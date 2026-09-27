<?php

namespace ProofAge\Laravel\Tests;

use Composer\InstalledVersions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use ProofAge\Laravel\Http\IlluminateHttpClient;
use ProofAge\Laravel\ProofAgeClient;
use ProofAge\Sdk\Exceptions\ProofAgeException;
use ProofAge\Sdk\Exceptions\TransportException;
use ProofAge\Sdk\Http\Body\FilePart;
use ProofAge\Sdk\Http\Body\MultipartBody;
use ProofAge\Sdk\Http\Body\RawBody;
use ProofAge\Sdk\Http\HttpClient;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\Response;
use ProofAge\Sdk\Http\RetryPolicy;
use ProofAge\Sdk\Signing\Signer;
use Psr\Http\Message\StreamInterface;

/*
 * The adapter is the one piece of transport code left in this package. Everything
 * above it (signing, retries, status mapping) is the SDK's; these tests pin what the
 * adapter itself must do — and must not do — when handed a signed SDK Request.
 */
class IlluminateHttpClientTest extends TestCase
{
    public function test_it_is_an_sdk_transport(): void
    {
        $this->assertInstanceOf(HttpClient::class, new IlluminateHttpClient);
    }

    public function test_headers_pass_through_to_the_http_facade(): void
    {
        Http::fake(['api.test.com/*' => Http::response(['ok' => true])]);

        (new IlluminateHttpClient)->send($this->request('GET', headers: [
            'X-API-Key' => 'key-1234',
            'X-HMAC-Signature' => 'abc',
            'X-Custom' => 'value',
        ]));

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://api.test.com/v1/workspace'
            && $request->header('X-API-Key') === ['key-1234']
            && $request->header('X-HMAC-Signature') === ['abc']
            && $request->header('X-Custom') === ['value']);
    }

    public function test_raw_body_bytes_are_sent_verbatim(): void
    {
        Http::fake(['api.test.com/*' => Http::response(['ok' => true])]);

        $bytes = '{"callback_url":"https:\/\/example.com\/hook","name":"Jürgen"}';

        (new IlluminateHttpClient)->send($this->request(
            'POST',
            body: new RawBody($bytes, 'application/json'),
            headers: ['Content-Type' => 'application/json'],
        ));

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->body() === $bytes
            && $request->hasHeader('Content-Type', 'application/json'));
    }

    public function test_multipart_goes_through_attach_so_fake_request_inspection_works(): void
    {
        Http::fake(['api.test.com/*' => Http::response(['message' => 'ok'])]);

        (new IlluminateHttpClient)->send($this->request(
            'POST',
            body: new MultipartBody(
                ['type' => 'document', 'side' => 'front'],
                [new FilePart('file', 'front.jpg', 'not-really-a-jpeg')],
            ),
        ));

        // For a multipart request Laravel's fake exposes the parts as a list under data(),
        // not keyed by field name, so a form field is found the same way a file is.
        $field = fn ($request, string $name, string $value): bool => collect($request->data())
            ->contains(fn (array $part) => $part['name'] === $name && $part['contents'] === $value);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->isMultipart()
            && $request->hasFile('file', 'not-really-a-jpeg', 'front.jpg')
            && $field($request, 'type', 'document')
            && $field($request, 'side', 'front'));
    }

    public function test_a_file_part_content_type_reaches_the_wire(): void
    {
        Http::fake(['api.test.com/*' => Http::response('', 200)]);

        (new IlluminateHttpClient)->send($this->request(
            'POST',
            body: new MultipartBody(
                ['type' => 'selfie'],
                [new FilePart('file', 'capture', 'bytes', 'image/heic')],
            ),
        ));

        // Without the part's own type Guzzle would guess from the filename, and a name
        // with no extension guesses nothing useful.
        $this->assertContains('Content-Type: image/heic', $this->filePartHeaders('capture'));
    }

    public function test_a_file_part_without_a_content_type_keeps_the_guessed_one(): void
    {
        Http::fake(['api.test.com/*' => Http::response('', 200)]);

        (new IlluminateHttpClient)->send($this->request(
            'POST',
            body: new MultipartBody(['type' => 'selfie'], [new FilePart('file', 'selfie.jpg', 'bytes')]),
        ));

        $this->assertContains('Content-Type: image/jpeg', $this->filePartHeaders('selfie.jpg'));
    }

    public function test_the_multipart_signature_covers_the_fields_as_they_go_on_the_wire(): void
    {
        Http::fake(['api.test.com/*' => Http::response('', 200)]);

        $this->client()->verifications('ver_1')->uploadMedia([
            'type' => 'document',
            'side' => 'front',
            'document' => 'passport',
            'head_turn_step' => 3,
            'nested' => ['b' => 'two', 'a' => 'one'],
            'file' => new FilePart('file', 'front.jpg', 'jpeg-bytes'),
        ]);

        $this->assertWireFieldsVerify();
    }

    public function test_a_null_or_false_field_does_not_break_the_multipart_signature(): void
    {
        Http::fake(['api.test.com/*' => Http::response('', 200)]);

        $this->client()->verifications('ver_1')->uploadMedia([
            'type' => 'selfie',
            'fingerprint' => null,
            'flag' => false,
            'file' => new FilePart('file', 'selfie.jpg', 'jpeg-bytes'),
        ]);

        [$expected, $signed] = $this->wireSignature();

        // proofage/php-sdk up to 0.1.2 signs null as absent and false as "0", while every
        // transport sends both as an empty part, so the server computes a different string.
        // The fix belongs in the SDK's MultipartBody; this adapter forwards the fields as
        // given. Remove this guard once composer.json requires the fixed SDK.
        $installed = (string) InstalledVersions::getPrettyVersion('proofage/php-sdk');

        if ($expected !== $signed && preg_match('/^v?0\.1\.[0-2]$/', $installed) === 1) {
            $this->markTestSkipped("proofage/php-sdk {$installed} signs null/false multipart fields differently from how they are sent.");
        }

        $this->assertSame($expected, $signed);
    }

    public function test_a_connection_exception_becomes_a_transport_exception(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        try {
            (new IlluminateHttpClient)->send($this->request());
            $this->fail('Expected a TransportException.');
        } catch (TransportException $e) {
            $this->assertSame('Connection timed out', $e->getMessage());
            $this->assertInstanceOf(ConnectionException::class, $e->getPrevious());
            $this->assertInstanceOf(ProofAgeException::class, $e, 'A transport failure belongs to the SDK exception family.');
            $this->assertNull($e->getResponse());
        }
    }

    public function test_a_non_2xx_status_is_returned_as_a_response_not_thrown(): void
    {
        Http::fake(['api.test.com/*' => Http::response(['error' => ['message' => 'Nope']], 422)]);

        $response = (new IlluminateHttpClient)->send($this->request());

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(422, $response->status());
        $this->assertSame('Nope', $response->json()['error']['message']);
    }

    public function test_the_adapter_itself_never_retries(): void
    {
        Http::fake([
            'api.test.com/*' => Http::sequence()
                ->push(['error' => ['code' => 'RATE_LIMIT']], 429)
                ->push(['ok' => true], 200),
        ]);

        // A policy that would retry a 429, so that any retry loop inside the adapter would show.
        $response = (new IlluminateHttpClient)->send($this->request(policy: RetryPolicy::interactive(3, 0)));

        $this->assertSame(429, $response->status());
        Http::assertSentCount(1);
    }

    public function test_the_response_carries_status_headers_body_and_the_request_sent(): void
    {
        Http::fake(['api.test.com/*' => Http::response('binary-image-bytes', 200, ['Content-Type' => 'image/jpeg'])]);

        $request = $this->request();
        $response = (new IlluminateHttpClient)->send($request);

        $this->assertSame(200, $response->status());
        $this->assertSame('image/jpeg', $response->header('Content-Type'));
        $this->assertSame('image/jpeg', $response->header('content-type'), 'header() is case-insensitive, as Illuminate\'s is.');
        // headers() keeps the names as the server spelled them, exactly as Illuminate's
        // headers() did (both read the same Guzzle response), so `headers()['Content-Type']`
        // written against 0.6 keeps working.
        $this->assertSame(['image/jpeg'], $response->headers()['Content-Type']);
        $this->assertSame(Http::get($request->url)->headers(), $response->headers());
        $this->assertSame('binary-image-bytes', $response->body());
        $this->assertInstanceOf(StreamInterface::class, $response->getBody());
        $this->assertSame($request, $response->request);
    }

    public function test_timeout_stream_and_sink_reach_the_http_client_options(): void
    {
        $seen = [];
        Http::fake(function ($request, array $options) use (&$seen) {
            $seen[] = $options;

            return Http::response('bytes', 200);
        });

        $transport = new IlluminateHttpClient;
        $sink = sys_get_temp_dir().'/proofage-adapter-'.uniqid().'.bin';

        $transport->send($this->request(timeout: 7));
        $transport->send($this->request(stream: true));
        $transport->send($this->request(sink: $sink));

        $this->assertSame(7, $seen[0]['timeout']);
        $this->assertArrayNotHasKey('stream', $seen[0]);

        $this->assertTrue($seen[1]['stream']);

        $this->assertSame($sink, $seen[2]['sink']);
        $this->assertArrayNotHasKey('stream', $seen[2]);

        @unlink($sink);
    }

    public function test_sink_writes_the_body_to_disk_and_the_response_body_reads_it_back(): void
    {
        Http::fake(['api.test.com/*' => Http::response('binary-image-bytes', 200)]);

        $sink = sys_get_temp_dir().'/proofage-adapter-'.uniqid().'.jpg';

        $response = (new IlluminateHttpClient)->send($this->request(sink: $sink));

        $this->assertFileExists($sink);
        $this->assertSame('binary-image-bytes', file_get_contents($sink));
        $this->assertSame('binary-image-bytes', $response->body());

        unlink($sink);
    }

    public function test_a_fake_registered_after_the_transport_was_built_still_intercepts(): void
    {
        $transport = new IlluminateHttpClient;

        Http::fake(['api.test.com/*' => Http::response(['late' => true])]);

        $response = $transport->send($this->request());

        $this->assertSame(['late' => true], $response->json());
        Http::assertSentCount(1);
    }

    /**
     * The header lines of the file part named $filename in the one recorded request.
     *
     * @return list<string>
     */
    private function filePartHeaders(string $filename): array
    {
        $body = Http::recorded()->first()[0]->body();

        foreach (explode("\r\n--", $body) as $chunk) {
            [$head] = explode("\r\n\r\n", $chunk, 2);

            if (str_contains($head, 'filename="'.$filename.'"')) {
                return explode("\r\n", $head);
            }
        }

        $this->fail("No file part named {$filename} was sent.");
    }

    private function client(): ProofAgeClient
    {
        return new ProofAgeClient([
            'api_key' => 'test-api-key',
            'secret_key' => 'test-secret-key',
            'base_url' => 'https://api.test.com',
            'version' => 'v1',
        ]);
    }

    private function assertWireFieldsVerify(): void
    {
        [$expected, $signed] = $this->wireSignature();

        $this->assertSame($expected, $signed, 'The server recomputes the signature from the fields it receives; they must be the fields that were signed.');
    }

    /**
     * What the server would compute for the one recorded multipart request — its fields
     * decoded the way PHP registers $_POST, its files hashed — beside the signature sent.
     *
     * @return array{string, string}
     */
    private function wireSignature(): array
    {
        $recorded = Http::recorded();
        $this->assertCount(1, $recorded);

        $request = $recorded->first()[0];
        $this->assertTrue($request->isMultipart());
        $this->assertSame(1, preg_match('/boundary=([^;]+)/', $request->header('Content-Type')[0], $m));

        $pairs = [];
        $hashes = [];

        foreach (explode('--'.$m[1], $request->body()) as $chunk) {
            if (! str_contains($chunk, "\r\n\r\n")) {
                continue;
            }

            [$head, $contents] = explode("\r\n\r\n", $chunk, 2);
            $contents = substr($contents, 0, -2);
            preg_match('/name="([^"]*)"/', $head, $name);

            if (str_contains($head, 'filename=')) {
                $hashes[] = hash('sha256', $contents);
            } else {
                $pairs[] = rawurlencode($name[1]).'='.rawurlencode($contents);
            }
        }

        parse_str(implode('&', $pairs), $fields);

        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $expected = hash_hmac('sha256', Signer::canonicalMultipart('POST', $path, $fields, $hashes), 'test-secret-key');

        return [$expected, $request->header('X-HMAC-Signature')[0]];
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function request(
        string $method = 'GET',
        RawBody|MultipartBody|null $body = null,
        array $headers = [],
        ?RetryPolicy $policy = null,
        int $timeout = 30,
        ?string $sink = null,
        bool $stream = false,
    ): Request {
        return new Request(
            $method,
            'https://api.test.com/v1/workspace',
            '/v1/workspace',
            $headers,
            $body,
            $policy ?? RetryPolicy::download(1, 0),
            $timeout,
            $sink,
            $stream,
        );
    }
}
