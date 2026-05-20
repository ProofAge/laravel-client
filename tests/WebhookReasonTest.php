<?php

namespace ProofAge\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use ProofAge\Laravel\Enums\WebhookReason;

class WebhookReasonTest extends TestCase
{
    public function test_aml_blocklist_face_match_reason_value(): void
    {
        $this->assertSame('aml.blocklist.face_match', WebhookReason::AmlBlocklistFaceMatch->value);
    }
}
