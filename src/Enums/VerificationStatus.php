<?php

namespace ProofAge\Laravel\Enums;

enum VerificationStatus: string
{
    case CREATED = 'created';
    case STARTED = 'started';
    case SUBMITTED = 'submitted';
    case RESUBMISSION_REQUESTED = 'resubmission_requested';
    case APPROVED = 'approved';
    case DECLINED = 'declined';
    case ABANDONED = 'abandoned';
    case EXPIRED = 'expired';
}
