<?php

namespace ProofAge\Laravel\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use ProofAge\Laravel\ProofAgeClient;

class VerificationResourceTest extends TestCase
{
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

    public function test_create_sends_post_with_data(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications' => Http::response([
                'id' => 'ver_new',
                'status' => 'pending',
            ]),
        ]);

        $result = $client->verifications()->create([
            'callback_url' => 'https://example.com/callback',
            'metadata' => ['user_id' => 42],
        ]);

        $this->assertEquals('ver_new', $result['id']);
        $this->assertEquals('pending', $result['status']);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/v1/verifications')
                && $request->hasHeader('X-HMAC-Signature');
        });
    }

    public function test_find_sends_get_to_correct_endpoint(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_abc' => Http::response([
                'id' => 'ver_abc',
                'status' => 'approved',
            ]),
        ]);

        $result = $client->verifications()->find('ver_abc');

        $this->assertEquals('ver_abc', $result['id']);
        $this->assertEquals('approved', $result['status']);

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && str_contains($request->url(), '/v1/verifications/ver_abc');
        });
    }

    public function test_get_throws_when_no_id_set(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/*' => Http::response([], 200),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Verification ID is required');

        $client->verifications()->get();
    }

    public function test_get_fetches_by_constructor_id(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_xyz' => Http::response([
                'id' => 'ver_xyz',
                'status' => 'pending',
            ]),
        ]);

        $result = $client->verifications('ver_xyz')->get();

        $this->assertEquals('ver_xyz', $result['id']);
    }

    public function test_accept_consent_sends_post(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_123/consent' => Http::response(['accepted' => true]),
        ]);

        $result = $client->verifications('ver_123')->acceptConsent([
            'consent_version_id' => 1,
            'text_sha256' => 'hash',
        ]);

        $this->assertTrue($result['accepted']);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/v1/verifications/ver_123/consent');
        });
    }

    public function test_accept_consent_throws_when_no_id(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/*' => Http::response([], 200),
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $client->verifications()->acceptConsent(['consent_version_id' => 1]);
    }

    public function test_upload_media_sends_multipart_request(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/*' => Http::response(['id' => 'media_456']),
        ]);

        $file = UploadedFile::fake()->image('doc.jpg', 800, 600);

        $result = $client->verifications('ver_123')->uploadMedia([
            'type' => 'document_front',
            'file' => $file,
        ]);

        $this->assertEquals('media_456', $result['id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1/verifications/ver_123/media')
                && $request->hasHeader('X-HMAC-Signature');
        });
    }

    public function test_upload_media_throws_when_no_id(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/*' => Http::response([], 200),
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $client->verifications()->uploadMedia(['type' => 'selfie']);
    }

    public function test_submit_sends_post(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_123/submit' => Http::response([
                'id' => 'ver_123',
                'status' => 'processing',
            ]),
        ]);

        $result = $client->verifications('ver_123')->submit();

        $this->assertEquals('processing', $result['status']);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/v1/verifications/ver_123/submit');
        });
    }

    public function test_submit_throws_when_no_id(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/*' => Http::response([], 200),
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $client->verifications()->submit();
    }
}
