<?php

namespace App\Services\Answers;

use App\Models\BookAccessGrant;
use App\Models\BookAsset;
use App\Models\User;

/**
 * Fail-closed answer scope resolution, mirroring the M2 retrieval ACL
 * semantics: any unknown OR unauthorized requested ULID rejects the
 * whole request indistinguishably (no existence oracle). ACL is
 * evaluated on EVERY answer request — conversation history never
 * grants access.
 */
class AnswerScopeResolver
{
    /**
     * @param  list<string>|null  $requestedPublicIds
     * @return list<int>|null internal asset ids, or null = not accessible
     */
    public function resolve(User $user, ?array $requestedPublicIds): ?array
    {
        if ($requestedPublicIds !== null && $requestedPublicIds !== []) {
            $assets = BookAsset::query()
                ->whereIn('public_id', $requestedPublicIds)
                ->get(['id', 'public_id']);

            if ($assets->count() !== count(array_unique($requestedPublicIds))) {
                return null;
            }

            if (! $user->isAdmin()) {
                $grantedCount = BookAccessGrant::query()
                    ->where('user_id', $user->id)
                    ->whereIn('book_asset_id', $assets->pluck('id'))
                    ->count();

                if ($grantedCount !== $assets->count()) {
                    return null;
                }
            }

            return $assets->pluck('id')->all();
        }

        // No explicit scope: every book the user can read. (Admin: all
        // assets with any retrieval readiness is resolved downstream by
        // the search scope-readiness filter.)
        if ($user->isAdmin()) {
            return BookAsset::query()->pluck('id')->all();
        }

        return BookAccessGrant::query()
            ->where('user_id', $user->id)
            ->pluck('book_asset_id')
            ->all();
    }
}
