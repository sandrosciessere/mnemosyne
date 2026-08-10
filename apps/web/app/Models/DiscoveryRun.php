<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiscoveryRun extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'files_seen' => 'integer',
            'epubs_found' => 'integer',
            'entries_created' => 'integer',
            'skipped_outside_root' => 'integer',
            'unreadable' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(DiscoveryEntry::class);
    }
}
