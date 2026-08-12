<?php

namespace App\Models;

use App\Enums\ClaimVerificationStatus;
use App\Enums\EpistemicLabel;
use App\Enums\VerifierSupportLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class GroundedAnswerClaim extends Model
{
    protected $guarded = ['*'];

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'verification_status' => ClaimVerificationStatus::class,
            'final_label' => EpistemicLabel::class,
            'verifier_support_level' => VerifierSupportLevel::class,
            'ordinal' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(GroundedAnswerRun::class, 'grounded_answer_run_id');
    }

    public function evidence(): BelongsToMany
    {
        return $this->belongsToMany(
            GroundedAnswerEvidence::class,
            'grounded_answer_claim_evidence',
            'grounded_answer_claim_id',
            'grounded_answer_evidence_id',
        )->withPivot('atoms');
    }
}
