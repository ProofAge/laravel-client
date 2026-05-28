<?php

namespace ProofAge\Laravel\Enums;

enum WebhookReason: string
{
    case AmlBlocklistFaceMatch = 'aml.blocklist.face_match';
    case AmlBlocklistDeviceMatch = 'aml.blocklist.device_match';

    public static function isAmlBlocklist(?string $reason): bool
    {
        return in_array($reason, [
            self::AmlBlocklistFaceMatch->value,
            self::AmlBlocklistDeviceMatch->value,
        ], true);
    }
}
