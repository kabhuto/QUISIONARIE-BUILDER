<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One respondent's copy of a survey (FormGear's assignment): a private link whose form opens
 * with pre-filled answers ("predata"), some of them locked, and that can be sent once.
 */
#[Fillable(['token', 'label', 'predata', 'locked', 'response_id', 'expires_at'])]
class Assignment extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'predata' => 'array',
            'locked' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Assignment $assignment) {
            $assignment->token ??= Str::lower(Str::random(32));
        });
    }

    /**
     * @return BelongsTo<Survey, $this>
     */
    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    /**
     * @return BelongsTo<SurveyResponse, $this>
     */
    public function response(): BelongsTo
    {
        return $this->belongsTo(SurveyResponse::class);
    }

    public function isSubmitted(): bool
    {
        return $this->response_id !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function status(): string
    {
        return match (true) {
            $this->isSubmitted() => 'submitted',
            $this->isExpired() => 'expired',
            default => 'open',
        };
    }

    public function url(): string
    {
        return route('form.create', ['survey' => $this->survey, 'a' => $this->token]);
    }

    /**
     * Whether a question's answer is fixed to its pre-filled value.
     */
    public function locks(string $questionId): bool
    {
        return in_array($questionId, $this->locked ?? [], true) && array_key_exists($questionId, $this->predata ?? []);
    }

    /**
     * The survey as this assignment's respondent sees it: locked pre-filled answers become fixed values.
     */
    public function applyTo(Survey $survey): Survey
    {
        $locked = array_filter($this->locked ?? [], fn ($id): bool => is_string($id) && is_scalar($this->predata[$id] ?? null));

        if ($locked === []) {
            return $survey;
        }

        $copy = $survey->replicate();
        $copy->id = $survey->id;
        $copy->exists = true;
        $copy->blocks = array_map(function (array $block) use ($locked): array {
            $block['questions'] = array_map(function (array $question) use ($locked): array {
                if (in_array($question['id'], $locked, true)) {
                    $question['disabled'] = true;
                    $question['prefill'] = (string) $this->predata[$question['id']];
                    // A fixed answer cannot be switched back on by the form's logic.
                    if ($question['logic']) {
                        $question['logic']['actions'] = array_values(array_diff($question['logic']['actions'], ['enable']));
                    }
                }

                return $question;
            }, $block['questions']);

            return $block;
        }, $survey->blocks ?? []);

        return $copy;
    }

    /**
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'status' => $this->status(),
            'url' => $this->url(),
            'predata' => $this->predata ?: (object) [],
            'locked' => $this->locked ?? [],
            'response_id' => $this->response_id,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
