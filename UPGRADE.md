# Upgrading

## 0.6 to 0.7

0.7.0 rebuilds the package on [`proofage/php-sdk`](https://github.com/ProofAge/php-sdk). Composer
installs the SDK for you. Resource methods, config keys, environment variables, the `ProofAge`
facade, the `proofage.verify_webhook` middleware alias and the `proofage:verify-setup` command are
unchanged, and `Http::fake()` keeps intercepting the client's requests in your tests.

### Required: enums moved to the SDK

`ProofAge\Laravel\Enums\*` no longer exists. Change the import:

```php
// before
use ProofAge\Laravel\Enums\VerificationStatus;
use ProofAge\Laravel\Enums\WebhookReason;
use ProofAge\Laravel\Enums\BlockFaceReasonCode;

// after
use ProofAge\Sdk\Enums\VerificationStatus;
use ProofAge\Sdk\Enums\WebhookReason;
use ProofAge\Sdk\Enums\BlockFaceReasonCode;
```

The cases and values are identical. This is the only edit an upgrade requires; an app that does not
use the enums needs none.

### Exceptions: nothing to change

The client still throws `ProofAge\Laravel\Exceptions\AuthenticationException` for a 401,
`ValidationException` for a 422, `WebhookVerificationException` for a rejected webhook and
`ProofAgeException` for everything else. Every existing `catch` on those names still matches,
including a `catch (ProofAge\Laravel\Exceptions\ProofAgeException $e)` used as the sole
handler — it still sees a 401 and a 422, exactly as before 0.7.0.

What is new is that all of them also descend from `ProofAge\Sdk\Exceptions\ProofAgeException`,
so a catch-all on the SDK base works too and is the form to prefer in new code:

```php
} catch (\ProofAge\Sdk\Exceptions\ProofAgeException $e) {
```

One consequence of PHP's single inheritance is worth knowing if you write new code against the
SDK names: the Laravel 401, 422 and webhook classes descend from the Laravel base, so they are
**not** instances of `ProofAge\Sdk\Exceptions\AuthenticationException`,
`ValidationException` or `WebhookVerificationException`. Inside a Laravel application, catch the
Laravel names for a specific status, or the SDK base for everything. `getErrors()` and
`toArray()` behave identically on both sides; they come from a trait the SDK shares with this
package.

The four Laravel exception classes are `@deprecated` in 0.7.0 and removed in 1.0, when the SDK
names become the only ones.

### Check: `makeRequest()` returns the SDK response

`ProofAgeClient::makeRequest()` and `makeStreamedRequest()` return `ProofAge\Sdk\Http\Response`
instead of `Illuminate\Http\Client\Response`. The same object is what `ProofAgeException::getResponse()`
returns and what `fromResponse()` takes. The SDK response has `status()`, `body()`, `json()`,
`header()`, `headers()`, `successful()`, `failed()` and `ok()` with the same meaning, so this
code needs no change:

```php
$response = $client->makeRequest('GET', 'workspace');
$data = $response->json();
$status = $response->status();
```

Exactly two things break, and only if you use them on that object:

1. A type hint or `instanceof` against `Illuminate\Http\Client\Response`. Hint
   `ProofAge\Sdk\Http\Response` instead.
2. The four Illuminate-only methods `collect()`, `throw()`, `onError()` and `toPsrResponse()`.
   `collect($response->json())` replaces the first; the client already maps a non-2xx status to
   an exception, which is what `throw()` did; `getBody()` returns the PSR-7 stream `toPsrResponse()->getBody()`
   used to.

Nothing that goes through a resource method or the facade is affected.

### Check: network failures are `TransportException`

A connection failure, DNS or TLS error or timeout used to escape as
`Illuminate\Http\Client\ConnectionException`. It is now `ProofAge\Sdk\Exceptions\TransportException`,
which extends `ProofAge\Sdk\Exceptions\ProofAgeException`; `getPrevious()` is the Illuminate
exception.

```php
// before
} catch (\Illuminate\Http\Client\ConnectionException $e) {

// after
} catch (\ProofAge\Sdk\Exceptions\TransportException $e) {
```

### Deprecated: the four Laravel exception classes

`ProofAge\Laravel\Exceptions\ProofAgeException`, `AuthenticationException`, `ValidationException`
and `WebhookVerificationException` are marked `@deprecated` in 0.7.0 and are removed in 1.0, when
the SDK's own classes become what is thrown. They are still the classes thrown, and a `catch` on
them keeps working for the whole 0.x line. When you next touch such a `catch`, the only SDK name
that matches today is the base, `ProofAge\Sdk\Exceptions\ProofAgeException`; keep the Laravel name
for a specific status until 1.0.

### Behaviour changes inherited from the SDK

- `uploadMedia()` with a file path that does not exist throws
  `\InvalidArgumentException("File not found: …")` before anything is sent, instead of silently
  sending the request without the file (which the API answered with a 422).
- A body `json_encode` cannot encode (invalid UTF-8 in `external_metadata`, for example) throws
  `ProofAgeException('Request body is not JSON-encodable')` instead of sending `false`.
- Retries, delays, timeouts and both retry policies (interactive and download) are unchanged.

### New in 0.7

- `app(\ProofAge\Sdk\Client::class)` resolves the same singleton as `app(ProofAgeClient::class)`.
- `ProofAgeClient` inherits the SDK's middleware and events: `pushMiddleware()`, `onRequest()`,
  `onResponse()`, `onError()`. See the SDK's README.
- `ProofAge\Sdk\Testing\FakeHttpClient` is available for tests that want a transport double
  instead of `Http::fake()`: `new ProofAgeClient($config, $fake)`.
