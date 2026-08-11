<?php

namespace App\Policies;

use App\Enums\SubmissionStatus;
use App\Models\BookSubmission;
use App\Models\User;

class BookSubmissionPolicy
{
    public function view(User $user, BookSubmission $submission): bool
    {
        return $user->isAdmin() || $submission->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true; // Any authenticated user may propose an EPUB.
    }

    public function cancel(User $user, BookSubmission $submission): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        // Owners may withdraw their own submission while it awaits approval.
        return $submission->user_id === $user->id
            && $submission->status === SubmissionStatus::PendingApproval;
    }

    public function approve(User $user, BookSubmission $submission): bool
    {
        return $user->isAdmin();
    }

    public function reject(User $user, BookSubmission $submission): bool
    {
        return $user->isAdmin();
    }
}
