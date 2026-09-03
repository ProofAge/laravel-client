<?php

namespace ProofAge\Laravel\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use ProofAge\Laravel\Exceptions\AuthenticationException;
use ProofAge\Laravel\Exceptions\ProofAgeException;
use ProofAge\Laravel\Exceptions\ValidationException;
use ProofAge\Laravel\Exceptions\WebhookVerificationException;
use ProofAge\Laravel\Facades\ProofAge;
use ProofAge\Laravel\Middleware\VerifyWebhookSignature;
use ProofAge\Laravel\ProofAgeClient;
use ProofAge\Laravel\Resources\VerificationResource;
use ProofAge\Laravel\Resources\WorkspaceResource;
use ProofAge\Laravel\Services\WebhookSignatureVerifier;
use ProofAge\Sdk\Client as SdkClient;
use ProofAge\Sdk\Enums\BlockFaceReasonCode;
use ProofAge\Sdk\Enums\VerificationStatus;
use ProofAge\Sdk\Enums\WebhookReason;
use ProofAge\Sdk\Exceptions\AuthenticationException as SdkAuthenticationException;
use ProofAge\Sdk\Exceptions\ExceptionInterface;
use ProofAge\Sdk\Exceptions\ProofAgeException as SdkProofAgeException;
use ProofAge\Sdk\Exceptions\TransportException;
use ProofAge\Sdk\Exceptions\ValidationException as SdkValidationException;
use ProofAge\Sdk\Exceptions\WebhookVerificationException as SdkWebhookVerificationException;
use ProofAge\Sdk\Http\Response as SdkResponse;
use ProofAge\Sdk\Resources\VerificationResource as SdkVerificationResource;
use ProofAge\Sdk\Resources\WorkspaceResource as SdkWorkspaceResource;
use ProofAge\Sdk\Webhooks\WebhookSignatureVerifier as SdkWebhookSignatureVerifier;

/*
 * Every pre-0.7 name that survives is a real class the SDK's counterpart sits above.
 * These tests pin what a consumer written against 0.6 can still rely on, and pin the
 * one place where the two hierarchies part ways (see UPGRADE.md), so that neither
 * side of that trade-off is changed by accident.
 */
class BackwardCompatibilityTest extends TestCase
{
    public function test_the_facade_returns_laravel_resources_that_are_also_sdk_resources(): void
    {
        $workspace = ProofAge::workspace();
        $verification = ProofAge::verifications('ver_1');

        $this->assertInstanceOf(WorkspaceResource::class, $workspace);
        $this->assertInstanceOf(SdkWorkspaceResource::class, $workspace);
        $this->assertInstanceOf(VerificationResource::class, $verification);
        $this->assertInstanceOf(SdkVerificationResource::class, $verification);
    }

    public function test_a_directly_constructed_client_returns_the_same_resource_classes(): void
    {
        $client = $this->client();

        $this->assertInstanceOf(WorkspaceResource::class, $client->workspace());
        $this->assertInstanceOf(VerificationResource::class, $client->verifications());
        $this->assertInstanceOf(SdkVerificationResource::class, $client->verifications('ver_1'));
    }

    public function test_the_container_serves_one_client_under_both_names(): void
    {
        $client = $this->app->make(ProofAgeClient::class);

        $this->assertInstanceOf(SdkClient::class, $client);
        $this->assertSame($client, $this->app->make(SdkClient::class), 'app(Sdk\Client::class) must not build a fresh cURL-backed client.');
        $this->assertSame($client, $this->app->make('proofage'));
    }

    public function test_gender_constants_are_readable_through_the_laravel_resource_name(): void
    {
        $this->assertSame(0, VerificationResource::GENDER_FEMALE);
        $this->assertSame(1, VerificationResource::GENDER_MALE);
        $this->assertSame(SdkVerificationResource::GENDER_MALE, VerificationResource::GENDER_MALE);
    }

