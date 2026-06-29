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
     *
     * @param  array{
     *     fingerprint?: string,
     *     callback_url?: string,
     *     external_id?: string,
     *     external_metadata?: array<string, mixed>,
     *     metadata?: array<string, mixed>
     * }  $data
     * @return array{
     *     id: string,
     *     external_id: string|null,
     *     external_metadata: array<string, mixed>|null,
     *     redirect_url: string|null,
     *     status: string,
     *     reason: string|null,
     *     consent_accepted_at: string|null,
     *     created_at: string,
     *     updated_at: string,
     *     url: string
     * }|null
     */
    public function create(array $data): ?array
    {
        $response = $this->client->makeRequest('POST', 'verifications', $data);

        return $response->json();
    }

    /**
     * Get verification by ID.
     *
     * @return array{
     *     id: string,
     *     external_id: string|null,
     *     external_metadata: array<string, mixed>|null,
     *     redirect_url: string|null,
     *     status: string,
     *     reason: string|null,
     *     consent_accepted_at: string|null,
     *     created_at: string,
     *     updated_at: string
     * }|null
     */
    public function find(string $id): ?array
    {
        $response = $this->client->makeRequest('GET', "verifications/{$id}");

        return $response->json();
    }

    /**
     * Get current verification (if ID was set in constructor).
     *
     * @return array{
     *     id: string,
     *     external_id: string|null,
     *     external_metadata: array<string, mixed>|null,
     *     redirect_url: string|null,
     *     status: string,
     *     reason: string|null,
     *     consent_accepted_at: string|null,
     *     created_at: string,
     *     updated_at: string
     * }|null
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
     *
     * @param  array{consent_version_id: int, text_sha256: string}  $data
     * @return array{consent_version_id: int, consent_accepted_at: string}|null
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
     *
     * `file` is sent as multipart. `side` and `document` are required when `type` is
     * `document`. `capture_resolution` and `device_info` are JSON-encoded strings.
     *
     * @param  array{
     *     file: \Illuminate\Http\UploadedFile|string,
     *     type: string,
     *     side?: string,
     *     document?: string,
     *     fingerprint?: string,
     *     head_turn_step?: int,
     *     capture_resolution?: string,
     *     device_info?: string
     * }  $data
     * @return array{message: string}|null
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
     *
     * @return array{message: string}|null
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
     *
     * Media are ordered selfie, document_front, document_back; `signed_url` values expire
     * at `meta.signed_url_expires_at`.
     *
     * @return array{
     *     document: array{fields: array{first_name: string|null, last_name: string|null, date_of_birth: string|null, document_number: string|null}},
     *     media: list<array{id: string, type: string, signed_url: string|null, expires_at: string}>,
     *     meta: array{attempt_id: string|null, signed_url_ttl_seconds: int, signed_url_expires_at: string}
     * }|null
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
     *
     * The API responds 204 No Content, so this returns null.
     *
     * @param  array{reason?: string}|null  $data
     * @return null
     */
    public function blockFace(?array $data = null): ?array
    {
        if (! $this->verificationId) {
            throw new \InvalidArgumentException('Verification ID is required');
        }

        $response = $this->client->makeRequest(
            'POST',
            "verifications/{$this->verificationId}/blocked-face",
            $data ?? []
        );

        return $response->json();
    }
}
