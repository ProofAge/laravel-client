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
returns and what `fromResponse()` takes. Nothing that goes through a resource method or the facade
is affected.

Eight methods carry over with the same meaning — `status()`, `body()`, `json()`, `header()`,
`headers()`, `successful()`, `failed()` and `ok()` — so this code needs no change:

```php
$response = $client->makeRequest('GET', 'workspace');
$data = $response->json();
$status = $response->status();
$type = $response->header('Content-Type');        // case-insensitive lookup, as before
$code = $e->getResponse()->json('error.code');    // dot path, as before
```

Three details differ on those eight:

- `header($name)` returns the first value, or `null` when the header is absent. Illuminate's
  returned `getHeaderLine()`: every value joined with `, `, and `''` when absent. A check such as
  `$response->header('X-Request-Id') === ''` becomes `=== null`.
- `json($key)` walks a plain dot path (`error.code`, `errors.file.0`) and returns `$default` when a
  segment is missing. Illuminate's went through `data_get()`, so a `*` wildcard (`errors.*.0`) is
  not supported, and there is no third `$flags` argument.
- `headers()` returns the names exactly as the server sent them, which is what Illuminate's
  returned as well (both read the same Guzzle response), so `headers()['Content-Type']` is
  unchanged. Over HTTP/2 a server sends every name in lower case, so prefer `header()` for a lookup
  that should not depend on the connection.

Everything else on `Illuminate\Http\Client\Response` does not exist on the SDK response. A call
raises `Error: Call to undefined method`, and only when that line runs: a
`$e->getResponse()->serverError()` inside a `catch` block is not found until the API next answers
with an error, so grep for these rather than waiting for one.

- A type hint, `instanceof` or a test double typed on `Illuminate\Http\Client\Response`. Hint
  `ProofAge\Sdk\Http\Response`. A Mockery stub of `makeRequest()` that returns an Illuminate
  response fails on its first call with `TypeError: ProofAgeClient::makeRequest(): Return value
  must be of type ProofAge\Sdk\Http\Response`; build the stubbed value with
  `ProofAge\Sdk\Testing\FakeHttpClient::json([...], 200)` instead, or replace the double with
  `Http::fake()`, which the client still honours.
- Status helpers: `clientError()`, `serverError()`, `unauthorized()`, `forbidden()`, `notFound()`,
  `conflict()`, `unprocessableEntity()`, `unprocessableContent()`, `tooManyRequests()`,
  `badRequest()`, `paymentRequired()`, `requestTimeout()`, `created()`, `accepted()`,
  `noContent()`, `movedPermanently()`, `found()`, `notModified()`, `redirect()`. Compare
  `status()`; `successful()` and `failed()` remain.
- Other body shapes: `object()`, `collect()`, `fluent()`, `resource()`, and `reason()`. Use
  `json()`, `(object) $response->json()`, `collect($response->json())`.
- `ArrayAccess` (`$response['id']`, `isset($response['id'])`) and `(string) $response` /
  `__toString()`. Use `json()['id']` and `body()`.
- Throwing: `throw()`, `throwIf()`, `throwUnless()`, `throwIfStatus()`, `throwUnlessStatus()`,
  `throwIfClientError()`, `throwIfServerError()`, `toException()`, `onError()`. The client already
  throws for every non-2xx, which is what these did.
- Transport internals: `toPsrResponse()` (`getBody()` returns the PSR-7 stream it was used for),
  `cookies()`, `effectiveUri()`, `handlerStats()`, `close()`.
- `dd()`, `dump()`, `ddHeaders()`, `dumpHeaders()`, `tap()`, and macros registered with
  `Response::macro()` or `mixin()`.

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
- `timeout`, `retry_attempts`, `retry_delay` and `download_retry_attempts` must be integers; an
  integer-valued string, which is what `env()` returns, is accepted. The published
  `config/proofage.php` casts them, so an app configured through it is unaffected.
  `new ProofAgeClient(['timeout' => 0.5, ...])`, which 0.6 handed to Guzzle as half a second, now
  throws `ProofAgeException` at construction: use whole seconds.
- `makeRequest()` and `makeStreamedRequest()` percent-encode each path segment of the endpoint and
  throw `\InvalidArgumentException` for an endpoint containing a `.` or `..` segment, a `#`,
  whitespace or a control character. 0.6 sent those as given and the API answered 401
  "HMAC signature is invalid". Pass raw segments; a pre-encoded one is encoded again.
- `downloadMediaTo()` writes to a temporary sibling file and renames it into place after a 2xx. A
  404 or a network failure leaves nothing at the destination and a file already there untouched;
  0.6 left the JSON error body under the media's name.
- Retries, delays and both retry policies (interactive and download) are unchanged.

### New in 0.7

- `app(\ProofAge\Sdk\Client::class)` resolves the same singleton as `app(ProofAgeClient::class)`.
- `ProofAgeClient` inherits the SDK's middleware and events: `pushMiddleware()`, `onRequest()`,
  `onResponse()`, `onError()`. See the SDK's README.
- `ProofAge\Sdk\Testing\FakeHttpClient` is available for tests that want a transport double
  instead of `Http::fake()`: `new ProofAgeClient($config, $fake)`.
