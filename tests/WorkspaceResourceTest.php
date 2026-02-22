<?php

namespace ProofAge\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use ProofAge\Laravel\ProofAgeClient;

class WorkspaceResourceTest extends TestCase
{
    private function makeFakedClient(array $fakeResponses): ProofAgeClient
    {
        Http::fake($fakeResponses);

        return new ProofAgeClient([
            'api_key' => 'test-api-key',
            'secret_key' => 'test-secret-key',
            'base_url' => 'https://api.test.com',
            'version' => 'v1',
        ]);
    }

    public function test_get_returns_workspace_data(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/workspace' => Http::response([
                'id' => 'ws_123',
                'name' => 'My Workspace',
                'webhook_url' => 'https://example.com/webhook',
            ]),
        ]);

        $result = $client->workspace()->get();

        $this->assertEquals('ws_123', $result['id']);
        $this->assertEquals('My Workspace', $result['name']);

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && str_contains($request->url(), '/v1/workspace');
        });
    }

    public function test_get_consent_returns_consent_data(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/consent' => Http::response([
                'id' => 'con_456',
                'version' => 2,
                'text' => 'I consent to age verification.',
            ]),
        ]);

        $result = $client->workspace()->getConsent();

        $this->assertEquals('con_456', $result['id']);
        $this->assertEquals(2, $result['version']);

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && str_contains($request->url(), '/v1/consent');
        });
    }
}
