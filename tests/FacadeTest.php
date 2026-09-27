<?php

namespace ProofAge\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use ProofAge\Laravel\Facades\ProofAge;
use ProofAge\Laravel\ProofAgeClient;
use ProofAge\Laravel\Resources\VerificationResource;
use ProofAge\Laravel\Resources\WorkspaceResource;

class FacadeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'api.test.com/*' => Http::response(['id' => 'test_123', 'name' => 'Test'], 200),
        ]);
    }

    public function test_facade_can_access_workspace(): void
    {
        $workspace = ProofAge::workspace();

        $this->assertInstanceOf(WorkspaceResource::class, $workspace);

        $result = $workspace->get();

        $this->assertIsArray($result);
        $this->assertEquals('test_123', $result['id']);
    }

    public function test_facade_can_create_verification(): void
    {
        $verifications = ProofAge::verifications();

        $this->assertInstanceOf(VerificationResource::class, $verifications);

        $result = $verifications->create(['callback_url' => 'https://example.com']);

        $this->assertIsArray($result);
    }

    public function test_facade_can_work_with_specific_verification(): void
    {
        $verification = ProofAge::verifications('ver_123');

        $this->assertInstanceOf(VerificationResource::class, $verification);

        $result = $verification->get();

        $this->assertIsArray($result);
    }

    public function test_the_facade_docblock_names_only_real_client_and_resource_methods(): void
    {
        $doc = (string) (new \ReflectionClass(ProofAge::class))->getDocComment();

        preg_match_all('/@method static \S+ (\w+)\(/', $doc, $methods);
        $this->assertNotEmpty($methods[1]);

        foreach ($methods[1] as $method) {
            $this->assertTrue(method_exists(ProofAgeClient::class, $method), "The facade docblock names {$method}(), which ProofAgeClient does not have.");
        }

        $this->assertSame(1, preg_match('/verifications\(\) has (.+?)\./s', $doc, $listed));
        preg_match_all('/(\w+)\(\)/', $listed[1], $resourceMethods);

        foreach ($resourceMethods[1] as $method) {
            $this->assertTrue(method_exists(VerificationResource::class, $method), "The facade docblock names VerificationResource::{$method}(), which does not exist.");
        }

        $this->assertSame(1, preg_match('/workspace\(\) has (.+?);/s', $doc, $listed));
        preg_match_all('/(\w+)\(\)/', $listed[1], $resourceMethods);

        foreach ($resourceMethods[1] as $method) {
            $this->assertTrue(method_exists(WorkspaceResource::class, $method), "The facade docblock names WorkspaceResource::{$method}(), which does not exist.");
        }
    }
}
