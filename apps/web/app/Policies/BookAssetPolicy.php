<?php

namespace App\Policies;

use App\Models\BookAsset;
use App\Models\User;

class BookAssetPolicy
{
    public function view(User $user, BookAsset $asset): bool
    {
        return $user->isAdmin() || $this->hasGrant($user, $asset);
    }

    /**
     * Downloading the original file follows the same grant model; the
     * grant is created when a user's approved submission completes.
     */
    public function download(User $user, BookAsset $asset): bool
    {
        return $user->isAdmin() || $this->hasGrant($user, $asset);
    }

    private function hasGrant(User $user, BookAsset $asset): bool
    {
        return $asset->accessGrants()->where('user_id', $user->id)->exists();
    }
}
