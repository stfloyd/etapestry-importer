<?php

class BlackbaudClient {
    private nusoap_client $nsc;

    public function __construct($databaseId, $apiKey) {
        // Set login details. This info is visible to admin users within eTapestry.
        // Navigate to Management -> My Organization -> Subscriptions and look under
        // the API Subscription section.

        // Set initial endpoint
        $endpoint = 'https://sna.etapestry.com/v3messaging/service?WSDL';

        // Instantiate nusoap_client
        echo '(BB) => Establishing NuSoap Client...';
        $this->nsc = new nusoap_client($endpoint, true);
        echo "Done.\n";

        // Did an error occur?
        $this->checkStatus();

        // Invoke login method
        echo '(BB) => Calling login method...';
        $newEndpoint = $this->nsc->call('apiKeyLogin', array($databaseId, $apiKey));
        echo "Done.\n";

        // Did a soap fault occur?
        $this->checkStatus();

        // Determine if the login method returned a value...this will occur
        // when the database you are trying to access is located at a different
        // environment that can only be accessed using the provided endpoint
        if ($newEndpoint != "")
        {
            echo "(BB) => New Endpoint: $newEndpoint\n";

            // Instantiate nusoap_client with different endpoint
            echo '(BB) => Establishing NuSoap Client with new endpoint...';
            $this->nsc = new nusoap_client($newEndpoint, true);
            echo "Done.\n";

            // Did an error occur?
            $this->checkStatus();

            // Invoke login method
            echo '(BB) => Calling login method...';
            $this->nsc->call('apiKeyLogin', array($databaseId, $apiKey));
            echo "Done.\n";

            // Did a soap fault occur?
            $this->checkStatus();
        }

        // Output results
        echo "(BB) => Login Successful\n";
    }

    public function __destruct()
    {
        $this->logout();
    }

    /**
     * Start an eTapestry API session by calling the logout
     * method given a nusoap_client instance.
     */
    private function logout()
    {
        // Invoke logout method
        echo '(BB) => Calling logout method...';
        $this->nsc->call('logout');
        echo "Done.\n";
    }

    /**
     * Utility method to determine if a NuSoap fault or error occurred.
     * If so, output any relevant info and stop the code from executing.
     */
    private function checkStatus()
    {
        if ($this->nsc->fault || $this->nsc->getError())
        {
            if (!$this->nsc->fault)
            {
                echo 'Error: '.$this->nsc->getError();
            }
            else
            {
                echo 'Fault Code: '.$this->nsc->faultcode."\n";
                echo 'Fault String: '.$this->nsc->faultstring."\n";
            }
            exit;
        }
    }

    public function getAccount($dbRef)
    {
        echo '(BB) => Calling getAccount method...';
        $response = $this->nsc->call('getAccount', array($dbRef));
        echo "Done.\n";

        // Did an error occur?
        $this->checkStatus();

        return $response;
    }

    function getJournalEntries($start, $count, $accountRef) {
        $journalParams = array();
        $journalParams['start'] = $start;
        $journalParams['count'] = $count; // Max is 100
        $journalParams['accountRef'] = $accountRef; // example: 1234.0.567812'

        echo '(BB) => Calling getJournalEntries method...';
        $response = $this->nsc->call('getJournalEntries', array($journalParams));
        echo "Done.\n";

        // Did an error occur?
        $this->checkStatus();

        return $response;
    }

    public function getDonations($accountRef) {
        $moreJournalEntries = true;
        $journalParamsStart = 0;
        $journalParamsCount = 100;
        $donations = [];

        while ($moreJournalEntries) {
            $bbJournalEntries = $this->getJournalEntries($journalParamsStart, $journalParamsCount, $accountRef);
            $donations = array_merge($bbJournalEntries['data'], $donations);

            if ($bbJournalEntries['count'] < $journalParamsCount) {
                // If we had less than the page size, we are done.
                $moreJournalEntries = false;
            } else {
                // Move up $journalParamsCount elements and continue loop.
                $journalParamsStart += $journalParamsCount;
            }
        }

        return $donations;
    }

    public function doesRDDonationExist($accountRef, $rdDonationId) {
        $donations = $this->getDonations($accountRef);
        $exists = false;

        foreach ($donations as $donation) {
            try {
                $exists = hasDonationId($donation['note'], $rdDonationId);
                if ($exists) break;

            } catch (Exception $e) {
                continue;
            }
        }

        return $exists;
    }

    public function addGift($gift) {
        echo '(BB) => Calling addGift method...';
        $response = $this->nsc->call('addGift', array($gift, false));
        echo "Done.\n";

        // Did an error occur?
        $this->checkStatus();

        return $response;
    }

    function getAccountByUniqueDefinedValue($fieldName, $value)
    {
        $dv = array();
        $dv['fieldName'] = $fieldName;
        $dv['value'] = $value;

        // Invoke getAccountByUniqueDefinedValue method
        echo '(BB) => Calling getAccountByUniqueDefinedValue method...';
        $response = $this->nsc->call('getAccountByUniqueDefinedValue', array($dv));
        echo "Done.\n";

        // Did an error occur?
        $this->checkStatus();

        return $response;
    }

    function getAccountById($id)
    {
        // Invoke getAccountById method
        echo "(BB) => Calling getAccountById method... ID: {$id}...";
        $response = $this->nsc->call('getAccountById', array($id));
        echo "Done.\n";

        // Did an error occur?
        $this->checkStatus();

        return $response;
    }
}

// $text = "Processed by RaiseDonors using credit card ending in x1922. RaiseDonor Donation ID:219252. Comment: Donation for Godparent Home General I want to help young girls in need to make a decision for Life. I started to receive a social security check and wanted to give a portion of it monthly to defeat the culture of death that the world is promoting through.";
// $donationId = parseRaiseDonorsIdFromNotes($text);
// echo $donationId;
function parseRaiseDonorsIdFromNotes($text)
{
    $matches = null;
    preg_match_all("/Donation ID\:([0-9]+)/", $text, $matches);
    if (count($matches[1]) == 0)
    {
        preg_match_all("/Donation ID: ([0-9]+)/", $text, $matches);
        if (count($matches[1]) == 0) {
            return null;
        }
    }
    return $matches[1][0];
}

function hasDonationId($text, $donationId)
{
    try {
        $textDonationId = parseRaiseDonorsIdFromNotes($text);
        return $textDonationId == $donationId;
    } catch (Exception $e) {
        return false;
    }
}

// $text = "Processed by RaiseDonors using credit card ending in x1922. RaiseDonor Donation ID:219252. Comment: Donation for Godparent Home General I want to help young girls in need to make a decision for Life. I started to receive a social security check and wanted to give a portion of it monthly to defeat the culture of death that the world is promoting through.";
// $last4String = parseRaiseDonorsCardLast4FromNotes($text);
// echo $last4String;
function parseRaiseDonorsCardLast4FromNotes($text)
{
    $matches = null;
    preg_match_all("/card ending in x([0-9]+)/", $text, $matches);
    return $matches[1][0];
}

function formatCreditCardNotesFromRaiseDonor($donationId, $last4, $comment)
{
    $value = "Processed by RaiseDonors using credit card ending in x{last4} RaiseDonor Donation ID: {$donationId}.";
    if (!empty($comment))
    {
        $value = $value. " Comment: {$comment}";
    }
    return $value;
}

?>