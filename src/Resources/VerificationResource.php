<?php

namespace ProofAge\Laravel\Resources;

use ProofAge\Laravel\ProofAgeClient;

class VerificationResource
{
    public const GENDER_FEMALE = 0;

    public const GENDER_MALE = 1;

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

    /**
     * Get sanitized document fields and source media for verification.
     */
    public function document(): ?array
    {
        if (! $this->verificationId) {
            throw new \InvalidArgumentException('Verification ID is required');
        }

        $response = $this->client->makeRequest(
            'GET',
            "verifications/{$this->verificationId}/document"
        );

        return $response->json();
    }

    /**
     * Get sanitized age estimation and gender result for verification.
     *
     * Gender value mapping: GENDER_FEMALE (0) means female, GENDER_MALE (1) means male.
     *
     * @return array{
     *     verification_id: string,
     *     attempt_id: string|null,
     *     age_threshold: array{
     *         minimum: int|null,
     *         passed: bool|null,
     *         confidence: float|null
     *     },
     *     gender: array{
     *         value: self::GENDER_FEMALE|self::GENDER_MALE|null,
     *         confidence: float|null
     *     }|null
     * }|null
     */
    public function estimation(): ?array
    {
        if (! $this->verificationId) {
            throw new \InvalidArgumentException('Verification ID is required');
        }

        $response = $this->client->makeRequest(
            'GET',
            "verifications/{$this->verificationId}/estimation"
        );

        return $response->json();
    }

    /**
     * Block the verification face for future AML checks.
     */
    public function blockFace(): ?array
    {
        if (! $this->verificationId) {
            throw new \InvalidArgumentException('Verification ID is required');
        }

        $response = $this->client->makeRequest(
            'POST',
            "verifications/{$this->verificationId}/blocked-face"
        );

        return $response->json();
    }
}
