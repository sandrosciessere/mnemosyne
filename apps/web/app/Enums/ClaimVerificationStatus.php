<?php

namespace App\Enums;

/**
 * Whether the independent verifier accepted a generated claim. Rejected
 * claims are kept for audit but never displayed as supported content.
 */
enum ClaimVerificationStatus: string
{
    case Verified = 'verified';
    case Rejected = 'rejected';
}
