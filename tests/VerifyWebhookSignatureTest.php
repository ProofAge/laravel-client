<?php

namespace ProofAge\Laravel\Tests;

use Illuminate\Http\Request;
use ProofAge\Laravel\Middleware\VerifyWebhookSignature;

class VerifyWebhookSignatureTest extends TestCase
{
    protected VerifyWebhookSignature $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new VerifyWebhookSignature;
    }

    public function test_missing_secret_key_returns_error(): void
    {
        config(['proofage.secret_key' => null]);

        $timestamp = now()->timestamp;
        $request = Request::create('/webhook', 'POST', [], [], [], [], '{"test": "data"}');
        $request->headers->set('X-HMAC-Signature', 'some-signature');
        $request->headers->set('X-Timestamp', $timestamp);
        $request->headers->set('X-Auth-Client', 'test-client');

        $response = $this->middleware->handle($request, function () {
            return response()->json(['success' => true]);
        });

        $this->assertEquals(418, $response->getStatusCode()); // I_AM_A_TEAPOT for config error
        $responseData = json_decode($response->getContent(), true);
        $this->assertEquals('CONFIGURATION_ERROR', $responseData['error']['code']);
    }

    public function test_missing_signature_header_returns_error(): void
    {
        config(['proofage.secret_key' => 'test-secret', 'proofage.api_key' => 'test-client']);

        $timestamp = now()->timestamp;
        $request = Request::create('/webhook', 'POST', [], [], [], [], '{"test": "data"}');
        $request->headers->set('X-Timestamp', $timestamp);
        $request->headers->set('X-Auth-Client', 'test-client');

        $response = $this->middleware->handle($request, function () {
            return response()->json(['success' => true]);
        });

        $this->assertEquals(401, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertEquals('MISSING_SIGNATURE', $responseData['error']['code']);
    }

    public function test_missing_timestamp_header_returns_error(): void
    {
        config(['proofage.secret_key' => 'test-secret', 'proofage.api_key' => 'test-client']);

        $request = Request::create('/webhook', 'POST', [], [], [], [], '{"test": "data"}');
        $request->headers->set('X-HMAC-Signature', 'some-signature');
        $request->headers->set('X-Auth-Client', 'test-client');

        $response = $this->middleware->handle($request, function () {
            return response()->json(['success' => true]);
        });

        $this->assertEquals(401, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertEquals('MISSING_TIMESTAMP', $responseData['error']['code']);
    }

    public function test_missing_auth_client_header_returns_error(): void
    {
        config(['proofage.secret_key' => 'test-secret', 'proofage.api_key' => 'test-client']);

        $timestamp = now()->timestamp;
        $request = Request::create('/webhook', 'POST', [], [], [], [], '{"test": "data"}');
        $request->headers->set('X-HMAC-Signature', 'some-signature');
        $request->headers->set('X-Timestamp', $timestamp);

        $response = $this->middleware->handle($request, function () {
            return response()->json(['success' => true]);
        });

        $this->assertEquals(401, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertEquals('MISSING_AUTH_CLIENT', $responseData['error']['code']);
    }

    public function test_timestamp_too_old_returns_error(): void
    {
        config(['proofage.secret_key' => 'test-secret', 'proofage.api_key' => 'test-client']);

        // Timestamp from 10 minutes ago
        $timestamp = now()->timestamp - 600;
        $request = Request::create('/webhook', 'POST', [], [], [], [], '{"test": "data"}');
        $request->headers->set('X-HMAC-Signature', 'some-signature');
        $request->headers->set('X-Timestamp', $timestamp);
        $request->headers->set('X-Auth-Client', 'test-client');

        $response = $this->middleware->handle($request, function () {
            return response()->json(['success' => true]);
        });

        $this->assertEquals(401, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertEquals('TIMESTAMP_TOO_OLD', $responseData['error']['code']);
    }

    public function test_invalid_auth_client_returns_error(): void
    {
        config(['proofage.secret_key' => 'test-secret', 'proofage.api_key' => 'test-client']);

        $timestamp = now()->timestamp;
        $request = Request::create('/webhook', 'POST', [], [], [], [], '{"test": "data"}');
        $request->headers->set('X-HMAC-Signature', 'some-signature');
        $request->headers->set('X-Timestamp', $timestamp);
        $request->headers->set('X-Auth-Client', 'wrong-client');

        $response = $this->middleware->handle($request, function () {
            return response()->json(['success' => true]);
        });

        $this->assertEquals(401, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertEquals('INVALID_AUTH_CLIENT', $responseData['error']['code']);
    }

    public function test_invalid_signature_returns_error(): void
    {
        config(['proofage.secret_key' => 'test-secret', 'proofage.api_key' => 'test-client']);

        $timestamp = now()->timestamp;
        $request = Request::create('/webhook', 'POST', [], [], [], [], '{"test": "data"}');
        $request->headers->set('X-HMAC-Signature', 'invalid-signature');
        $request->headers->set('X-Timestamp', $timestamp);
        $request->headers->set('X-Auth-Client', 'test-client');

        $response = $this->middleware->handle($request, function () {
            return response()->json(['success' => true]);
        });

        $this->assertEquals(401, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertEquals('INVALID_SIGNATURE', $responseData['error']['code']);
    }

    public function test_valid_signature_allows_request(): void
    {
        $secret = 'test-secret';
        config(['proofage.secret_key' => $secret, 'proofage.api_key' => 'test-client']);

        $timestamp = now()->timestamp;
        $body = '{"test": "data"}';
        $request = Request::create('/webhook', 'POST', [], [], [], [], $body);
        $request->headers->set('X-Timestamp', $timestamp);
        $request->headers->set('X-Auth-Client', 'test-client');

        // Generate valid signature: "$timestamp.$rawBody"
        $signaturePayload = $timestamp.'.'.$body;
        $validSignature = hash_hmac('sha256', $signaturePayload, $secret);
        $request->headers->set('X-HMAC-Signature', $validSignature);

        $response = $this->middleware->handle($request, function () {
            return response()->json(['success' => true]);
        });

        $this->assertEquals(200, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertTrue($responseData['success']);
    }

    public function test_empty_body_request(): void
    {
        $secret = 'test-secret';
        config(['proofage.secret_key' => $secret, 'proofage.api_key' => 'test-client']);

        $timestamp = now()->timestamp;
        $body = '';
        $request = Request::create('/webhook', 'POST', [], [], [], [], $body);
        $request->headers->set('X-Timestamp', $timestamp);
        $request->headers->set('X-Auth-Client', 'test-client');

        // Generate valid signature for empty body
        $signaturePayload = $timestamp.'.'.$body;
        $validSignature = hash_hmac('sha256', $signaturePayload, $secret);
        $request->headers->set('X-HMAC-Signature', $validSignature);

        $response = $this->middleware->handle($request, function () {
            return response()->json(['success' => true]);
        });

        $this->assertEquals(200, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertTrue($responseData['success']);
    }
}
