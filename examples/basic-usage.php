<?php

/*
 * Using the client outside a Laravel application.
 *
 * ProofAge\Laravel\ProofAgeClient sends through Laravel's Http facade, which needs a booted
 * application. Outside one, use the framework-neutral SDK this package is built on: same
 * resource methods, same config keys, no framework dependencies. Inside Laravel, prefer the
 * facade or app(ProofAgeClient::class) — see laravel-usage.php.
 */

require_once __DIR__.'/../vendor/autoload.php';

use ProofAge\Sdk\Client;
use ProofAge\Sdk\Exceptions\AuthenticationException;
use ProofAge\Sdk\Exceptions\ProofAgeException;
use ProofAge\Sdk\Exceptions\TransportException;
use ProofAge\Sdk\Exceptions\ValidationException;

// Initialize the client
$client = new Client([
    'api_key' => 'your-api-key',
    'secret_key' => 'your-secret-key',
    'base_url' => 'https://api.proofage.net',
    'version' => 'v1',
]);

try {
    // Get workspace information
    echo "Getting workspace information...\n";
    $workspace = $client->workspace()->get();
    echo 'Workspace: '.$workspace['name'].' (Mode: '.$workspace['mode'].")\n\n";

    // Get the active consent version. Its text (at $consent['url']) is what the person must be
    // shown, and its id and text_sha256 are the only values acceptConsent() takes.
    echo "Getting consent information...\n";
    $consent = $client->workspace()->getConsent();
    echo 'Consent version: '.$consent['version']."\n\n";

    // Create a verification
    echo "Creating verification...\n";
    $verification = $client->verifications()->create([
        // Where the person's browser goes after finishing the flow — a page of your app, not a
        // webhook. Decisions are POSTed to the webhook URL set in the workspace settings of the
        // ProofAge console.
        'callback_url' => 'https://your-app.com/verification/done',
        // Your identifiers, echoed back in responses and webhooks. (`metadata` is stored but
        // never returned, so it cannot be used to find the user again.)
        'external_id' => 'user-123',
        'external_metadata' => ['session_id' => 'abc123'],
    ]);
    echo 'Created verification: '.$verification['id']."\n";
    // Most integrations stop here and send the person to the hosted flow:
    echo 'Hosted flow: '.$verification['url']."\n\n";

    $verificationId = $verification['id'];

    // The rest is for an integration that captures the images itself.

    // Accept consent for the verification, once the person has accepted the text
    echo "Accepting consent...\n";
    $consentResult = $client->verifications($verificationId)->acceptConsent([
        'consent_version_id' => $consent['id'],
        'text_sha256' => $consent['text_sha256'],
    ]);
    echo 'Consent accepted at: '.$consentResult['consent_accepted_at']."\n\n";

    // Upload a selfie and the front of a passport. Images only. Both calls return null — the
    // API answers 200 with an empty body — and throw on failure. A path that does not exist
    // throws \InvalidArgumentException before anything is sent.
    if (file_exists('/path/to/selfie.jpg')) {
        echo "Uploading selfie...\n";
        $client->verifications($verificationId)->uploadMedia([
            'type' => 'selfie',
            'file' => '/path/to/selfie.jpg',
        ]);
        echo "Selfie uploaded\n\n";
    }

    if (file_exists('/path/to/passport.jpg')) {
        echo "Uploading document...\n";
        $client->verifications($verificationId)->uploadMedia([
            'type' => 'document',
            'side' => 'front',                      // front | back
            'document' => 'passport',               // id | driver_license | passport | residence_permit
            'file' => '/path/to/passport.jpg',
        ]);
        echo "Document uploaded\n\n";
    }

    // Get verification status
    echo "Getting verification status...\n";
    $verificationStatus = $client->verifications($verificationId)->get();
    echo 'Verification status: '.$verificationStatus['status']."\n\n";

    // Submit verification for processing (null on success; the decision arrives by webhook)
    echo "Submitting verification...\n";
    $client->verifications($verificationId)->submit();
    echo "Verification submitted successfully\n";

} catch (AuthenticationException $e) {
    echo 'Authentication error: '.$e->getMessage()."\n";
    echo 'Error code: '.$e->getErrorCode()."\n";
} catch (ValidationException $e) {
    echo 'Validation error: '.$e->getMessage()."\n";
    echo 'Validation errors: '.json_encode($e->getErrors())."\n";
} catch (TransportException $e) {
    echo 'Could not reach the API: '.$e->getMessage()."\n";
} catch (ProofAgeException $e) {
    echo 'ProofAge API error: '.$e->getMessage()."\n";
    if ($e->getResponse()) {
        echo 'HTTP Status: '.$e->getResponse()->status()."\n";
    }
} catch (Exception $e) {
    echo 'General error: '.$e->getMessage()."\n";
}
