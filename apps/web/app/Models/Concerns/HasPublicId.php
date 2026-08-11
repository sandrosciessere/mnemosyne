<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Internal ids stay bigint (join performance at 100k+ scale); every
 * externally visible identifier is an opaque lowercase ULID in public_id.
 * Routes and APIs must bind on public_id, never on the numeric id.
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function ($model) {
            if (empty($model->public_id)) {
                $model->public_id = strtolower((string) Str::ulid());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
