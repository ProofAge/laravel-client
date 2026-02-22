<?php

namespace ProofAge\Laravel\Exceptions;

use Exception;
use Illuminate\Http\Client\Response;

class ProofAgeException extends Exception
{
    protected ?Response $response = null;

    protected array $errorData = [];

    public function __construct(string $message = '', int $code = 0, ?Exception $previous = null, ?Response $response = null)
    {
        parent::__construct($message, $code, $previous);

        $this->response = $response;

        if ($response) {
            $this->parseErrorData();
        }
    }

    public static function fromResponse(Response $response, string $message = ''): static
    {
        $errorMessage = $message ?: 'ProofAge API request failed';

        $json = $response->json();
        if ($json && isset($json['error']['message'])) {
            $errorMessage = $json['error']['message'];
        }

        return new static($errorMessage, $response->status(), null, $response);
    }

    public function getResponse(): ?Response
    {
        return $this->response;
    }

    public function getErrorData(): array
    {
        return $this->errorData;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorData['code'] ?? null;
    }

    protected function parseErrorData(): void
    {
        if ($this->response && $this->response->json()) {
            $data = $this->response->json();

            if (isset($data['error'])) {
                $this->errorData = $data['error'];
            }
        }
    }
}
