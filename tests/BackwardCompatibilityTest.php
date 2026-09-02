<?php

namespace ProofAge\Laravel\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use ProofAge\Laravel\Exceptions\AuthenticationException;
use ProofAge\Laravel\Exceptions\ProofAgeException;
use ProofAge\Laravel\Exceptions\ValidationException;
use ProofAge\Laravel\Exceptions\WebhookVerificationException;
use ProofAge\Laravel\Facades\ProofAge;
use ProofAge\Laravel\ProofAgeClient;
use ProofAge\Laravel\Resources\VerificationResource;
use ProofAge\Laravel\Resources\WorkspaceResource;
use ProofAge\Sdk\Client as SdkClient;
use ProofAge\Sdk\Exceptions\AuthenticationException as SdkAuthenticationException;
use ProofAge\Sdk\Exceptions\ExceptionInterface;
use ProofAge\Sdk\Exceptions\ProofAgeException as SdkProofAgeException;
use ProofAge\Sdk\Exceptions\TransportException;
use ProofAge\Sdk\Exceptions\ValidationException as SdkValidationException;
use ProofAge\Sdk\Http\Response as SdkResponse;
use ProofAge\Sdk\Resources\VerificationResource as SdkVerificationResource;
use ProofAge\Sdk\Resources\WorkspaceResource as SdkWorkspaceResource;

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
        $this->assertInstanceOf(SdkAuthenticationException::class, $thrown);
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
        $this->assertInstanceOf(SdkValidationException::class, $thrown);
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

    public function test_the_laravel_base_class_does_not_catch_the_laravel_401_and_422_subclasses(): void
    {
        // Deliberate, and documented in UPGRADE.md: the Laravel AuthenticationException and
        // ValidationException extend their SDK counterparts so that a `catch` on either name
        // matches. PHP has single inheritance, so they cannot also extend the Laravel base
        // class; a pre-0.7 `catch (ProofAge\Laravel\Exceptions\ProofAgeException)` used as the
        // sole handler therefore no longer sees a 401 or a 422. Flip this test only together
        // with the parents of those two classes and the UPGRADE.md entry.
        Http::fake(['api.test.com/*' => Http::response(['error' => ['message' => 'nope']], 401)]);
        $thrown = $this->thrownBy(fn () => $this->client()->workspace()->get());

        $this->assertNotInstanceOf(ProofAgeException::class, $thrown);
        $this->assertInstanceOf(SdkProofAgeException::class, $thrown);
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

    public function test_the_retained_laravel_exception_classes_are_marked_deprecated(): void
    {
        foreach ([ProofAgeException::class, AuthenticationException::class, ValidationException::class, WebhookVerificationException::class] as $class) {
            $doc = (string) (new \ReflectionClass($class))->getDocComment();

            $this->assertStringContainsString('@deprecated', $doc, "{$class} must carry the 0.7.0 deprecation notice.");
        }
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
