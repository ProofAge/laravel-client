<?php

namespace ProofAge\Laravel\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use ProofAge\Laravel\Exceptions\ProofAgeException;
use ProofAge\Laravel\ProofAgeClient;
use ProofAge\Laravel\Resources\VerificationResource;
use ProofAge\Sdk\Enums\VerificationStatus;

class VerificationResourceTest extends TestCase
{
    /**
     * A verification as GET /v1/verifications/{id} returns it; create adds `url`.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function verificationBody(string $id, array $overrides = []): array
    {
        return $overrides + [
            'id' => $id,
            'external_id' => 'user-42',
            'external_metadata' => ['plan' => 'pro'],
            'redirect_url' => 'https://example.com/verification/done',
            'status' => 'created',
            'reason' => null,
            'duplicate_check' => ['checked' => false, 'duplicate_count' => 0, 'duplicates' => []],
            'erasure' => null,
            'consent_accepted_at' => null,
            'created_at' => '2026-09-27T12:00:00+00:00',
            'updated_at' => '2026-09-27T12:00:00+00:00',
        ];
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

    public function test_create_sends_post_with_data(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications' => Http::response($this->verificationBody('ver_new', [
                'url' => 'https://idv.proofage.net/v/eyJ...',
            ]), 201),
        ]);

        $result = $client->verifications()->create([
            'callback_url' => 'https://example.com/verification/done',
            'external_id' => 'user-42',
            'external_metadata' => ['plan' => 'pro'],
        ]);

        $this->assertEquals('ver_new', $result['id']);
        $this->assertEquals('created', $result['status']);
        $this->assertSame('https://example.com/verification/done', $result['redirect_url']);
        $this->assertSame('user-42', $result['external_id']);
        $this->assertSame('https://idv.proofage.net/v/eyJ...', $result['url']);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/v1/verifications')
                && $request['external_id'] === 'user-42'
                && $request->hasHeader('X-HMAC-Signature');
        });
    }

    public function test_find_sends_get_to_correct_endpoint(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_abc' => Http::response($this->verificationBody('ver_abc', [
                'status' => 'approved',
                'consent_accepted_at' => '2026-09-27T12:01:00+00:00',
            ])),
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
            'api.test.com/v1/verifications/ver_xyz' => Http::response($this->verificationBody('ver_xyz', [
                'status' => 'documents_required',
            ])),
        ]);

        $result = $client->verifications('ver_xyz')->get();

        $this->assertEquals('ver_xyz', $result['id']);
        // Surfaced from the latest attempt rather than the verification itself.
        $this->assertSame('documents_required', $result['status']);
        $this->assertSame(VerificationStatus::DOCUMENTS_REQUIRED, VerificationStatus::tryFrom($result['status']));
    }

    public function test_accept_consent_sends_post(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/consent' => Http::response([
                'id' => 3,
                'version' => '2026-09-01',
                'text_sha256' => $sha = hash('sha256', 'consent text'),
                'url' => 'https://proofage.xyz/consent/2026-09-01',
            ]),
            'api.test.com/v1/verifications/ver_123/consent' => Http::response([
                'consent_version_id' => 3,
                'consent_accepted_at' => '2026-09-27T12:01:00+00:00',
            ]),
        ]);

        $consent = $client->workspace()->getConsent();

        $result = $client->verifications('ver_123')->acceptConsent([
            'consent_version_id' => $consent['id'],
            'text_sha256' => $consent['text_sha256'],
        ]);

        $this->assertSame(3, $result['consent_version_id']);
        $this->assertSame('2026-09-27T12:01:00+00:00', $result['consent_accepted_at']);

        Http::assertSent(function ($request) use ($sha) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/v1/verifications/ver_123/consent')
                && $request['consent_version_id'] === 3
                && $request['text_sha256'] === $sha;
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
            'api.test.com/*' => Http::response('', 200),
        ]);

        $file = UploadedFile::fake()->image('doc.jpg', 800, 600);

        $result = $client->verifications('ver_123')->uploadMedia([
            'type' => 'document',
            'side' => 'front',
            'document' => 'passport',
            'file' => $file,
        ]);

        $this->assertNull($result);

        $field = fn ($request, string $name, string $value): bool => collect($request->data())
            ->contains(fn (array $part) => $part['name'] === $name && $part['contents'] === $value);

        Http::assertSent(function ($request) use ($field) {
            return str_contains($request->url(), '/v1/verifications/ver_123/media')
                && $request->hasFile('file', null, 'doc.jpg')
                && $field($request, 'type', 'document')
                && $field($request, 'side', 'front')
                && $field($request, 'document', 'passport')
                && $request->hasHeader('X-HMAC-Signature');
        });
    }

    public function test_upload_media_and_submit_return_null_for_the_empty_200_the_api_answers(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_123/media' => Http::response('', 200),
            'api.test.com/v1/verifications/ver_123/submit' => Http::response('', 200),
        ]);

        $this->assertNull($client->verifications('ver_123')->uploadMedia([
            'type' => 'selfie',
            'file' => UploadedFile::fake()->image('selfie.jpg'),
        ]));
        $this->assertNull($client->verifications('ver_123')->submit());

        Http::assertSentCount(2);
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
            'api.test.com/v1/verifications/ver_123/submit' => Http::response('', 200),
        ]);

        $result = $client->verifications('ver_123')->submit();

        $this->assertNull($result);

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

    public function test_document_sends_get_to_document_endpoint(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_123/document' => Http::response([
                'document' => [
                    'fields' => [
                        'first_name' => 'John',
                        'last_name' => 'Doe',
                        'date_of_birth' => '1990-01-15',
                        'document_number' => 'AB123456',
                    ],
                ],
                'media' => [
                    [
                        'id' => 'media_selfie',
                        'type' => 'selfie',
                        'url' => 'https://api.test.com/v1/verifications/ver_123/media/media_selfie',
                    ],
                    [
                        'id' => 'media_front',
                        'type' => 'document_front',
                        'url' => null,
                    ],
                ],
                'meta' => [
                    'attempt_id' => 'attempt_123',
                ],
            ]),
        ]);

        $result = $client->verifications('ver_123')->document();

        $this->assertSame('John', $result['document']['fields']['first_name']);
        $this->assertSame('AB123456', $result['document']['fields']['document_number']);
        $this->assertSame('media_selfie', $result['media'][0]['id']);
        $this->assertNull($result['media'][1]['url'], 'A purged or expired media has no url.');
        $this->assertSame('attempt_123', $result['meta']['attempt_id']);

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && str_contains($request->url(), '/v1/verifications/ver_123/document')
                && $request->hasHeader('X-HMAC-Signature');
        });
    }

    public function test_download_media_streams_bytes_from_the_media_endpoint(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_1/media/med_1' => Http::response(
                'binary-image-bytes',
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);

        $body = $client->verifications('ver_1')->downloadMedia('med_1');

        $this->assertSame('binary-image-bytes', (string) $body);

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && str_contains($request->url(), '/v1/verifications/ver_1/media/med_1')
                && $request->hasHeader('X-API-Key')
                && $request->hasHeader('X-HMAC-Signature');
        });
    }

    public function test_download_media_signs_the_path_with_an_empty_body(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_1/media/med_1' => Http::response('bytes', 200),
        ]);

        $client->verifications('ver_1')->downloadMedia('med_1');

        $expected = hash_hmac('sha256', 'GET/v1/verifications/ver_1/media/med_1', 'test-secret-key');

        Http::assertSent(fn ($request) => $request->header('X-HMAC-Signature')[0] === $expected);
    }

    public function test_download_media_to_writes_the_file_to_disk(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_1/media/med_1' => Http::response('binary-image-bytes', 200),
        ]);

        $path = sys_get_temp_dir().'/proofage-media-'.uniqid().'.jpg';

        $returned = $client->verifications('ver_1')->downloadMediaTo('med_1', $path);

        $this->assertSame($path, $returned);
        $this->assertFileExists($path);
        $this->assertSame('binary-image-bytes', file_get_contents($path));

        unlink($path);
    }

    public function test_download_media_does_not_retry_a_rate_limit(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_1/media/med_1' => Http::sequence()
                ->push(['error' => ['code' => 'RATE_LIMIT']], 429)
                ->push('bytes', 200),
        ]);

        try {
            $client->verifications('ver_1')->downloadMedia('med_1');
            $this->fail('Expected the 429 to surface instead of being retried.');
        } catch (ProofAgeException $exception) {
            $this->assertSame(429, $exception->getCode());
        }

        Http::assertSentCount(1);
    }

    public function test_interactive_requests_still_retry_a_rate_limit(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_1/document' => Http::sequence()
                ->push(['error' => ['code' => 'RATE_LIMIT']], 429)
                ->push(['document' => ['fields' => []], 'media' => [], 'meta' => []], 200),
        ]);

        $result = $client->verifications('ver_1')->document();

        $this->assertIsArray($result);
        Http::assertSentCount(2);
    }

    public function test_download_retry_attempts_can_be_raised_for_connection_failures(): void
    {
        // Built by hand rather than with Http::sequence()->pushFailedConnection(),
        // which does not exist before Laravel 11.
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                throw new ConnectionException('Connection timed out');
            }

            return Http::response('bytes', 200);
        });

        $client = new ProofAgeClient([
            'api_key' => 'test-api-key',
            'secret_key' => 'test-secret-key',
            'base_url' => 'https://api.test.com',
            'version' => 'v1',
            'retry_delay' => 0,
            'download_retry_attempts' => 2,
        ]);

        $body = $client->verifications('ver_1')->downloadMedia('med_1');

        // Counted in the fake rather than with Http::assertSentCount(): a fake
        // that throws is never recorded as sent, so only the retry shows up there.
        $this->assertSame('bytes', (string) $body);
        $this->assertSame(2, $calls);
    }

    public function test_download_media_throws_when_no_id(): void
    {
        $client = $this->makeFakedClient([]);

        $this->expectException(\InvalidArgumentException::class);

        $client->verifications()->downloadMedia('med_1');
    }

    public function test_document_throws_when_no_id(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/*' => Http::response([], 200),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Verification ID is required');

        $client->verifications()->document();
    }

    public function test_estimation_sends_get_to_estimation_endpoint(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_123/estimation' => Http::response([
                'verification_id' => 'ver_123',
                'attempt_id' => 'attempt_123',
                'age_threshold' => [
                    'minimum' => 18,
                    'passed' => true,
                    'confidence' => 0.98,
                ],
                'gender' => [
                    'value' => 0,
                    'confidence' => 0.93,
                ],
            ]),
        ]);

        $result = $client->verifications('ver_123')->estimation();

        $this->assertSame('ver_123', $result['verification_id']);
        $this->assertSame(18, $result['age_threshold']['minimum']);
        $this->assertSame(VerificationResource::GENDER_FEMALE, $result['gender']['value']);

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && str_contains($request->url(), '/v1/verifications/ver_123/estimation')
                && $request->hasHeader('X-HMAC-Signature');
        });
    }

    public function test_estimation_throws_when_no_id(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/*' => Http::response([], 200),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Verification ID is required');

        $client->verifications()->estimation();
    }

    public function test_block_face_sends_post(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_123/blocked-face' => Http::response('', 204),
        ]);

        $result = $client->verifications('ver_123')->blockFace();

        $this->assertNull($result);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/v1/verifications/ver_123/blocked-face')
                && $request->hasHeader('X-HMAC-Signature');
        });
    }

    public function test_block_face_sends_optional_body_data(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_123/blocked-face' => Http::response('', 204),
        ]);

        $result = $client->verifications('ver_123')->blockFace([
            'reason' => 'text here',
        ]);

        $this->assertNull($result);

        $expectedBody = json_encode(['reason' => 'text here']);
        $expectedSignature = hash_hmac(
            'sha256',
            'POST/v1/verifications/ver_123/blocked-face'.$expectedBody,
            'test-secret-key'
        );

        Http::assertSent(function ($request) use ($expectedBody, $expectedSignature) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/v1/verifications/ver_123/blocked-face')
                && $request->body() === $expectedBody
                && $request->header('X-HMAC-Signature') === [$expectedSignature];
        });
    }

    public function test_reused_client_does_not_duplicate_auth_headers_between_requests(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/workspace' => Http::response(['id' => 'ws_123']),
            'api.test.com/v1/verifications/ver_123/blocked-face' => Http::response('', 204),
        ]);

        $client->workspace()->get();
        $client->verifications('ver_123')->blockFace();

        $expectedSignature = hash_hmac(
            'sha256',
            'POST/v1/verifications/ver_123/blocked-face',
            'test-secret-key'
        );

        $blockedFaceRequest = Http::recorded()
            ->map(fn (array $pair) => $pair[0])
            ->first(fn ($request) => str_contains($request->url(), '/v1/verifications/ver_123/blocked-face'));

        $this->assertNotNull($blockedFaceRequest);
        $this->assertSame(['test-api-key'], $blockedFaceRequest->header('X-API-Key'));
        $this->assertSame([$expectedSignature], $blockedFaceRequest->header('X-HMAC-Signature'));
    }

    public function test_block_face_throws_when_no_id(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/*' => Http::response([], 200),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Verification ID is required');

        $client->verifications()->blockFace();
    }
}
