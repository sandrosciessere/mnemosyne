<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscoveryEntry extends Model
{
    protected $guarded = ['*'];

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'imported_at' => 'datetime',
        ];
    }

    public function discoveryRun(): BelongsTo
    {
        return $this->belongsTo(DiscoveryRun::class);
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(BookSubmission::class, 'book_submission_id');
    }
}
