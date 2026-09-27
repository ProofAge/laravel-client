<?php

namespace ProofAge\Laravel\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use ProofAge\Laravel\Exceptions\AuthenticationException;
use ProofAge\Laravel\Exceptions\ProofAgeException;
use ProofAge\Laravel\Exceptions\ValidationException;
use ProofAge\Laravel\ProofAgeClient;
use ProofAge\Sdk\Testing\FakeHttpClient;

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

    private function makeFakedClient(array $fakeResponses, array $overrides = []): ProofAgeClient
    {
        Http::fake($fakeResponses);

        return new ProofAgeClient($overrides + [
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
            'api.test.com/v1/verifications' => Http::response(['id' => 'ver_123', 'status' => 'created'], 201),
        ]);

        $result = $client->verifications()->create([
            'callback_url' => 'https://example.com/verification/done',
        ]);

        $this->assertEquals('ver_123', $result['id']);
        $this->assertEquals('created', $result['status']);
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
        // Signing now lives in the SDK; what this package must guarantee is that the
        // signature the SDK computed is what leaves through the Http facade, over the
        // exact bytes sent — here a body whose slashes json_encode() escapes.
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications' => Http::response(['id' => 'ver_123']),
        ]);
        $data = ['callback_url' => 'https://example.com/verification/done'];
        $rawBody = json_encode($data);

        $client->verifications()->create($data);

        $expectedCanonical = 'POST/v1/verifications'.$rawBody;
        $expectedSignature = hash_hmac('sha256', $expectedCanonical, 'test-secret-key');

        Http::assertSent(function ($request) use ($rawBody, $expectedSignature) {
            return $request->body() === $rawBody
                && strlen($request->header('X-HMAC-Signature')[0]) === 64
                && $request->header('X-HMAC-Signature') === [$expectedSignature];
        });
    }

    public function test_it_can_accept_consent_for_verification(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_123/consent' => Http::response([
                'consent_version_id' => 1,
                'consent_accepted_at' => '2026-09-27T12:01:00+00:00',
            ]),
        ]);

        $result = $client->verifications('ver_123')->acceptConsent([
            'consent_version_id' => 1,
            'text_sha256' => hash('sha256', 'consent text'),
        ]);

        $this->assertSame(1, $result['consent_version_id']);
        $this->assertSame('2026-09-27T12:01:00+00:00', $result['consent_accepted_at']);
    }

    public function test_it_can_submit_verification(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_123/submit' => Http::response('', 200),
        ]);

        $result = $client->verifications('ver_123')->submit();

        $this->assertNull($result, 'Submit answers 200 with an empty body.');
    }

    public function test_from_response_returns_correct_subclass_for_authentication(): void
    {
        $response = FakeHttpClient::json(['error' => ['message' => 'Unauthorized']], 401);

        $exception = AuthenticationException::fromResponse($response);

        $this->assertInstanceOf(AuthenticationException::class, $exception);
        $this->assertSame('Unauthorized', $exception->getMessage());
        $this->assertSame(401, $exception->getCode());
    }

    public function test_from_response_returns_correct_subclass_for_validation(): void
    {
        $response = FakeHttpClient::json(['error' => ['message' => 'Validation failed'], 'errors' => ['field' => ['required']]], 422);

        $exception = ValidationException::fromResponse($response);

        $this->assertInstanceOf(ValidationException::class, $exception);
        $this->assertSame(['field' => ['required']], $exception->getErrors());
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

    public function test_the_retry_wait_goes_through_laravels_sleep_so_sleep_fake_records_it(): void
    {
        Sleep::fake();
        $client = $this->makeFakedClient(
            ['api.test.com/*' => Http::response(['error' => ['message' => 'down']], 503)],
            ['retry_attempts' => 3, 'retry_delay' => 250],
        );

        try {
            $client->workspace()->get();
        } catch (ProofAgeException) {
        }

        Sleep::assertSleptTimes(2);
        Sleep::assertSequence([Sleep::usleep(250_000), Sleep::usleep(250_000)]);
    }

    public function test_a_faked_sleep_does_not_sleep_for_real(): void
    {
        Sleep::fake();
        $client = $this->makeFakedClient(
            ['api.test.com/*' => Http::response(['error' => ['message' => 'down']], 503)],
            ['retry_attempts' => 3, 'retry_delay' => 700],
        );

        $started = hrtime(true);

        try {
            $client->workspace()->get();
        } catch (ProofAgeException) {
        }

        $elapsedMs = (hrtime(true) - $started) / 1e6;

        $this->assertLessThan(700, $elapsedMs, sprintf('Two 700 ms retry waits under Sleep::fake() took %.0f ms: the client slept for real.', $elapsedMs));
    }
}