    public function test_a_401_is_caught_under_both_the_laravel_and_the_sdk_name(): void
    {
        Http::fake(['api.test.com/*' => Http::response(['error' => ['message' => 'Invalid API key', 'code' => 'UNAUTHORIZED']], 401)]);

        $thrown = $this->thrownBy(fn () => $this->client()->workspace()->get());

        $this->assertInstanceOf(AuthenticationException::class, $thrown);
        // Not the SDK's own AuthenticationException: single inheritance forces a choice, and
        // keeping the pre-0.7 catch working wins over matching the SDK's 401 subclass, which
        // no consumer can yet be catching — that namespace ships for the first time in 0.7.0.
        $this->assertNotInstanceOf(SdkAuthenticationException::class, $thrown);
        $this->assertInstanceOf(SdkProofAgeException::class, $thrown);
        $this->assertSame(401, $thrown->getCode());
        $this->assertSame('UNAUTHORIZED', $thrown->getErrorCode());
    }

    public function test_a_422_is_caught_under_both_names_and_keeps_get_errors(): void
    {
        Http::fake(['api.test.com/*' => Http::response([
            'error' => ['message' => 'Validation failed'],
            'errors' => ['callback_url' => ['The callback url field is required.']],
        ], 422)]);

        $thrown = $this->thrownBy(fn () => $this->client()->verifications()->create(['callback_url' => 'x']));

        $this->assertInstanceOf(ValidationException::class, $thrown);
        $this->assertNotInstanceOf(SdkValidationException::class, $thrown);
        $this->assertInstanceOf(SdkProofAgeException::class, $thrown);
        // getErrors() survives the re-parenting because it comes from the shared SDK trait.
        $this->assertSame(['callback_url' => ['The callback url field is required.']], $thrown->getErrors());
    }

    public function test_any_other_status_is_caught_under_both_base_names_with_the_sdk_response(): void
    {
        Http::fake(['api.test.com/*' => Http::response(['error' => ['message' => 'Server Error']], 500)]);

        $thrown = $this->thrownBy(fn () => $this->client(['retry_attempts' => 1])->workspace()->get());

        $this->assertInstanceOf(ProofAgeException::class, $thrown);
        $this->assertInstanceOf(SdkProofAgeException::class, $thrown);
        $this->assertSame(500, $thrown->getCode());
        $this->assertInstanceOf(SdkResponse::class, $thrown->getResponse());
    }

    public function test_a_configuration_error_is_caught_under_both_base_names(): void
    {
        $thrown = $this->thrownBy(fn () => new ProofAgeClient(['api_key' => 'k', 'base_url' => 'https://api.test.com']));

        $this->assertInstanceOf(ProofAgeException::class, $thrown);
        $this->assertInstanceOf(SdkProofAgeException::class, $thrown);
        $this->assertSame('Secret key is required', $thrown->getMessage());
    }

    public function test_the_sdk_base_class_is_the_catch_all_for_every_client_error(): void
    {
        foreach ([401, 422, 500] as $status) {
            Http::fake(['api.test.com/*' => Http::response(['error' => ['message' => "status {$status}"]], $status)]);

            $thrown = $this->thrownBy(fn () => $this->client(['retry_attempts' => 1])->workspace()->get());

            $this->assertInstanceOf(SdkProofAgeException::class, $thrown, "A {$status} must be a ProofAge\\Sdk\\Exceptions\\ProofAgeException.");
            $this->assertInstanceOf(ExceptionInterface::class, $thrown);
        }
    }

    public function test_the_laravel_base_class_catches_every_laravel_subclass(): void
    {
        // Before 0.7.0 this was the documented handler — examples/laravel-usage.php used it
        // as the sole catch in five methods. If a 401 or a 422 stops matching it, an upgrade
        // turns handled API errors into 500s with nothing at upgrade time to say so. The
        // Laravel subclasses therefore descend from the Laravel base, which descends from the
        // SDK base, so a catch on either base still matches.
        foreach ([401, 422] as $status) {
            Http::fake(['api.test.com/*' => Http::response(['error' => ['message' => 'nope']], $status)]);
            $thrown = $this->thrownBy(fn () => $this->client()->workspace()->get());

            $this->assertInstanceOf(ProofAgeException::class, $thrown, "status {$status}");
            $this->assertInstanceOf(SdkProofAgeException::class, $thrown, "status {$status}");
        }
    }

