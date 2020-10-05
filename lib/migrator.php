<?php

// The maximum execution time, in seconds. If set to zero, no time limit is imposed.
set_time_limit(0);

class Migrator {
    private int $runNum = 0;

    private bool $running = false;

    private array $runs;

    private RaiseDonorsClient $rdClient;

    private BlackbaudClient $bbClient;

    private array $loadedData;

    private array $rdDonors;

    private array $bbDonors;

    private array $donations;

    private array $errors;

    private array $duplicateCrmKeyDonors;

    private array $unmatchedAccounts;

    public string $label;

    private bool $isProduction;

    public function __construct(RaiseDonorsClient $rdClient, BlackbaudClient $bbClient, string $label = null, bool $isProduction = false) {
        $outputDir = __DIR__."/../output/";
        if (!is_dir($outputDir)) {
            mkdir($outputDir);
        }
        
        $this->label = $label;
        $this->rdClient = $rdClient;
        $this->bbClient = $bbClient;
        $this->isProduction = $isProduction;
        $this->clearTransient();
        $this->loadRuns();
    }

    public function processCSV(string $csvFilePath) {
        $this->clearTransient();
        $this->startRun();
        $this->loadedData = loadCSV($csvFilePath);

        // Loop through each entry, the index at "Account Number" is the matching
        // ID in eTapestry and crmSecondKey in RaiseDonors.
        //
        // 1. fetch the RD donor based on the eTapestry ID (crmKeySecond)
        //      1a. fetch that donors' donations in RD (if any)
        //      1b. map the RD donor & their donations
        // 2. fetch the BB donor and get their DB ref key
        //      2a. use the DB ref key to get that donor's journal entries
        //      2b. map the BB donor & their donations
        for ($i = 0; $i < count($this->loadedData); $i++) {
            $rdAccountNumber = $this->loadedData[$i]['Account Number'];

            try {
                $rdDonor = $this->rdClient->getDonor($rdAccountNumber);
                $rdDonations = $this->rdClient->getDonations($rdDonor['id']);
                array_push($this->rdDonors, [
                    'label' => $this->label,
                    'donor' => $rdDonor,
                    'donations' => $rdDonations
                ]);

                // Now lets get the BB donor's DB ref key from eTapestry and fetch their journal entries/donations.
                if ($this->isProduction) {
                    $bbDonor = getAccountById($nsc, $rdDonor["crmSecondKey"]);
                } else {
                    $bbDonor = $this->bbClient->getAccountByUniqueDefinedValue('Live eTap Account Number', $rdDonor['crmSecondKey']);
                }

                if (!isset($bbDonor)) {
                    echo "(BB) => Had an issue getting an account. ID: {$rdDonor['crmSecondKey']}\n";
                    array_push($this->errors, $rdDonor['crmSecondKey']);
                    continue;
                }

                $eTapDonationFromRD = [
                    'label' => $this->label,
                    'bbDonorId' => $bbDonor['id'],
                    'bbDonorName' => "{$bbDonor['firstName']} {$bbDonor['lastName']}",
                    'rdDonorId' => $rdDonor['id'],
                    'rdDonorName' => "{$rdDonor['firstName']} {$rdDonor['lastName']}",
                    'donationsCount' => 0,
                    'donations' => []
                ];

                // Define where we will store all donations.
                $bbDonations = $this->bbClient->getDonations($bbDonor['ref']);

                // Let's map our BB donor and their donations.
                array_push($this->bbDonors, [
                    'label' => $this->label,
                    'donor' => $bbDonor,
                    'donations' => $bbDonations
                ]);

                for ($j = 0; $j < count($rdDonations); $j++) {
                    if ($this->bbClient->doesRDDonationExist($bbDonor['ref'], $rdDonations[$j]['id'])) {
                        echo "(BB) => Donation ID {$rdDonations[$j]['id']} already exists... Skipping.\n";
                        continue;
                    }

                    $gift = $this->buildGiftFromDonation($rdDonations[$j], $bbDonor['ref']);

                    if (empty($gift)) {
                        continue;
                    }

                    array_push($eTapDonationFromRD['donations'], $gift);
                    echo "(RD) => Donation ID {$rdDonations[$j]['id']} does not exist in eTapestry.\n";
                }

                $eTapDonationFromRD['donationsCount'] = count($eTapDonationFromRD['donations']);
                array_push($this->donations, $eTapDonationFromRD);
            } catch(HTTP_Request2_Exception $e) {
                echo "<!!!> Fatal HTTP Request Exception: {$e->getMessage()}";
            } catch(RDDonorDoesNotExistException $e) {
                echo $e;
                array_push($this->errors, "RD: {$e}");
                continue;
            } catch(RDMultipleDonorsSameKeyException $e) {
                echo $e;
                array_push($this->duplicateCrmKeyDonors, [
                    'label' => $this->label,
                    'crmKey' => $e->crmKey,
                    'donors' => $e->raw
                ]);
                continue;
            }
        }
    }

