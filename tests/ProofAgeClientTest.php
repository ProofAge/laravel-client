<?php

namespace ProofAge\Laravel\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use ProofAge\Laravel\Exceptions\AuthenticationException;
use ProofAge\Laravel\Exceptions\ProofAgeException;
use ProofAge\Laravel\Exceptions\ValidationException;
use ProofAge\Laravel\ProofAgeClient;

class ProofAgeClientTest extends TestCase
{
    protected ProofAgeClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new ProofAgeClient([
            'api_key' => 'test-api-key',
            'secret_key' => 'test-secret-key',
            'base_url' => 'https://api.test.com',
            'version' => 'v1',
        ]);
    }

    private function makeFakedClient(array $fakeResponses): ProofAgeClient
    {
        Http::fake($fakeResponses);

        return new ProofAgeClient([
            'api_key' => 'test-api-key',
            'secret_key' => 'test-secret-key',
            'base_url' => 'https://api.test.com',
            'version' => 'v1',
        ]);
    }

    public function test_it_throws_exception_when_api_key_is_missing(): void
    {
        $this->expectException(ProofAgeException::class);
        $this->expectExceptionMessage('API key is required');

        new ProofAgeClient([
            'secret_key' => 'test-secret-key',
            'base_url' => 'https://api.test.com',
        ]);
    }

    public function test_it_throws_exception_when_secret_key_is_missing(): void
    {
        $this->expectException(ProofAgeException::class);
        $this->expectExceptionMessage('Secret key is required');

        new ProofAgeClient([
            'api_key' => 'test-api-key',
            'base_url' => 'https://api.test.com',
        ]);
    }

    public function test_it_can_get_workspace_information(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/workspace' => Http::response(['name' => 'Test Workspace', 'id' => 'ws_123']),
        ]);

        $result = $client->workspace()->get();

        $this->assertEquals('Test Workspace', $result['name']);
        $this->assertEquals('ws_123', $result['id']);
    }

    public function test_it_can_create_verification(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications' => Http::response(['id' => 'ver_123', 'status' => 'pending']),
        ]);

        $result = $client->verifications()->create([
            'callback_url' => 'https://example.com/webhook',
        ]);

        $this->assertEquals('ver_123', $result['id']);
        $this->assertEquals('pending', $result['status']);
    }

    public function test_it_throws_authentication_exception_on_401(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/*' => Http::response(['error' => ['message' => 'Invalid API key']], 401),
        ]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid API key');

        $client->workspace()->get();
    }

    public function test_it_throws_validation_exception_on_422(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/*' => Http::response([
                'error' => ['message' => 'Validation failed'],
                'errors' => ['callback_url' => ['The callback url field is required.']],
            ], 422),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Validation failed');

        $client->verifications()->create([]);
    }

    public function test_it_generates_correct_hmac_signature_for_json_data(): void
    {
        $method = 'POST';
        $endpoint = 'verifications';
        $data = ['callback_url' => 'https://example.com/webhook'];
        $rawBody = json_encode($data);

        $reflection = new \ReflectionClass($this->client);
        $methodReflection = $reflection->getMethod('generateHmacSignature');
        $methodReflection->setAccessible(true);

        $signature = $methodReflection->invoke($this->client, $method, $endpoint, $rawBody);

        $expectedCanonical = 'POST/v1/verifications'.$rawBody;
        $expectedSignature = hash_hmac('sha256', $expectedCanonical, 'test-secret-key');

        $this->assertIsString($signature);
        $this->assertEquals(64, strlen($signature));
        $this->assertEquals($expectedSignature, $signature);
    }

    public function test_it_can_accept_consent_for_verification(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_123/consent' => Http::response(['accepted' => true]),
        ]);

        $result = $client->verifications('ver_123')->acceptConsent([
            'consent_version_id' => 1,
            'text_sha256' => 'abc123',
        ]);

        $this->assertTrue($result['accepted']);
    }

    public function test_it_can_submit_verification(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_123/submit' => Http::response(['id' => 'ver_123', 'status' => 'processing']),
        ]);

        $result = $client->verifications('ver_123')->submit();

        $this->assertEquals('processing', $result['status']);
    }

    public function test_from_response_returns_correct_subclass_for_authentication(): void
    {
        $response = Http::fake([
            '*' => Http::response(['error' => ['message' => 'Unauthorized']], 401),
        ])->get('https://example.com');

        $exception = AuthenticationException::fromResponse($response);

        $this->assertInstanceOf(AuthenticationException::class, $exception);
    }

    public function test_from_response_returns_correct_subclass_for_validation(): void
    {
        $response = Http::fake([
            '*' => Http::response(['error' => ['message' => 'Validation failed'], 'errors' => ['field' => ['required']]], 422),
        ])->get('https://example.com');

        $exception = ValidationException::fromResponse($response);

        $this->assertInstanceOf(ValidationException::class, $exception);
    }

    public function test_it_sends_file_upload_as_multipart(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/*' => Http::response(['id' => 'media_123'], 200),
        ]);

        $file = UploadedFile::fake()->image('selfie.jpg', 640, 480);

        $client->makeRequest('POST', 'verifications/ver_123/media', ['type' => 'selfie'], ['file' => $file]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'verifications/ver_123/media')
                && $request->hasHeader('X-HMAC-Signature')
                && $request->hasHeader('X-API-Key');
        });
    }
}
