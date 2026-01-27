<?php

namespace ProofAge\Laravel\Exceptions;

class ValidationException extends ProofAgeException
{
    protected array $errors = [];

    public function getErrors(): array
    {
        if (empty($this->errors) && $this->response) {
            $data = $this->response->json();

            if (isset($data['errors'])) {
                $this->errors = $data['errors'];
            }
        }

        return $this->errors;
    }
}