    public function test_the_laravel_validation_exception_still_exposes_its_errors(): void
    {
        // getErrors() comes from the SDK trait rather than from SDK inheritance now.
        Http::fake(['api.test.com/*' => Http::response([
            'error' => ['message' => 'invalid'],
            'errors' => ['external_id' => ['The external id field is required.']],
        ], 422)]);

        $thrown = $this->thrownBy(fn () => $this->client()->workspace()->get());

        $this->assertInstanceOf(ValidationException::class, $thrown);
        $this->assertSame(['external_id' => ['The external id field is required.']], $thrown->getErrors());
    }

    public function test_a_network_failure_is_a_transport_exception_inside_the_sdk_family(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $thrown = $this->thrownBy(fn () => $this->client(['retry_attempts' => 1])->workspace()->get());

        $this->assertInstanceOf(TransportException::class, $thrown);
        $this->assertInstanceOf(SdkProofAgeException::class, $thrown);
        $this->assertNotInstanceOf(ConnectionException::class, $thrown, 'Nothing Illuminate-typed leaves the client.');
        $this->assertInstanceOf(ConnectionException::class, $thrown->getPrevious());
    }

    public function test_make_request_and_make_streamed_request_return_the_sdk_response(): void
    {
        Http::fake([
            'api.test.com/v1/workspace' => Http::response(['id' => 'ws_1'], 200, ['X-Trace' => 'abc']),
            'api.test.com/v1/verifications/ver_1/media/med_1' => Http::response('bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $client = $this->client();

        $response = $client->makeRequest('GET', 'workspace');
        $streamed = $client->makeStreamedRequest('GET', 'verifications/ver_1/media/med_1');

        $this->assertInstanceOf(SdkResponse::class, $response);
        $this->assertInstanceOf(SdkResponse::class, $streamed);

        // The surface Illuminate's response shares with the SDK's, so `$response->json()` code needs no edit.
        foreach (['status', 'body', 'json', 'header', 'headers', 'successful', 'failed', 'ok'] as $method) {
            $this->assertTrue(method_exists($response, $method), "Response::{$method}() is part of the preserved surface.");
        }

        $this->assertSame(['id' => 'ws_1'], $response->json());
        $this->assertSame('abc', $response->header('X-Trace'));
        $this->assertTrue($response->ok());
        $this->assertSame('bytes', (string) $streamed->getBody());
    }

    public function test_the_webhook_signature_verifier_is_the_sdk_verifier(): void
    {
        $verifier = new WebhookSignatureVerifier('secret', 300);

        $this->assertInstanceOf(SdkWebhookSignatureVerifier::class, $verifier);

        $timestamp = time();
        $signature = $verifier->generateSignature('{"status":"approved"}', $timestamp);

        $this->assertTrue($verifier->verify('{"status":"approved"}', $timestamp, $signature));
        $this->assertFalse($verifier->verify('{"status":"declined"}', $timestamp, $signature));
    }

    public function test_the_webhook_verification_exception_renders_its_own_body(): void
    {
        $exception = new WebhookVerificationException('INVALID_SIGNATURE', 'HMAC signature is invalid');

        // Same trade as the 401 and 422 classes: it descends from the Laravel base so the
        // pre-0.7 catch keeps working, and carries the SDK's body through a shared trait.
        $this->assertNotInstanceOf(SdkWebhookVerificationException::class, $exception);
        $this->assertInstanceOf(ProofAgeException::class, $exception);
        $this->assertInstanceOf(SdkProofAgeException::class, $exception);

        $rendered = $exception->render(request());

        $this->assertSame(401, $rendered->getStatusCode());
        $this->assertSame($exception->toArray(), $rendered->getData(true));
        $this->assertSame(['error' => ['code' => 'INVALID_SIGNATURE', 'message' => 'HMAC signature is invalid']], $rendered->getData(true));
    }

    public function test_the_middleware_reports_a_missing_header_before_missing_configuration(): void
    {
        // The pre-0.7 order: an unsigned request is rejected as unsigned even on a
        // misconfigured app. Delegating the checks to the SDK must not reorder them.
        config(['proofage.secret_key' => null]);

        $request = Request::create('/webhook', 'POST', [], [], [], [], '{}');
        $request->headers->set('X-Timestamp', (string) time());
        $request->headers->set('X-Auth-Client', 'test-api-key');

        $thrown = $this->thrownBy(fn () => (new VerifyWebhookSignature)->handle($request, fn () => response('ok')));

        $this->assertInstanceOf(WebhookVerificationException::class, $thrown);
        $this->assertSame('MISSING_SIGNATURE', $thrown->errorCode);
        $this->assertSame(401, $thrown->statusCode);
    }

    public function test_the_laravel_enums_are_gone_and_the_sdk_enums_replace_them(): void
    {
        // The one edit a consumer must make: import ProofAge\Sdk\Enums\* instead. Deliberate
        // (an enum cannot be subclassed, so a twin would drift) and therefore tested.
        foreach (['VerificationStatus', 'WebhookReason', 'BlockFaceReasonCode'] as $enum) {
            $this->assertFalse(enum_exists("ProofAge\\Laravel\\Enums\\{$enum}"), "ProofAge\\Laravel\\Enums\\{$enum} must not exist.");
            $this->assertFalse(class_exists("ProofAge\\Laravel\\Enums\\{$enum}"));
            $this->assertTrue(enum_exists("ProofAge\\Sdk\\Enums\\{$enum}"), "ProofAge\\Sdk\\Enums\\{$enum} is the replacement.");
        }

        $this->assertSame('approved', VerificationStatus::APPROVED->value);
        $this->assertSame('underage', BlockFaceReasonCode::UNDERAGE->value);
        $this->assertTrue(WebhookReason::isAmlBlocklist('aml.blocklist.face_match'));
    }

    public function test_the_retained_laravel_exception_classes_are_marked_deprecated_with_advice_that_works(): void
    {
        // The notice is what an IDE shows on hover, so it is the advice most likely to be
        // followed. "Catch ProofAge\Sdk\Exceptions\ValidationException instead" would send
        // every 422 past the reader's handler (see test_a_422_is_the_laravel_validation_exception...).
        $base = $this->docCommentAsProse(ProofAgeException::class);

        $this->assertStringContainsString('@deprecated', $base);
        $this->assertStringContainsString('Catch ProofAge\Sdk\Exceptions\ProofAgeException instead', $base, 'The SDK base is a strict superset of the Laravel base, so this advice is right.');

        foreach ([AuthenticationException::class, ValidationException::class, WebhookVerificationException::class] as $class) {
            $doc = $this->docCommentAsProse($class);
            $short = substr(strrchr($class, '\\'), 1);

            $this->assertStringContainsString('@deprecated', $doc, "{$class} must carry the 0.7.0 deprecation notice.");
            $this->assertStringNotContainsString("Catch ProofAge\\Sdk\\Exceptions\\{$short} instead", $doc, "{$class}: that catch never matches inside a Laravel application.");
            $this->assertStringContainsString('or ProofAge\Sdk\Exceptions\ProofAgeException for every error', $doc, "{$class} must name the one SDK class that does catch it.");
            $this->assertStringContainsString("ProofAge\\Sdk\\Exceptions\\{$short} does not match", $doc, "{$class} must say which SDK name does not match it.");
        }
    }

    /** The class docblock with the comment decoration and line wrapping removed. */
    private function docCommentAsProse(string $class): string
    {
        $doc = (string) (new \ReflectionClass($class))->getDocComment();

        return trim((string) preg_replace('/\s*\n\s*\*\s*/', ' ', $doc));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function client(array $overrides = []): ProofAgeClient
    {
        return new ProofAgeClient($overrides + [
            'api_key' => 'test-api-key',
            'secret_key' => 'test-secret-key',
            'base_url' => 'https://api.test.com',
            'version' => 'v1',
            'retry_delay' => 0,
        ]);
    }

    private function thrownBy(callable $action): \Throwable
    {
        try {
            $action();
        } catch (\Throwable $e) {
            return $e;
        }

        $this->fail('Expected an exception to be thrown.');
    }
}
