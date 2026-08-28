<?php

namespace ProofAge\Laravel;

use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use ProofAge\Laravel\Exceptions\AuthenticationException;
use ProofAge\Laravel\Exceptions\ProofAgeException;
use ProofAge\Laravel\Exceptions\ValidationException;
use ProofAge\Laravel\Resources\VerificationResource;
use ProofAge\Laravel\Resources\WorkspaceResource;
use Symfony\Component\HttpFoundation\Response as SymfonyResponseAlias;

class ProofAgeClient
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'timeout' => 30,
            'retry_attempts' => 3,
            'retry_delay' => 1000,
            'download_retry_attempts' => 1,
        ], $config);

        $this->validateConfig();
    }

    public function workspace(): WorkspaceResource
    {
        return new WorkspaceResource($this);
    }

    public function verifications(?string $id = null): VerificationResource
    {
        return new VerificationResource($this, $id);
    }

    public function makeRequest(string $method, string $endpoint, array $data = [], array $files = []): Response
    {
        $url = $this->buildUrl($endpoint);

        $request = $this->newHttpRequest()->withHeaders([
            'X-API-Key' => $this->config['api_key'],
        ]);

        if (! empty($files)) {
            $signature = $this->generateHmacSignatureForFiles($method, $endpoint, $data, $files);
            $request = $request->withHeaders(['X-HMAC-Signature' => $signature]);

            foreach ($files as $name => $file) {
                if ($file instanceof UploadedFile) {
                    $request = $request->attach($name, file_get_contents($file->getRealPath()), $file->getClientOriginalName());
                } elseif (is_string($file) && file_exists($file)) {
                    $request = $request->attach($name, file_get_contents($file), basename($file));
                }
            }

            $response = $request->post($url, $data);
        } else {
            // JSON: serialize body once, sign raw bytes, send the same bytes
            $rawBody = ! empty($data) ? json_encode($data) : '';
            $signature = $this->generateHmacSignature($method, $endpoint, $rawBody);
            $request = $request->withHeaders(['X-HMAC-Signature' => $signature]);

            if ($rawBody !== '') {
                $response = $request
                    ->withHeader('Content-Type', 'application/json')
                    ->send($method, $url, ['body' => $rawBody]);
            } else {
                $response = $request->send($method, $url);
            }
        }

        return $this->handleResponse($response);
    }

    /**
     * Fetch a binary endpoint without JSON decoding.
     *
     * The public API signs GET requests over method + path with an empty body,
     * which is exactly what a bodyless GET sends, so signing is unchanged. What
     * differs from makeRequest() is that the response body is never decoded and,
     * unless a $sink is given, never buffered in memory.
     *
     * @param  string|null  $sink  Absolute path to stream the body into. When null the
     *                             body is returned as a lazily-read PSR-7 stream.
     */
    public function makeStreamedRequest(string $method, string $endpoint, ?string $sink = null): Response
    {
        $url = $this->buildUrl($endpoint);
        $signature = $this->generateHmacSignature($method, $endpoint, '');

        $request = $this->newDownloadHttpRequest()->withHeaders([
            'X-API-Key' => $this->config['api_key'],
            'X-HMAC-Signature' => $signature,
            'Accept' => '*/*',
        ]);

        $request = $sink === null
            ? $request->withOptions(['stream' => true])
            : $request->sink($sink);

        return $this->handleResponse($request->send($method, $url));
    }

    protected function validateConfig(): void
    {
        if (empty($this->config['api_key'])) {
            throw new ProofAgeException('API key is required');
        }

        if (empty($this->config['secret_key'])) {
            throw new ProofAgeException('Secret key is required');
        }

        if (empty($this->config['base_url'])) {
            throw new ProofAgeException('Base URL is required');
        }
    }

    protected function newHttpRequest(): PendingRequest
    {
        return Http::timeout($this->config['timeout'])
            ->retry(
                times: $this->config['retry_attempts'],
                sleepMilliseconds: $this->config['retry_delay'],
                when: function (Exception $exception, $request) {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    if ($exception instanceof RequestException) {
                        $response = $exception->response;

                        if ($response && $response->status() >= 400 && $response->status() < 500) {
                            return $response->status() === SymfonyResponseAlias::HTTP_TOO_MANY_REQUESTS;
                        }
                    }

                    return true;
                },
                throw: false,
            )
            ->acceptJson();
    }

    /**
     * Retry policy for downloads, deliberately different from newHttpRequest().
     *
     * A download runs from a queue whose own backoff owns the wait, so an
     * in-process retry is not free: on 429 it spends the same per-minute budget
     * that just refused us, and any sleep blocks the worker rather than
     * releasing the job. Honouring Retry-After here would block it for longer
     * still. So HTTP statuses are never retried — the caller's queue decides —
     * and only a genuine connection failure is, if the operator raises
     * download_retry_attempts above the default of 1 (no retries at all).
     *
     * The interactive path keeps its 3 quick retries: there a user is waiting
     * and there is no queue to hand the wait to.
     */
    protected function newDownloadHttpRequest(): PendingRequest
    {
        return Http::timeout($this->config['timeout'])
            ->retry(
                times: max(1, (int) ($this->config['download_retry_attempts'] ?? 1)),
                sleepMilliseconds: $this->config['retry_delay'],
                when: fn (Exception $exception) => $exception instanceof ConnectionException,
                throw: false,
            );
    }

    protected function buildUrl(string $endpoint): string
    {
        $baseUrl = rtrim($this->config['base_url'], '/');
        $version = $this->config['version'];
        $endpoint = ltrim($endpoint, '/');

        return "{$baseUrl}/{$version}/{$endpoint}";
    }

    /**
     * Generate HMAC signature for non-file requests using the raw body bytes.
     */
    protected function generateHmacSignature(string $method, string $endpoint, string $rawBody = ''): string
    {
        $method = strtoupper($method);
        $path = '/'.$this->config['version'].'/'.ltrim($endpoint, '/');

        $canonicalRequest = $method.$path.$rawBody;

        return hash_hmac('sha256', $canonicalRequest, $this->config['secret_key']);
    }

    /**
     * Generate HMAC signature for multipart requests with files.
     */
    protected function generateHmacSignatureForFiles(string $method, string $endpoint, array $data, array $files): string
    {
        $method = strtoupper($method);
        $path = '/'.$this->config['version'].'/'.ltrim($endpoint, '/');

        $fields = $this->canonicalizeArrayForQuery($data);
        $fieldsString = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);

        $fileHashes = $this->collectFileHashes($files);
        sort($fileHashes);

        $canonicalRequest = $method.$path."\n".$fieldsString."\n".implode(',', $fileHashes);

        return hash_hmac('sha256', $canonicalRequest, $this->config['secret_key']);
    }

    protected function canonicalizeArrayForQuery(array $input): array
    {
        ksort($input);

        foreach ($input as $k => $v) {
            if (is_array($v)) {
                $input[$k] = $this->canonicalizeArrayForQuery($v);
            }
        }

        return $input;
    }

    protected function collectFileHashes(array $files): array
    {
        $hashes = [];

        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $hashes[] = hash_file('sha256', $file->getRealPath());
            } elseif (is_string($file) && file_exists($file)) {
                $hashes[] = hash_file('sha256', $file);
            }
        }

        return $hashes;
    }

    protected function handleResponse(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        // Handle specific error types
        if ($response->status() === 401) {
            throw AuthenticationException::fromResponse($response);
        }

        if ($response->status() === 422) {
            throw ValidationException::fromResponse($response);
        }

        throw ProofAgeException::fromResponse($response);
    }
}
