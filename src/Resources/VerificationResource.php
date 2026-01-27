<?php

namespace ProofAge\Laravel\Resources;

use ProofAge\Laravel\ProofAgeClient;

class VerificationResource
{
    protected ProofAgeClient $client;

    protected ?string $verificationId;

    public function __construct(ProofAgeClient $client, ?string $verificationId = null)
    {
        $this->client = $client;
        $this->verificationId = $verificationId;
    }

    /**
     * Create a new verification.
     */
    public function create(array $data): ?array
    {
        $response = $this->client->makeRequest('POST', 'verifications', $data);

        return $response->json();
    }

    /**
     * Get verification by ID.
     */
    public function find(string $id): ?array
    {
        $response = $this->client->makeRequest('GET', "verifications/{$id}");

        return $response->json();
    }

    /**
     * Get current verification (if ID was set in constructor).
     */
    public function get(): ?array
    {
        if (! $this->verificationId) {
            throw new \InvalidArgumentException('Verification ID is required');
        }

        return $this->find($this->verificationId);
    }

    /**
     * Accept consent for verification.
     */
    public function acceptConsent(array $data): ?array
    {
        if (! $this->verificationId) {
            throw new \InvalidArgumentException('Verification ID is required');
        }

        $response = $this->client->makeRequest(
            'POST',
            "verifications/{$this->verificationId}/consent",
            $data
        );

        return $response->json();
    }

    /**
     * Upload media for verification.
     */
    public function uploadMedia(array $data): ?array
    {
        if (! $this->verificationId) {
            throw new \InvalidArgumentException('Verification ID is required');
        }

        $files = [];
        $formData = $data;

        // Extract files from data
        if (isset($data['file'])) {
            $files['file'] = $data['file'];
            unset($formData['file']);
        }

        $response = $this->client->makeRequest(
            'POST',
            "verifications/{$this->verificationId}/media",
            $formData,
            $files
        );

        return $response->json();
    }

    /**
     * Submit verification for processing.
     */
    public function submit(): ?array
    {
        if (! $this->verificationId) {
            throw new \InvalidArgumentException('Verification ID is required');
        }

        $response = $this->client->makeRequest(
            'POST',
            "verifications/{$this->verificationId}/submit"
        );

        return $response->json();
    }
}
