<?php

/**
 * Take a United States formatted date (mm/dd/yyyy) and
 * convert it into a date/time string that NuSoap requires.
 */
function formatDateAsDateTimeString($dateStr) {
    if ($dateStr == null || $dateStr == "") return "";
    if (substr_count($dateStr, "/") != 2) return "[Invalid Date: $dateStr]";

    $separator1 = stripos($dateStr, "/");
    $separator2 = stripos($dateStr, "/", $separator1 + 1);

    $month = substr($dateStr, 0, $separator1);
    $day = substr($dateStr, $separator1 + 1, $separator2 - $separator1 - 1);
    $year = substr($dateStr, $separator2 + 1);

    return ($month > 0 && $day > 0 && $year > 0) ? date(DATE_ATOM, mktime(0, 0, 0, $month, $day, $year)) : "[Invalid Date: $dateStr]";
}

function loadCSV($file) {
    // Create an array to hold the data
    $arrData = array();

    // Create a variable to hold the header information
    $header = NULL;

    // If the file can be opened as readable, bind a named resource
    if (($handle = fopen($file, 'r')) !== FALSE) {
        // Loop through each row
        while (($row = fgetcsv($handle)) !== FALSE) {
            // Loop through each field
            foreach($row as &$field) {
                // Remove any invalid or hidden characters
                $field = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $field);
            }

            // If the header has been stored
            if ($header) {
                // Create an associative array with the data
                $arrData[] = array_combine($header, $row);
            } else {
                // Store the current row as the header
                $header = $row;
            }
        }

        // Close the file pointer
        fclose($handle);
    }

    return $arrData;
}

function centsToDollars($cents) {
    return number_format(($cents / 100.0), 2, '.', '');
}

?>