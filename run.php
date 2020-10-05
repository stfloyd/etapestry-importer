<?php

require_once 'HTTP/Request2.php';

require('lib/utils.php');
require('lib/nusoap.php');
require('lib/blackbaud.php');
require('lib/raisedonors.php');
require('lib/migrator.php');

// The maximum execution time, in seconds. If set to zero, no time limit is imposed.
set_time_limit(0);

// Make sure to keep alive the script when a client disconnect.
ignore_user_abort(true);

function validateEnvironment() {
    $outputDir = __DIR__.'/output/';
    $path = realpath($outputDir);

    if ($path === false AND !is_dir($path)) {
        mkdir($outputDir);
    }
}

function runImport() {
    // Sandbox will only work in the sandbox's API and not production database.
    $sandbox = false;

    // If import is enabled, the script will push the data generated into the eTapestry database.
    $importEnabled = true;

    $etapestryConfig = json_decode(
        file_get_contents(__DIR__.'/input/etapestry.config.json'), true
    );

    if ($sandbox) {
        $databaseId = $etapestryConfig['sandbox']['databaseId'];
        $apiKey = $etapestryConfig['sandbox']['apiKey'];
    } else {
        $databaseId = $etapestryConfig['production']['databaseId'];
        $apiKey = $etapestryConfig['production']['apiKey'];
    }

    // Start BB session
    $bbClient = new BlackbaudClient($databaseId, $apiKey);

    $rdOrgs = json_decode(
        file_get_contents(__DIR__.'/input/raisedonors.config.json'), true
    );
    
    print_r($rdOrgs);

    foreach ($rdOrgs as $key => $org) {
        // Start RD session
        $rdClient = new RaiseDonorsClient($org['key'], $org['license']);

        // Create our migrator
        $migrator = new Migrator($rdClient, $bbClient, $key, !$sandbox);

        $migrator->processLastWeekDonations();
        $migrator->writeOutput();

        if ($importEnabled) {
            $migrator->import();
        }
    }
}

validateEnvironment();
runImport();

?>