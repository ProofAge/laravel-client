<?php

namespace ProofAge\Laravel\Tests;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use ProofAge\Laravel\Facades\ProofAge;
use ProofAge\Sdk\Signing\Signer;

/**
 * What the API now expects of every request, as it leaves through the Http facade: an
 * X-Timestamp the signature covers, and an Idempotency-Key on the two creates that take one.
 * Signing and the key itself are proofage/php-sdk's; this checks the Laravel transport
 * delivers them untouched, on every attempt.
 */
class RequestSigningTest extends TestCase
{
    /** @return list<HttpRequest> */
    private function sentRequests(): array
    {
        return Http::recorded()->map(fn (array $pair): HttpRequest => $pair[0])->values()->all();
    }

    public function test_a_request_carries_a_fresh_timestamp_and_a_signature_that_covers_it(): void
    {
        Http::fake(['api.test.com/*' => Http::response(['id' => 'ws_1'])]);

        ProofAge::workspace()->get();

        $request = $this->sentRequests()[0];
        $timestamp = $request->header('X-Timestamp')[0];
        $this->assertMatchesRegularExpression('/^\d{10}$/', $timestamp);
        $this->assertEqualsWithDelta(time(), (int) $timestamp, 5);
        $this->assertSame(
            [(new Signer('test-secret-key'))->signRaw('GET', '/v1/workspace', '', $timestamp)],
            $request->header('X-HMAC-Signature'),
        );
        $this->assertNotSame(
            (new Signer('test-secret-key'))->signRaw('GET', '/v1/workspace'),
            $request->header('X-HMAC-Signature')[0],
            'The legacy, untimestamped signature must not be the one sent.',
        );
    }

    public function test_a_create_carries_an_idempotency_key_and_a_valid_timestamped_signature(): void
    {
        Http::fake(['api.test.com/*' => Http::response(['id' => 'ver_1'], 201)]);

        ProofAge::verifications()->create(['external_id' => 'u-1']);

        $request = $this->sentRequests()[0];
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $request->header('Idempotency-Key')[0],
        );
        $this->assertSame(
            [self::expectedSignature($request, 'POST/v1/verifications'.$request->body())],
            $request->header('X-HMAC-Signature'),
        );
    }

    public function test_a_key_from_the_caller_goes_out_as_given(): void
    {
        Http::fake(['api.test.com/*' => Http::response(['id' => 'ver_1'], 201)]);

        ProofAge::verifications()->create(['external_id' => 'u-1'], idempotencyKey: 'order-42');
        ProofAge::webhookSubscriptions()->create(['url' => 'https://example.com/hook'], 'hook-1');

        [$verification, $subscription] = $this->sentRequests();
        $this->assertSame(['order-42'], $verification->header('Idempotency-Key'));
        $this->assertSame(['hook-1'], $subscription->header('Idempotency-Key'));
        $this->assertSame('{"external_id":"u-1"}', $verification->body());
    }

    public function test_other_calls_carry_no_idempotency_key(): void
    {
        Http::fake(['api.test.com/*' => Http::response(['data' => []])]);

        ProofAge::workspace()->get();
        ProofAge::verifications()->list();
        ProofAge::verifications('ver_1')->submit();

        foreach ($this->sentRequests() as $request) {
            $this->assertFalse($request->hasHeader('Idempotency-Key'), $request->method().' '.$request->url());
        }
    }

    public function test_a_retry_keeps_the_key_and_is_signed_again(): void
    {
        Sleep::fake();
        Http::fake(['api.test.com/*' => Http::sequence()
            ->push(['message' => 'down'], 503)
            ->push(['id' => 'ver_1'], 201)]);

        $result = ProofAge::verifications()->create(['external_id' => 'u-1']);

        $this->assertSame('ver_1', $result['id']);
        [$first, $second] = $this->sentRequests();
        $this->assertSame($first->header('Idempotency-Key'), $second->header('Idempotency-Key'));

        foreach ([$first, $second] as $request) {
            $this->assertSame(
                [self::expectedSignature($request, 'POST/v1/verifications'.$request->body())],
                $request->header('X-HMAC-Signature'),
                'Each attempt is signed with the timestamp it carries.',
            );
        }
    }
}
