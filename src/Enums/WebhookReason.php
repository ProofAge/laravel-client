<?php

namespace ProofAge\Laravel\Enums;

enum WebhookReason: string
{
    case AmlBlocklistFaceMatch = 'aml.blocklist.face_match';
}