    private function _donationMinifier($donation) {
        return [
            'rdDonationId' => $donation['id'],
            'amount' => centsToDollars($donation['amountInCents']),
            'notes' => formatCreditCardNotesFromRaiseDonor($donation['id'], $donation['last4'], $donation['comment'])
        ];
    }

    public function processLastWeekDonations() {
        $this->clearTransient();
        $this->startRun();
        $today = date('m/d/Y') . ' 00:00:00';
        $weekAgo = date('m/d/Y', strtotime('-1 week')) . ' 00:00:00';
        $rdDonations = $this->rdClient->getDonations(null, $weekAgo, $today, true);

        foreach ($rdDonations as $donation) {
            $bbDonorKey = $donation['donor']['crmSecondKey'];

            if (!isset($bbDonorKey)) {
                // This donor does not have a matching account in eTapestry.
                $unmatchedDonor = $donation['donor'];
                $alreadyExists = false;
                foreach ($this->unmatchedAccounts as $i => $donor) {
                    if ($donor['id'] == $unmatchedDonor['id']) {
                        // Add donation to that users donations
                        $alreadyExists = true;
                        array_push($this->unmatchedAccounts[$i]['donations'], $this->_donationMinifier($donation));
                    }
                }

                if (!$alreadyExists) {
                    echo "(RD) => Found an unmatched account ID: {$bbDonorKey}\n";
                    $unmatchedDonor['donations'] = [];
                    array_push($unmatchedDonor['donations'], $this->_donationMinifier($donation));
                    array_push($this->unmatchedAccounts, $unmatchedDonor);
                }

                continue;
            }

            if ($this->isProduction) {
                $bbDonor = $this->bbClient->getAccountById($bbDonorKey);
            } else {
                $bbDonor = $this->bbClient->getAccountByUniqueDefinedValue('Live eTap Account Number', $bbDonorKey);
            }

            if (!isset($bbDonor)) {
                echo "(BB) => Had an issue getting an account. ID: {$bbDonorKey}\n";
                array_push($this->errors, "Could not get Blackbaud account ID (crmSecondKey): {$bbDonorKey}");
                continue;
            } else {
                array_push($this->bbDonors, $bbDonor);
            }

            if ($this->bbClient->doesRDDonationExist($bbDonor['ref'], $donation['id'])) {
                echo "(BB) => Donation ID {$donation['id']} already exists... Skipping.\n";
                continue;
            }

            $gift = $this->buildGiftFromDonation($donation, $bbDonor['ref']);

            if (empty($gift)) continue;

            array_push($this->donations, [
                'label' => $this->label,
                'bbDonorId' => $bbDonor['id'],
                'bbDonorName' => "{$bbDonor['firstName']} {$bbDonor['lastName']}",
                'rdDonorId' => $donation['donor']['id'],
                'rdDonationId' => $donation['id'],
                'rdDonationStatus' => $donation['status'],
                'donation' => $gift
            ]);

            echo "(RD) => Donation ID {$donation['id']} does not exist in eTapestry.\n";
        }
    }

    public function writeOutput() {
        echo "Writing RD->BB donations to file...";
        file_put_contents(__DIR__."/../output/{$this->runNum}/donations.json", json_encode($this->donations));
        echo "Done.\n";

        echo "Writing RD->BB donations (errors) to file...";
        file_put_contents(__DIR__."/../output/{$this->runNum}/errors.json", json_encode($this->errors));
        echo "Done.\n";

        echo "Writing RD->BB donors with same CRM keys...";
        file_put_contents(__DIR__."/../output/{$this->runNum}/duplicate_crmkeys.json", json_encode($this->duplicateCrmKeyDonors));
        echo "Done.\n";

        echo "Writing RD->BB unmatched accounts...";
        file_put_contents(__DIR__."/../output/{$this->runNum}/unmatched_accounts.json", json_encode($this->unmatchedAccounts));
        echo "Done.\n";

        echo "Writing RD Donors to file...";
        file_put_contents(__DIR__."/../output/{$this->runNum}/scan_rd.json", json_encode($this->rdDonors));
        echo "Done.\n";

        echo "Writing BB Donors to file...";
        file_put_contents(__DIR__."/../output/{$this->runNum}/scan_bb.json", json_encode($this->bbDonors));
        echo "Done.\n";

        echo "Writing summary to file...";
        $today = new DateTime();
        file_put_contents(__DIR__."/../output/{$this->runNum}/summary.json", json_encode([
            'date' => $today->format("Y-m-d H:i:s"),
            'label' => $this->label,
            'importedDonations' => count($this->donations),
            'unmatchedAccounts' => count($this->unmatchedAccounts),
            'duplicateCrmKeyDonors' => count($this->duplicateCrmKeyDonors),
            'errors' => count($this->errors)
        ]));
        echo "Done.\n";
    }

