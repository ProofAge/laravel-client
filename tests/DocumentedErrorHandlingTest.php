<?php

namespace ProofAge\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ProofAge\Laravel\Exceptions\AuthenticationException;
use ProofAge\Laravel\Exceptions\ValidationException;
use ProofAge\Laravel\ProofAgeClient;

/*
 * The `catch` chains this package prints are executed, not copied. Each test reads the
 * imports and the `catch (...)` order out of the document itself, throws a real 401, 422
 * and 500 through the client, and checks which handler PHP's first-match rule would run.
 *
 * Why: the Laravel 401/422 classes descend from the Laravel base, not from the SDK's own
 * AuthenticationException / ValidationException, so a document that tells a Laravel user to
 * `catch (\ProofAge\Sdk\Exceptions\ValidationException $e)` sends every 422 to the
 * catch-all — a 500 where the reader expected a 422 with getErrors(). That is the defect
 * the exception hierarchy was built to prevent; the docs must not reintroduce it.
 */
class DocumentedErrorHandlingTest extends TestCase
{
    /** @return iterable<string, array{string, string|null}> */
    public static function documentedCatchChains(): iterable
    {
        yield 'README.md' => ['README.md', '## Error Handling'];
        yield 'INSTALLATION.md' => ['INSTALLATION.md', '## Error Handling'];
        yield 'examples/laravel-usage.php' => ['examples/laravel-usage.php', null];
    }

    #[DataProvider('documentedCatchChains')]
    public function test_the_documented_catch_chain_routes_a_422_to_the_validation_handler(string $file, ?string $heading): void
    {
        $chain = $this->catchChainIn($file, $heading);

        Http::fake(['api.test.com/*' => Http::response([
            'error' => ['message' => 'Validation failed'],
            'errors' => ['callback_url' => ['The callback url field is required.']],
        ], 422)]);

        $handler = $this->handlerFor($chain, fn () => $this->client()->verifications()->create(['callback_url' => 'x']));

        $this->assertSame(ValidationException::class, $handler, "{$file}: a 422 must reach the handler that has getErrors(), not a catch-all.");
        $this->assertTrue(method_exists($handler, 'getErrors'));
    }

    #[DataProvider('documentedCatchChains')]
    public function test_the_documented_catch_chain_routes_a_401_to_the_authentication_handler(string $file, ?string $heading): void
    {
        $chain = $this->catchChainIn($file, $heading);

        Http::fake(['api.test.com/*' => Http::response(['error' => ['message' => 'Invalid API key', 'code' => 'UNAUTHORIZED']], 401)]);

        $handler = $this->handlerFor($chain, fn () => $this->client()->workspace()->get());

        $this->assertSame(AuthenticationException::class, $handler, "{$file}: a 401 must reach the authentication handler, not a catch-all.");
    }

    #[DataProvider('documentedCatchChains')]
    public function test_the_documented_catch_chain_still_catches_every_other_status(string $file, ?string $heading): void
    {
        $chain = $this->catchChainIn($file, $heading);

        Http::fake(['api.test.com/*' => Http::response(['error' => ['message' => 'Server Error']], 500)]);

        $handler = $this->handlerFor($chain, fn () => $this->client(['retry_attempts' => 1])->workspace()->get());

        $this->assertNotNull($handler, "{$file}: a 500 must not escape the documented chain.");
        $this->assertNotSame(ValidationException::class, $handler);
        $this->assertNotSame(AuthenticationException::class, $handler);
    }

    #[DataProvider('documentedCatchChains')]
    public function test_every_class_in_the_documented_chain_exists(string $file, ?string $heading): void
    {
        foreach ($this->catchChainIn($file, $heading) as $class) {
            $this->assertTrue(class_exists($class), "{$file} catches {$class}, which does not exist.");
        }
    }

    /**
     * The first class in $chain the exception thrown by $action is an instance of: what a
     * `try { $action } catch (chain[0]) {} catch (chain[1]) {} ...` would run.
     *
     * @param  list<class-string>  $chain
     * @return class-string|null
     */
    private function handlerFor(array $chain, callable $action): ?string
    {
        try {
            $action();
        } catch (\Throwable $thrown) {
            foreach ($chain as $class) {
                if ($thrown instanceof $class) {
                    return $class;
                }
            }

            return null;
        }

        $this->fail('Expected the client to throw.');
    }

    /**
     * The fully qualified classes named by the `catch (...)` blocks of the first PHP snippet
     * under $heading (or of the whole file when $heading is null), in the order they appear,
     * each resolved through the snippet's own `use` imports.
     *
     * @return list<class-string>
     */
    private function catchChainIn(string $file, ?string $heading): array
    {
        $source = file_get_contents(__DIR__.'/../'.$file);
        $this->assertNotFalse($source, "{$file} is missing.");

        if ($heading !== null) {
            $level = strspn($heading, '#');
            $section = strstr($source, "\n{$heading}\n");
            $this->assertNotFalse($section, "{$file} has no '{$heading}' section.");
            $end = preg_match('/\n#{1,'.$level.'} /', $section, $m, PREG_OFFSET_CAPTURE, 1);
            $section = $end === 1 ? substr($section, 0, $m[0][1]) : $section;

            $this->assertSame(1, preg_match('/```php\n(.*?)```/s', $section, $block), "{$file}: no php snippet under '{$heading}'.");
            $source = $block[1];
        }

        $imports = [];
        preg_match_all('/^use ([\w\\\\]+)(?:\s+as\s+(\w+))?;/m', $source, $uses, PREG_SET_ORDER);

        foreach ($uses as $use) {
            $imports[$use[2] ?? substr(strrchr('\\'.$use[1], '\\'), 1)] = $use[1];
        }

        preg_match_all('/catch\s*\(\s*([\w\\\\]+)\s+\$/', $source, $catches);
        $this->assertNotEmpty($catches[1], "{$file}: no catch blocks found.");

        $chain = [];

        foreach ($catches[1] as $name) {
            $class = str_starts_with($name, '\\') ? substr($name, 1) : ($imports[$name] ?? $name);

            if (! in_array($class, $chain, true)) {
                $chain[] = $class;
            }
        }

        return $chain;
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
}
