<?php

namespace ProofAge\Laravel\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use ProofAge\Laravel\ProofAgeClient;
use ProofAge\Laravel\Services\WebhookSignatureVerifier;
use ProofAge\Laravel\Support\DumpCasters;
use ProofAge\Sdk\Webhooks\WebhookVerifier;
use Symfony\Component\VarDumper\Cloner\AbstractCloner;
use Symfony\Component\VarDumper\Cloner\ClonerInterface;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Symfony\Component\VarDumper\Dumper\HtmlDumper;
use Symfony\Component\VarDumper\VarDumper;

/*
 * dd($client) and dd($e) in a Laravel application go through Symfony's VarDumper, which reads
 * every property by reflection and only merges __debugInfo() on top of them. The SDK's own
 * redaction therefore covers print_r() and var_dump() but not dd(): the workspace secret key,
 * the signed API key and an uploaded image came out in full.
 *
 * These tests dump through the very cloner Laravel's dd() handler holds, not a fresh one. That
 * cloner is built once, inside FoundationServiceProvider::register(), and copies the default
 * casters at that moment — before any package provider runs — so a caster that reaches a fresh
 * VarCloner does not necessarily reach dd(). Both are asserted.
 */
class DumpRedactionTest extends TestCase
{
    private const SECRET = 'sk_live_9f8e7d6c5b4a3210';

    private const API_KEY = 'pk_live_1a2b3c4d5e6f';

    public function test_dd_of_the_client_shows_the_config_with_the_keys_masked(): void
    {
        $client = $this->client();

        $this->assertNoSecretIn($client);

        $dump = $this->dumpsOf($client)['dd()'];
        $this->assertStringContainsString('****5e6f', $dump, 'The API key is masked to its last four characters, as the SDK events mask it.');
        $this->assertStringContainsString('[redacted]', $dump);
        $this->assertStringContainsString('https://api.test.com', $dump, 'Non-secret config stays readable.');
    }

    public function test_dd_of_a_caught_api_error_masks_the_signed_request_it_carries(): void
    {
        Http::fake(['api.test.com/*' => Http::response(['error' => ['message' => 'Validation failed'], 'errors' => ['type' => ['required']]], 422)]);

        $thrown = $this->thrownBy(fn () => $this->client()->verifications('ver_1')->submit());

        $this->assertNoSecretIn($thrown);

        $dump = $this->dumpsOf($thrown)['dd()'];
        $this->assertStringContainsString('/v1/verifications/ver_1/submit', $dump, 'The request stays readable.');
        $this->assertStringContainsString('****5e6f', $dump, 'X-API-Key is masked rather than dropped.');
    }

    public function test_dd_of_a_caught_upload_error_shows_the_file_as_size_and_hash_not_bytes(): void
    {
        $bytes = 'JFIF-fake-selfie-'.str_repeat('pixel', 40);
        Http::fake(['api.test.com/*' => Http::response(['error' => ['message' => 'Validation failed'], 'errors' => ['file' => ['too small']]], 422)]);

        $thrown = $this->thrownBy(fn () => $this->client()->verifications('ver_1')->uploadMedia([
            'type' => 'selfie',
            'file' => UploadedFile::fake()->createWithContent('selfie.jpg', $bytes),
        ]));

        $this->assertNoSecretIn($thrown, $bytes);
        $this->assertStringContainsString(hash('sha256', $bytes), $this->dumpsOf($thrown)['dd()'], 'The file is shown as its hash.');
    }

