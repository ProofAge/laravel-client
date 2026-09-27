<?php

namespace ProofAge\Laravel\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as IlluminateResponse;
use Illuminate\Support\Facades\Http;
use ProofAge\Sdk\Exceptions\TransportException;
use ProofAge\Sdk\Http\Body\MultipartBody;
use ProofAge\Sdk\Http\Body\RawBody;
use ProofAge\Sdk\Http\HttpClient;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\Response;

/**
 * The SDK transport for Laravel: sends through the Http facade.
 *
 * The facade is resolved at send time and no Factory instance is held, so Http::fake()
 * — which registers its stubs on the container's factory — intercepts every request,
 * including those made by a client singleton built before the fake was set up.
 *
 * No ->retry() and no ->throw(). RetryMiddleware drives attempts (a second loop here would
 * multiply them) and Client maps a non-2xx status to an exception (throwing here would
 * bypass that mapping). The adapter sends exactly what it is given and reports what came
 * back; a failure below HTTP is the one thing it translates, into TransportException.
 */
final class IlluminateHttpClient implements HttpClient
{
    public function send(Request $request): Response
    {
        $pending = Http::timeout($request->timeout)->withHeaders($request->headers);

        try {
            $illuminate = $this->dispatch($pending, $request);
        } catch (ConnectionException $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }

        return new Response(
            $illuminate->status(),
            $illuminate->headers(),
            $illuminate->toPsrResponse()->getBody(),
            $request,
        );
    }

    private function dispatch(PendingRequest $pending, Request $request): IlluminateResponse
    {
        $body = $request->body;

        if ($body instanceof MultipartBody) {
            // attach() rather than a pre-encoded body, so Http::fake() request inspection
            // ($request->hasFile('file'), $request['type']) keeps working. The multipart
            // signature covers the fields and the file hashes, never the encoded body, so
            // the fields go out exactly as the SDK signed them: no value is added, dropped
            // or rewritten here. Normalising them (skipping null, rendering booleans) is the
            // SDK's MultipartBody's job, for every transport at once.
            foreach ($body->files as $part) {
                $pending = $pending->attach(
                    $part->name,
                    $part->contents,
                    $part->filename,
                    $part->contentType !== null ? ['Content-Type' => $part->contentType] : [],
                );
            }

            // Each field pre-shaped as a {name, contents} part: Illuminate treats any array
            // value holding those two keys as an already-built part, so handing it the raw
            // field map would let a nested value that happens to have them be misread.
            $fields = [];

            foreach ($body->fields as $name => $value) {
                $fields[] = ['name' => (string) $name, 'contents' => $value];
            }

            return $pending->asMultipart()->send($request->method, $request->url, ['multipart' => $fields]);
        }

        if ($body instanceof RawBody) {
            // The bytes the SDK signed are the bytes sent; nothing here re-serialises them.
            return $pending
                ->withBody($body->bytes, $body->contentType)
                ->send($request->method, $request->url);
        }

        if ($request->sink !== null) {
            $pending = $pending->sink($request->sink);
        } elseif ($request->stream) {
            $pending = $pending->withOptions(['stream' => true]);
        }

        return $pending->send($request->method, $request->url);
    }
}
