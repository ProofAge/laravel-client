<?php

namespace ProofAge\Laravel\Tests;

use Illuminate\Http\Request;
use ProofAge\Laravel\Exceptions\WebhookVerificationException;
use ProofAge\Laravel\Middleware\VerifyWebhookSignature;

class VerifyWebhookSignatureTest extends TestCase
{
    protected VerifyWebhookSignature $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new VerifyWebhookSignature;
    }

    public function test_missing_signature_header_throws_exception(): void
    {
        $request = $this->makeRequest(body: '{"test": "data"}');
        $request->headers->set('X-Timestamp', (string) now()->timestamp);
        $request->headers->set('X-Auth-Client', 'test-api-key');

        $this->expectExceptionObject(
            new WebhookVerificationException('MISSING_SIGNATURE', 'X-HMAC-Signature header is required')
        );

        $this->middleware->handle($request, $this->passthrough());
    }

    public function test_missing_timestamp_header_throws_exception(): void
    {
        $request = $this->makeRequest(body: '{"test": "data"}');
        $request->headers->set('X-HMAC-Signature', 'some-signature');
        $request->headers->set('X-Auth-Client', 'test-api-key');

        $this->expectExceptionObject(
            new WebhookVerificationException('MISSING_TIMESTAMP', 'X-Timestamp header is required')
        );

        $this->middleware->handle($request, $this->passthrough());
    }

    public function test_missing_auth_client_header_throws_exception(): void
    {
        $request = $this->makeRequest(body: '{"test": "data"}');
        $request->headers->set('X-HMAC-Signature', 'some-signature');
        $request->headers->set('X-Timestamp', (string) now()->timestamp);

        $this->expectExceptionObject(
            new WebhookVerificationException('MISSING_AUTH_CLIENT', 'X-Auth-Client header is required')
        );

        $this->middleware->handle($request, $this->passthrough());
    }

    public function test_missing_config_throws_configuration_error(): void
    {
        config(['proofage.secret_key' => null]);

        $request = $this->makeRequest(body: '{"test": "data"}');
        $request->headers->set('X-HMAC-Signature', 'some-signature');
        $request->headers->set('X-Timestamp', (string) now()->timestamp);
        $request->headers->set('X-Auth-Client', 'test-client');

        try {
            $this->middleware->handle($request, $this->passthrough());
            $this->fail('Expected WebhookVerificationException');
        } catch (WebhookVerificationException $e) {
            $this->assertSame('CONFIGURATION_ERROR', $e->errorCode);
            $this->assertSame(418, $e->statusCode);
        }
    }

    public function test_invalid_auth_client_throws_exception(): void
    {
        $request = $this->makeRequest(body: '{"test": "data"}');
        $request->headers->set('X-HMAC-Signature', 'some-signature');
        $request->headers->set('X-Timestamp', (string) now()->timestamp);
        $request->headers->set('X-Auth-Client', 'wrong-client');

        $this->expectExceptionObject(
            new WebhookVerificationException('INVALID_AUTH_CLIENT', 'X-Auth-Client header is invalid')
        );

        $this->middleware->handle($request, $this->passthrough());
    }

    public function test_timestamp_too_old_throws_exception(): void
    {
        $timestamp = now()->timestamp - 600;
        $request = $this->makeRequest(body: '{"test": "data"}');
        $request->headers->set('X-HMAC-Signature', 'some-signature');
        $request->headers->set('X-Timestamp', (string) $timestamp);
        $request->headers->set('X-Auth-Client', 'test-api-key');

        $this->expectExceptionObject(
            new WebhookVerificationException('TIMESTAMP_TOO_OLD', 'Timestamp is too old')
        );

        $this->middleware->handle($request, $this->passthrough());
    }

    public function test_invalid_signature_throws_exception(): void
    {
        $request = $this->makeRequest(body: '{"test": "data"}');
        $request->headers->set('X-HMAC-Signature', 'invalid-signature');
        $request->headers->set('X-Timestamp', (string) now()->timestamp);
        $request->headers->set('X-Auth-Client', 'test-api-key');

        $this->expectExceptionObject(
            new WebhookVerificationException('INVALID_SIGNATURE', 'HMAC signature is invalid')
        );

        $this->middleware->handle($request, $this->passthrough());
    }

    public function test_valid_signature_allows_request(): void
    {
        $secret = 'test-secret-key';
        $timestamp = now()->timestamp;
        $body = '{"test": "data"}';
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        $request = $this->makeRequest(body: $body);
        $request->headers->set('X-HMAC-Signature', $signature);
        $request->headers->set('X-Timestamp', (string) $timestamp);
        $request->headers->set('X-Auth-Client', 'test-api-key');

        $response = $this->middleware->handle($request, $this->passthrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_valid_signature_with_empty_body(): void
    {
        $secret = 'test-secret-key';
        $timestamp = now()->timestamp;
        $body = '';
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        $request = $this->makeRequest(body: $body);
        $request->headers->set('X-HMAC-Signature', $signature);
        $request->headers->set('X-Timestamp', (string) $timestamp);
        $request->headers->set('X-Auth-Client', 'test-api-key');

        $response = $this->middleware->handle($request, $this->passthrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_config_prefix_reads_custom_config(): void
    {
        $sellerSecret = 'seller-secret-key';
        $sellerApiKey = 'seller-api-key';

        config([
            'services.proofage_seller.api_key' => $sellerApiKey,
            'services.proofage_seller.secret_key' => $sellerSecret,
        ]);

        $timestamp = now()->timestamp;
        $body = '{"verification_id": "ver_123"}';
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $sellerSecret);

        $request = $this->makeRequest(body: $body);
        $request->headers->set('X-HMAC-Signature', $signature);
        $request->headers->set('X-Timestamp', (string) $timestamp);
        $request->headers->set('X-Auth-Client', $sellerApiKey);

        $response = $this->middleware->handle(
            $request,
            $this->passthrough(),
            'services.proofage_seller'
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_config_prefix_rejects_default_creds_on_custom_prefix(): void
    {
        $sellerSecret = 'seller-secret-key';
        $sellerApiKey = 'seller-api-key';

        config([
            'services.proofage_seller.api_key' => $sellerApiKey,
            'services.proofage_seller.secret_key' => $sellerSecret,
        ]);

        $timestamp = now()->timestamp;
        $body = '{"test": true}';
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, 'test-secret-key');

        $request = $this->makeRequest(body: $body);
        $request->headers->set('X-HMAC-Signature', $signature);
        $request->headers->set('X-Timestamp', (string) $timestamp);
        $request->headers->set('X-Auth-Client', 'test-api-key');

        $this->expectExceptionObject(
            new WebhookVerificationException('INVALID_AUTH_CLIENT', 'X-Auth-Client header is invalid')
        );

        $this->middleware->handle($request, $this->passthrough(), 'services.proofage_seller');
    }

    public function test_custom_webhook_tolerance(): void
    {
        config(['proofage.webhook_tolerance' => 60]);

        $timestamp = now()->timestamp - 90;
        $body = '{"test": true}';
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, 'test-secret-key');

        $request = $this->makeRequest(body: $body);
        $request->headers->set('X-HMAC-Signature', $signature);
        $request->headers->set('X-Timestamp', (string) $timestamp);
        $request->headers->set('X-Auth-Client', 'test-api-key');

        $this->expectExceptionObject(
            new WebhookVerificationException('TIMESTAMP_TOO_OLD', 'Timestamp is too old')
        );

        $this->middleware->handle($request, $this->passthrough());
    }

    public function test_custom_prefix_inherits_webhook_tolerance_from_default(): void
    {
        config(['proofage.webhook_tolerance' => 60]);
        config([
            'services.proofage_seller.api_key' => 'seller-api-key',
            'services.proofage_seller.secret_key' => 'seller-secret-key',
        ]);

        $timestamp = now()->timestamp - 90;
        $body = '{"test": true}';
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'seller-secret-key');

        $request = $this->makeRequest(body: $body);
        $request->headers->set('X-HMAC-Signature', $signature);
        $request->headers->set('X-Timestamp', (string) $timestamp);
        $request->headers->set('X-Auth-Client', 'seller-api-key');

        $this->expectExceptionObject(
            new WebhookVerificationException('TIMESTAMP_TOO_OLD', 'Timestamp is too old')
        );

        $this->middleware->handle($request, $this->passthrough(), 'services.proofage_seller');
    }

    public function test_middleware_throws_webhook_verification_exception(): void
    {
        $this->app['router']->post('/test-webhook', function () {
            return response()->json(['success' => true]);
        })->middleware('proofage.verify_webhook');

        $this->withoutExceptionHandling();

        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessage('HMAC signature is invalid');

        $this->postJson('/test-webhook', ['test' => 'data'], [
            'X-HMAC-Signature' => 'bad',
            'X-Timestamp' => (string) now()->timestamp,
            'X-Auth-Client' => 'test-api-key',
        ]);
    }

    protected function makeRequest(string $body = ''): Request
    {
        return Request::create('/webhook', 'POST', [], [], [], [], $body);
    }

    protected function passthrough(): \Closure
    {
        return fn () => response()->json(['success' => true]);
    }
}