    public function test_a_configuration_failure_keeps_the_config_out_of_its_trace(): void
    {
        // The trace carries every frame's arguments (unless zend.exception_ignore_args is on,
        // so it is forced off here). The SDK's Client::__construct() marks its $config
        // #[\SensitiveParameter]; ProofAgeClient::__construct() is the frame above it and takes
        // the same array, so it must too, or an error page that renders trace arguments —
        // Ignition, Sentry — prints both keys. VarDumper drops the arguments of every frame
        // but the first, which is why the dumps alone could not catch this.
        $previous = ini_set('zend.exception_ignore_args', '0');

        try {
            $thrown = $this->thrownBy(fn () => $this->client(['timeout' => 0.5]));
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }

        $this->assertSame('timeout must be a positive integer, 0.5 given', $thrown->getMessage());

        $frames = array_filter($thrown->getTrace(), fn (array $f) => ($f['class'] ?? null) === ProofAgeClient::class && $f['function'] === '__construct');
        $this->assertCount(1, $frames);
        $this->assertInstanceOf(\SensitiveParameterValue::class, reset($frames)['args'][0] ?? null, 'ProofAgeClient::__construct() must mark $config #[\SensitiveParameter], as the SDK constructor below it does.');

        $this->assertNoSecretIn($thrown);
    }

    public function test_dd_of_the_webhook_verifiers_never_shows_the_secret(): void
    {
        $this->assertNoSecretIn(new WebhookSignatureVerifier(self::SECRET, 300));
        $this->assertNoSecretIn(new WebhookVerifier(self::API_KEY, self::SECRET, 300));
    }

    /**
     * The fatal this pins down only shows on symfony/var-dumper before 7.4, which the prefer-lowest
     * CI job installs; on the version installed here it asserts the registration landed.
     */
    public function test_every_redacted_class_is_a_default_caster(): void
    {
        foreach (DumpCasters::CLASSES as $class) {
            $this->assertSame(
                [DumpCasters::class, 'castToDebugInfo'],
                AbstractCloner::$defaultCasters[$class] ?? null,
                "{$class} is not registered with VarDumper, so dd() of it shows the real properties."
            );
        }
    }

    private function assertNoSecretIn(mixed $value, string ...$alsoAbsent): void
    {
        foreach ($this->dumpsOf($value) as $via => $dump) {
            $this->assertStringNotContainsString(self::SECRET, $dump, "The secret key is in the {$via} dump.");
            $this->assertStringNotContainsString(self::API_KEY, $dump, "The API key is in the {$via} dump in full.");

            foreach ($alsoAbsent as $needle) {
                $this->assertStringNotContainsString($needle, $dump, "'{$needle}' is in the {$via} dump.");
            }
        }
    }

    /**
     * Renderings keyed by how they were produced: the CLI dumper behind a console dd(), the HTML
     * dumper behind a web request's dd() (which also prints source excerpts around each trace
     * frame), and a cloner built after the casters were registered, for contrast.
     *
     * @return array<string, string>
     */
    private function dumpsOf(mixed $value): array
    {
        $cli = new CliDumper;
        $cli->setColors(false);
        $data = $this->ddCloner()->cloneVar($value);

        return [
            'dd()' => (string) $cli->dump($data, true),
            'dd() in a web request' => (string) (new HtmlDumper)->dump($data, true),
            'fresh VarCloner' => (string) $cli->dump((new VarCloner)->cloneVar($value), true),
        ];
    }

    /** The cloner Laravel's dd() and dump() handler holds, read out of the handler closure. */
    private function ddCloner(): ClonerInterface
    {
        $handler = (new \ReflectionClass(VarDumper::class))->getStaticPropertyValue('handler');
        $this->assertInstanceOf(\Closure::class, $handler, 'Laravel registers the dd() handler in FoundationServiceProvider; without it this would not be testing dd().');

        $cloner = (new \ReflectionFunction($handler))->getStaticVariables()['cloner'] ?? null;
        $this->assertInstanceOf(ClonerInterface::class, $cloner, 'Laravel\'s CliDumper::register() captures its VarCloner in the handler closure.');

        return $cloner;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function client(array $overrides = []): ProofAgeClient
    {
        return new ProofAgeClient($overrides + [
            'api_key' => self::API_KEY,
            'secret_key' => self::SECRET,
            'base_url' => 'https://api.test.com',
            'retry_attempts' => 1,
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
