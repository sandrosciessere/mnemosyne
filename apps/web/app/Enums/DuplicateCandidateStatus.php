<?php

namespace App\Enums;

enum DuplicateCandidateStatus: string
{
    case Open = 'open';
    case Dismissed = 'dismissed';
    case Confirmed = 'confirmed';
}
