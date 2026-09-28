<?php

namespace ProofAge\Laravel\Tests;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use ProofAge\Laravel\Facades\ProofAge;
use ProofAge\Laravel\ProofAgeClient;
use ProofAge\Laravel\ProofAgeClientFactory;
use ProofAge\Sdk\Client;
use ProofAge\Sdk\Exceptions\ProofAgeException;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\Response;

/**
 * X-ProofAge-Sdk and User-Agent as they leave through the Http facade: this package's token
 * first, then the SDK's, on every path a Laravel application sends from.
 */
class SdkIdentificationTest extends TestCase
{
    private static function header(): string
    {
        return 'laravel/'.ProofAge::VERSION.' php/'.Client::VERSION;
    }

    private static function userAgent(): string
    {
        return 'ProofAge-Laravel/'.ProofAge::VERSION.' ProofAge-PHP/'.Client::VERSION.' (PHP '.PHP_VERSION.')';
    }

    /** @return list<HttpRequest> */
    private function sentRequests(): array
    {
        return Http::recorded()->map(fn (array $pair): HttpRequest => $pair[0])->values()->all();
    }

    private function assertIdentified(HttpRequest $request, ?string $header = null, ?string $userAgent = null): void
    {
        $this->assertSame([$header ?? self::header()], $request->header('X-ProofAge-Sdk'));
        $this->assertSame([$userAgent ?? self::userAgent()], $request->header('User-Agent'));
    }

    public function test_the_version_constant_is_a_semantic_version(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', ProofAge::VERSION);
    }

    public function test_the_facade_sends_the_laravel_and_php_tokens(): void
    {
        Http::fake(['api.test.com/*' => Http::response(['id' => 'ws_1'])]);

        ProofAge::workspace()->get();

        $this->assertCount(1, $this->sentRequests());
        $this->assertIdentified($this->sentRequests()[0]);
    }

    public function test_a_create_through_the_container_client_is_identified(): void
    {
        Http::fake(['api.test.com/*' => Http::response(['id' => 'ver_1'], 201)]);

        app(Client::class)->verifications()->create(['external_id' => 'u-1']);

        $this->assertIdentified($this->sentRequests()[0]);
    }

    public function test_a_client_for_another_workspace_prefix_is_identified(): void
    {
        config([
            'services.proofage_seller.api_key' => 'seller-key',
            'services.proofage_seller.secret_key' => 'seller-secret',
        ]);
        Http::fake(['api.test.com/*' => Http::response(['id' => 'ws_2'])]);

        app(ProofAgeClientFactory::class)->make('services.proofage_seller')->workspace()->get();

        $this->assertIdentified($this->sentRequests()[0]);
    }

    public function test_an_upload_and_a_download_are_identified(): void
    {
        Http::fake([
            'api.test.com/v1/verifications/ver_1/media/med_1' => Http::response('jpeg', 200, ['Content-Type' => 'image/jpeg']),
            'api.test.com/*' => Http::response('', 200),
        ]);
        $path = tempnam(sys_get_temp_dir(), 'proofage');
        file_put_contents($path, 'jpeg-bytes');

        try {
            ProofAge::verifications('ver_1')->uploadMedia(['type' => 'selfie', 'file' => $path]);
            ProofAge::verifications('ver_1')->downloadMedia('med_1');
        } finally {
            unlink($path);
        }

        $this->assertCount(2, $this->sentRequests());

        foreach ($this->sentRequests() as $request) {
            $this->assertIdentified($request);
        }
    }

    public function test_the_header_is_not_part_of_the_signature(): void
    {
        Http::fake(['api.test.com/*' => Http::response(['id' => 'ver_1'], 201)]);

        ProofAge::verifications()->create(['external_id' => 'u-1']);

        $request = $this->sentRequests()[0];
        $this->assertSame(
            [hash_hmac('sha256', 'POST/v1/verifications'.$request->body(), 'test-secret-key')],
            $request->header('X-HMAC-Signature'),
        );
    }

    public function test_a_package_wrapping_this_one_goes_first(): void
    {
        Http::fake(['api.test.com/*' => Http::response(['id' => 'ws_1'])]);

        (new ProofAgeClient([
            'api_key' => 'k',
            'secret_key' => 's',
            'base_url' => 'https://api.test.com',
            'sdk_tokens' => ['acme-shop/2.1.0'],
            'user_agent_prefix' => 'AcmeShop/2.1.0',
        ]))->workspace()->get();

        $this->assertIdentified(
            $this->sentRequests()[0],
            'acme-shop/2.1.0 '.self::header(),
            'AcmeShop/2.1.0 '.self::userAgent(),
        );
    }

    public function test_middleware_cannot_drop_the_package_tokens(): void
    {
        Http::fake(['api.test.com/*' => Http::response(['id' => 'ws_1'])]);
        $client = app(ProofAgeClient::class);
        $client->pushMiddleware(static fn (Request $r, callable $next): Response => $next($r->withHeader('X-ProofAge-Sdk', 'other/1.0.0')));

        $client->workspace()->get();

        $this->assertIdentified($this->sentRequests()[0]);
    }

    public function test_a_user_agent_set_by_the_application_is_kept(): void
    {
        Http::fake(['api.test.com/*' => Http::response(['id' => 'ws_1'])]);
        $client = app(ProofAgeClient::class);
        $client->pushMiddleware(static fn (Request $r, callable $next): Response => $next($r->withHeader('User-Agent', 'MyApp/3.0')));

        $client->workspace()->get();

        $this->assertIdentified($this->sentRequests()[0], null, 'MyApp/3.0');
    }

    public function test_invalid_wrapper_tokens_are_still_rejected_by_the_sdk(): void
    {
        $this->expectException(ProofAgeException::class);
        $this->expectExceptionMessage('sdk_tokens');

        new ProofAgeClient([
            'api_key' => 'k',
            'secret_key' => 's',
            'base_url' => 'https://api.test.com',
            'sdk_tokens' => 'acme-shop/2.1.0',
        ]);
    }
}
