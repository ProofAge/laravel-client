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

    protected PendingRequest $http;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'timeout' => 30,
            'retry_attempts' => 3,
            'retry_delay' => 1000,
        ], $config);

        $this->validateConfig();
        $this->initializeHttpClient();
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

        $request = $this->http->withHeaders([
            'X-API-Key' => $this->config['api_key'],
        ]);

        // Add HMAC signature
        $signature = $this->generateHmacSignature($method, $endpoint, $data, $files);
        $request = $request->withHeaders([
            'X-HMAC-Signature' => $signature,
        ]);

        // Handle file uploads
        if (! empty($files)) {
            $response = $request->attach($files)->send($method, $url, ['form_params' => $data]);
        } else {
            $response = $request->send($method, $url, ['form_params' => $data]);
        }

        return $this->handleResponse($response);
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

    protected function initializeHttpClient(): void
    {
        $this->http = Http::timeout($this->config['timeout'])
            ->retry(
                $this->config['retry_attempts'],
                $this->config['retry_delay'],
                function (Exception $exception, $request) {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    // Don't retry on 4xx errors except 429 (rate limiting) and others that makes no sense to retry
                    if ($exception instanceof RequestException) {
                        $response = $exception->response;

                        if ($response && $response->status() >= 400 && $response->status() < 500) {
                            // Allow retry only for 429 (Too Many Requests)
                            return ! in_array(
                                $response->status(),
                                [
                                    SymfonyResponseAlias::HTTP_UNAUTHORIZED,
                                    SymfonyResponseAlias::HTTP_FORBIDDEN,
                                    SymfonyResponseAlias::HTTP_NOT_FOUND,
                                    SymfonyResponseAlias::HTTP_METHOD_NOT_ALLOWED,
                                    SymfonyResponseAlias::HTTP_TOO_MANY_REQUESTS,
                                ]
                            );
                        }
                    }

                    // Retry on 5xx errors and network issues
                    return true;
                }
            )
            ->acceptJson();
    }

    protected function buildUrl(string $endpoint): string
    {
        $baseUrl = rtrim($this->config['base_url'], '/');
        $version = $this->config['version'];
        $endpoint = ltrim($endpoint, '/');

        return "{$baseUrl}/{$version}/{$endpoint}";
    }

    protected function generateHmacSignature(string $method, string $endpoint, array $data = [], array $files = []): string
    {
        $method = strtoupper($method);
        $path = '/'.$this->config['version'].'/'.ltrim($endpoint, '/');

        $canonicalRequest = $method.$path;

        if (! empty($files)) {
            // Handle multipart form data with files
            $fields = $this->canonicalizeArrayForQuery($data);
            $fieldsString = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);

            $fileHashes = $this->collectFileHashes($files);
            sort($fileHashes); // Stabilize order

            $canonicalRequest .= "\n".$fieldsString."\n".implode(',', $fileHashes);
        } else {
            // Handle JSON data - append raw JSON content
            $jsonContent = empty($data) ? '' : json_encode($data, JSON_UNESCAPED_SLASHES);
            $canonicalRequest .= $jsonContent;
        }

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
