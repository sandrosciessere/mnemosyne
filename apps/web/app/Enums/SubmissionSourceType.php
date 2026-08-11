<?php

namespace App\Enums;

enum SubmissionSourceType: string
{
    case Upload = 'upload';
    case Filesystem = 'filesystem';
}
