<?php

namespace App\Models;

use App\Enums\ConversationMessageRole;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationMessage extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'role' => ConversationMessageRole::class,
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function answerRun(): BelongsTo
    {
        return $this->belongsTo(GroundedAnswerRun::class, 'grounded_answer_run_id');
    }
}