    public function import() {
        $this->loadData($this->runNum);
        echo "Beginning RD->BB migration...\n";
        if (!empty($this->donations)) {
            foreach ($this->donations as $rdToEtapData) {
                echo "Migrating donations for {$rdToEtapData['bbDonorName']}; RD ID: {$rdToEtapData['rdDonorId']}, BB ID: {$rdToEtapData['bbDonorId']}\n";
                if (array_key_exists('donations', $rdToEtapData)) {
                    foreach ($rdToEtapData['donations'] as $gift) {
                        $this->bbClient->addGift($gift);
                    }
                } else {
                    $this->bbClient->addGift($rdToEtapData['donation']);
                }
            }
        } else {
            echo "Nothing to migrate.\n";
        }
        echo "Migration finished.\n";
        $this->finishRun();
    }

    private function loadData($runNum) {
        $this->clearTransient();
        $this->donations = json_decode(file_get_contents(__DIR__."/../output/{$runNum}/donations.json"), true);
        $this->duplicateCrmKeyDonors = json_decode(file_get_contents(__DIR__."/../output/{$runNum}/duplicate_crmkeys.json"), true);
        $this->unmatchedAccounts = json_decode(file_get_contents(__DIR__."/../output/{$runNum}/unmatched_accounts.json"), true);
        $this->rdDonors = json_decode(file_get_contents(__DIR__."/../output/{$runNum}/scan_rd.json"), true);
        $this->bbDonors = json_decode(file_get_contents(__DIR__."/../output/{$runNum}/scan_bb.json"), true);
    }

    private function clearTransient() {
        $this->rdDonors = [];
        $this->bbDonors = [];
        $this->donations = [];
        $this->errors = [];
        $this->duplicateCrmKeyDonors = [];
        $this->loadedData = [];
        $this->unmatchedAccounts = [];
    }

    private function buildGiftFromDonation($donation, $bbDonorRef=null) {
        // All donations in eTapestry in this case will be created as a 'Cash' type donation
        // journal entry.
        // https://www.blackbaudhq.com/files/etapestry/api3/objects/Gift.html
        $dateCreated = new DateTime($donation['dateCreated']);
        $rdFund = $donation['allocations'][0]['fund']['name'];
        $gift = [
            'accountRef' => $bbDonorRef,
            'date' => formatDateAsDateTimeString($dateCreated->format('m/d/Y')),
            'amount' => centsToDollars($donation['amountInCents']),
            'fund' => substr($rdFund, 0, strpos($rdFund, ' (')),
            'approach' => 'Website',
            'note' => '',
            // https://www.blackbaudhq.com/files/etapestry/api3/objects/Valuable.html
            'valuable' => [
                'type' => 1,
                'cash' => [
                    'note' => ''
                ]
            ]
        ];

        // RD 1 = CC
        // RD 2 = Check
        if ($donation['paymentTenderType']['id'] == 1) {
            $gift['note'] = formatCreditCardNotesFromRaiseDonor($donation['id'], $donation['last4'], $donation['comment']);
        } else {
            echo "(RD) => Unknown tender type for donation ID: {$donation['id']}";
            return [];
        }

        return $gift;
    }

    private function loadRuns() {
        $this->runs = json_decode(file_get_contents(__DIR__.'/../output/runs.json'), true);

        foreach ($this->runs as $key => $run) {
            if ($run['status'] != 'finished') {
                $this->runs[$key]['status'] = 'failed';
            }
        }

        $this->saveRuns();
    }

    private function saveRuns() {
        file_put_contents(__DIR__.'/../output/runs.json', json_encode($this->runs));
    }

    private function getRunNumber() {
        $previousRun = $this->getLastRun();
        if (isset($previousRun)) return $previousRun['num'] + 1;
        else return 0;
    }

    private function getLastRun() {
        if (empty($this->runs)) {
            echo "No runs found.\n";
            return null;
        }

        return end($this->runs);
    }

    private function startRun() {
        if ($this->running) {
            throw new Exception('Tried to start a run while one is already running.');
        }

        $this->runNum = $this->getRunNumber();
        echo "Starting run #{$this->runNum}...\n";
        array_push($this->runs, [
            'label' => $this->label,
            'num' => $this->runNum,
            'status' => 'started'
        ]);
        file_put_contents(__DIR__.'/../output/runs.json', json_encode($this->runs));
        mkdir(__DIR__."/../output/{$this->runNum}");
        $this->running = true;
    }

    private function finishRun() {
        $this->runs[count($this->runs) - 1]['status'] = 'finished';
        $this->saveRuns();
        $this->running = false;
        echo "Run #{$this->runNum} finished.\n";
    }
}

?>