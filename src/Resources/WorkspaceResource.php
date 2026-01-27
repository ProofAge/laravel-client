<?php

namespace ProofAge\Laravel\Resources;

use ProofAge\Laravel\ProofAgeClient;

class WorkspaceResource
{
    protected ProofAgeClient $client;

    public function __construct(ProofAgeClient $client)
    {
        $this->client = $client;
    }

    /**
     * Get workspace information.
     */
    public function get(): ?array
    {
        $response = $this->client->makeRequest('GET', 'workspace');

        return $response->json();
    }

    /**
     * Get consent information.
     */
    public function getConsent(): ?array
    {
        $response = $this->client->makeRequest('GET', 'consent');

        return $response->json();
    }
}
