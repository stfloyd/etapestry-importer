<?php

require_once 'HTTP/Request2.php';

/*
Content-Type: application/json
X-OrganizationKey: <ORGANIZATION_KEY>
Authorization: Basic <API_LICENSE_KEY>
*/
class RaiseDonorsClient {
    public static string $baseUrl = 'https://api.raisedonors.com/v1/';

    private array $requestHeaders;

    public function __construct(string $orgKey, string $license) {
        $this->requestHeaders = [
            'Content' => 'application/json',
            'X-OrganizationKey' => $orgKey,
            'Authorization' => "BASIC {$license}"
        ];
    }

    protected function getRequest($url, $method=HTTP_Request2::METHOD_GET) {
        $request = new HTTP_Request2();
        $request->setUrl($url);
        $request->setMethod($method);
        $request->setConfig([
            'follow_redirects' => true
        ]);
        $request->setHeader($this->requestHeaders);
        return $request;
    }

    private function sendRequest($request) {
        $response = $request->send();

        if ($response->getStatus() == 429) {
            // Too many requests.
            $responseJson = json_decode($response->getBody(), true);
            // Check for familiar/known message.
            if ($responseJson["message"] == 'Rate limit exceeded quota.') {
                // We were rate limited, let's extract the # of seconds it says is remaining.
                $explosion = explode(" ", $responseJson['details']);
                $count = count($explosion);
                if ($explosion[$count - 1] == "second(s).") {
                    // I add five to be sure we are executing outside of the rate limit cap time.
                    $seconds = intval($explosion[$count - 2]) + 5;
                    echo "<@@@> (RD) => Rate limit reached. Sleeping for {$seconds} second(s).\n";
                    // Sleep for the time we got.
                    sleep($seconds);
                    // Recursion.
                    return $this->sendRequest($request);
                } else {
                    echo "<@@@> Rate limit details were not what was expected. Expected it in second(s): {$response->getStatus()} {$response->getReasonPhrase()}\n{$response->getBody()}\n";
                }
            } else {
                echo "<!!!> HTTP 429: Unknown response message, unsure how to handle: {$response->getStatus()} {$response->getReasonPhrase()}\n{$response->getBody()}\n";
            }
        } else if ($response->getStatus() != 200) {
            echo "<!!!> Unexpected HTTP status: {$response->getStatus()} {$response->getReasonPhrase()}\n{$response->getBody()}\n";
        }

        return $response;
    }

    public function getDonor($accountNumber) {
        $donorUrl = self::$baseUrl."donors?crmKeySecond=".$accountNumber;
        $request = $this->getRequest($donorUrl);
        $response = $this->sendRequest($request);

        if ($response->getStatus() != 200) {
            throw new Exception("<!!!> (RD) => Failed to get donor with account number: {$accountNumber} Details: {$response->getBody()}");
        }

        $responseJson = json_decode($response->getBody(), true);

        if (empty($responseJson)) {
            throw new RDDonorDoesNotExistException($accountNumber, $response->getBody());
        } else if (count($responseJson) > 1) {
            throw new RDMultipleDonorsSameKeyException($accountNumber, $responseJson, $response->getBody());
        }

        // We SHOULD only have 1, but we always return the first element.
        return $responseJson[0];
    }

    public function getDonations($donorId=null, $createdAfter=null, $createdBefore=null, $approvedOnly=true) {
        $donations = [];

        $currentPage = 1;
        $pageSize = 200;
        $moreDonations = true;

        while ($moreDonations) {
            // https://api.raisedonors.com/v1/donations?createdAfter=02/09/2020 00:00:00&includeTestTransactions=true&createdBefore=02/11/2020 00:00:00&giftAidRequested=
            if (isset($donorId)) {
                $donationsUrl = self::$baseUrl . "donors/{$donorId}/donations?page={$currentPage}&pageSize={$pageSize}";
            } else {
                $donationsUrl = self::$baseUrl . "donations?page={$currentPage}&pageSize={$pageSize}";
            }

            if (isset($createdAfter)) {
                $donationsUrl = $donationsUrl . "&createdAfter={$createdAfter}";
            }

            if (isset($createdBefore)) {
                $donationsUrl = $donationsUrl . "&createdBefore={$createdBefore}";
            }

            $request = $this->getRequest($donationsUrl);
            $response = $this->sendRequest($request);

            if ($response->getStatus() != 200) {
                throw new Exception("<!!!> (RD) => Failed to get donations for donor ID: {$donorId} Details: {$response->getBody()}");
            }

            $responseJson = json_decode($response->getBody(), true);
            $donations = [];

            if ($approvedOnly) {
                foreach ($responseJson as $donation) {
                    if ($donation['status']['id'] == 1) {
                        array_push($donations, $donation);
                    }
                }
            } else {
                $donations = array_merge($responseJson, $donations);
            }

            $pagination = json_decode($response->getHeader()['x-pagination'], true);
            if ($currentPage >= $pagination['totalPages']) {
                $moreDonations = false;
            } else {
                $currentPage++;
            }
        }

        return $donations;
    }
}

class RDDonorDoesNotExistException extends Exception {
    public $donorId = null;

    // Redefine the exception so message isn't optional
    public function __construct($donorId, $message, $code = 0, Exception $previous = null) {
        $this->donorId = $donorId;
        // make sure everything is assigned properly
        parent::__construct($message, $code, $previous);
    }

    public function __toString() {
        return __CLASS__ . ": [{$this->code}]: Donor with CRM key {$this->donorId} not found. Message: {$this->message}\n";
    }
}

class RDMultipleDonorsSameKeyException extends Exception {
    public $raw = [];
    public $crmKey = null;

    // Redefine the exception so message isn't optional
    public function __construct($crmKey, $raw, $message, $code = 0, Exception $previous = null) {
        $this->raw = $raw;
        $this->crmKey = $crmKey;
        // make sure everything is assigned properly
        parent::__construct($message, $code, $previous);
    }

    public function __toString() {
        return __CLASS__ . ": [{$this->code}]: Multiple donors found with same 'crmKeySecond'. Account number: {$this->crmKey}. Message: {$this->message}\n";
    }
}

?>