<?php

namespace ProofAge\Laravel\Support;

use ProofAge\Sdk\Client;
use ProofAge\Sdk\Http\Body\FilePart;
use ProofAge\Sdk\Http\Body\RawBody;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Middleware\SignMiddleware;
use ProofAge\Sdk\Signing\Signer;
use ProofAge\Sdk\Webhooks\WebhookSignatureVerifier;
use ProofAge\Sdk\Webhooks\WebhookVerifier;
use Symfony\Component\VarDumper\Caster\Caster;
use Symfony\Component\VarDumper\Cloner\AbstractCloner;
use Symfony\Component\VarDumper\Cloner\Stub;

/**
 * Makes dd() and dump() show the SDK's redacted view of the objects that hold a secret or a body.
 *
 * The SDK protects print_r() and var_dump() with __debugInfo(). Symfony's VarDumper, which
 * Laravel's dd() and dump() use, reads the real properties by reflection and only merges
 * __debugInfo() on top of them, so `dd($client)` printed the secret key and `dd($e)` the signed
 * API key and the uploaded image. A caster replaces what VarDumper shows for a class and its
 * subclasses (ProofAgeClient and this package's WebhookSignatureVerifier are covered through
 * their SDK parents); this one returns the __debugInfo() view and nothing else.
 *
 * Registered from src/Support/dump-casters.php when Composer's autoloader loads, not from the
 * service provider: Laravel builds the cloner behind dd() once, in
 * FoundationServiceProvider::register(), which runs before any package provider, and a cloner
 * copies the default casters at the moment it is built. A caster added from this package's
 * provider reaches a VarCloner built later but never the one behind dd().
 */
final class DumpCasters
{
    /** @var list<class-string> the SDK classes that hold a secret or a body; each implements __debugInfo() */
    public const CLASSES = [
        Client::class,
        Signer::class,
        SignMiddleware::class,
        Request::class,
        FilePart::class,
        RawBody::class,
        WebhookVerifier::class,
        WebhookSignatureVerifier::class,
    ];

    /** A no-op in an application without symfony/var-dumper, which illuminate/support only suggests. */
    public static function register(): void
    {
        if (! class_exists(AbstractCloner::class)) {
            return;
        }

        AbstractCloner::addDefaultCasters(array_fill_keys(self::CLASSES, [self::class, 'castToDebugInfo']));
    }

    /**
     * The caster VarDumper calls: __debugInfo()'s view as virtual properties, in place of the
     * real ones. Nested objects — the signer inside the sign middleware, a file part inside a
     * request body — are cast in turn through their own entry.
     *
     * @param  array<string, mixed>  $properties  the real properties VarDumper read; discarded
     * @return array<string, mixed>
     */
    public static function castToDebugInfo(object $object, array $properties, Stub $stub, bool $isNested): array
    {
        if (! method_exists($object, '__debugInfo')) {
            return $properties;
        }

        $view = [];

        foreach ($object->__debugInfo() as $key => $value) {
            $view[Caster::PREFIX_VIRTUAL.$key] = $value;
        }

        return $view;
    }
}
