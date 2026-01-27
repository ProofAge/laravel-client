<?php

require_once __DIR__.'/../vendor/autoload.php';

use ProofAge\Laravel\Exceptions\AuthenticationException;
use ProofAge\Laravel\Exceptions\ProofAgeException;
use ProofAge\Laravel\Exceptions\ValidationException;
use ProofAge\Laravel\ProofAgeClient;

// Initialize the client
$client = new ProofAgeClient([
    'api_key' => 'your-api-key',
    'secret_key' => 'your-secret-key',
    'base_url' => 'https://api.proofage.xyz',
    'version' => 'v1',
]);

try {
    // Get workspace information
    echo "Getting workspace information...\n";
    $workspace = $client->workspace()->get();
    echo 'Workspace: '.$workspace['name'].' (Mode: '.$workspace['mode'].")\n\n";

    // Get consent information
    echo "Getting consent information...\n";
    $consent = $client->workspace()->getConsent();
    echo 'Consent version: '.$consent['version']."\n\n";

    // Create a verification
    echo "Creating verification...\n";
    $verification = $client->verifications()->create([
        'callback_url' => 'https://your-app.com/webhook',
        'metadata' => [
            'user_id' => 123,
            'session_id' => 'abc123',
        ],
    ]);
    echo 'Created verification: '.$verification['id']."\n\n";

    $verificationId = $verification['id'];

    // Accept consent for the verification
    echo "Accepting consent...\n";
    $consentResult = $client->verifications($verificationId)->acceptConsent([
        'consent_version_id' => $consent['id'],
        'text_sha256' => hash('sha256', 'consent text here'),
    ]);
    echo 'Consent accepted at: '.$consentResult['consent_accepted_at']."\n\n";

    // Upload a selfie (example with file path)
    if (file_exists('/path/to/selfie.jpg')) {
        echo "Uploading selfie...\n";
        $mediaResult = $client->verifications($verificationId)->uploadMedia([
            'type' => 'selfie',
            'file' => '/path/to/selfie.jpg',
        ]);
        echo 'Media uploaded: '.$mediaResult['id']."\n\n";
    }

    // Get verification status
    echo "Getting verification status...\n";
    $verificationStatus = $client->verifications($verificationId)->get();
    echo 'Verification status: '.$verificationStatus['status']."\n\n";

    // Submit verification for processing
    echo "Submitting verification...\n";
    $submitResult = $client->verifications($verificationId)->submit();
    echo "Verification submitted successfully\n";

} catch (AuthenticationException $e) {
    echo 'Authentication error: '.$e->getMessage()."\n";
    echo 'Error code: '.$e->getErrorCode()."\n";
} catch (ValidationException $e) {
    echo 'Validation error: '.$e->getMessage()."\n";
    echo 'Validation errors: '.json_encode($e->getErrors())."\n";
} catch (ProofAgeException $e) {
    echo 'ProofAge API error: '.$e->getMessage()."\n";
    if ($e->getResponse()) {
        echo 'HTTP Status: '.$e->getResponse()->status()."\n";
    }
} catch (Exception $e) {
    echo 'General error: '.$e->getMessage()."\n";
}
