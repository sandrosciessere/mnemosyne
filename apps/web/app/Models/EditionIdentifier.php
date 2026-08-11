<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EditionIdentifier extends Model
{
    protected $fillable = [
        'scheme',
        'value',
        // Canonical identity: an ISBN-10 and its ISBN-13 equivalent share
        // one canonical form (isbn13). Matching compares canonical values,
        // not the declared scheme/value pair.
        'canonical_scheme',
        'canonical_value',
        'raw_value',
        'source',
    ];

    public function edition(): BelongsTo
    {
        return $this->belongsTo(Edition::class);
    }
}
