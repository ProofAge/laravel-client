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

### Check: a catch-all on the Laravel base exception

The client still throws `ProofAge\Laravel\Exceptions\AuthenticationException` for a 401,
`ValidationException` for a 422 and `ProofAgeException` for everything else, and every existing
`catch` on those names still matches. What changed is their parents: each now extends its
`ProofAge\Sdk\Exceptions\*` counterpart so that a `catch` on the SDK name matches too, and PHP's
single inheritance means `AuthenticationException` and `ValidationException` therefore no longer
extend the Laravel `ProofAgeException`.

If a `catch (ProofAge\Laravel\Exceptions\ProofAgeException $e)` is your **only** handler, it no
longer sees a 401 or a 422. Catch the SDK base class instead, which every SDK and Laravel
exception extends:

```php
// before: caught 401, 422 and everything else
} catch (\ProofAge\Laravel\Exceptions\ProofAgeException $e) {

// after
} catch (\ProofAge\Sdk\Exceptions\ProofAgeException $e) {
```

A handler that lists `AuthenticationException` and `ValidationException` before the base class,
as the README always showed, is unaffected. The same applies in a webhook controller:
`ProofAge\Laravel\Exceptions\WebhookVerificationException` extends the SDK's, not the Laravel base.

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
and `WebhookVerificationException` are marked `@deprecated` in 0.7.0 and are removed in 1.0. They
are still the classes thrown, and a `catch` on them keeps working for the whole 0.x line; when you
next touch such a `catch`, name the `ProofAge\Sdk\Exceptions\*` parent, which the client satisfies
already.

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
