<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['answers', 'flags'])]
class SurveyResponse extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'flags' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Survey, $this>
     */
    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    /**
     * @return mixed
     */
    public function answerTo(string $questionId)
    {
        return $this->answers[$questionId] ?? null;
    }

    /**
     * One answer as a single line of text, for tables, detail pages and CSV export.
     *
     * @param  array<string, mixed>  $question
     */
    public function displayAnswer(array $question): string
    {
        $answer = $this->answerTo($question['id']);

        if ($answer === null || $answer === '' || $answer === []) {
            return '';
        }

        return match ($question['type']) {
            'currency' => self::formatRupiah($answer),
            'signature' => '[tanda tangan]',
            'matrix' => collect($question['matrix']['rows'])
                ->filter(fn (string $row, int $index): bool => ($answer[$index] ?? '') !== '')
                ->map(fn (string $row, int $index): string => $row.': '.$answer[$index])
                ->implode('; '),
            'region' => collect(Survey::regionFields($question))
                ->filter(fn (array $field): bool => isset($answer[$field['key']]))
                ->map(fn (array $field): string => $field['label'].': '.$answer[$field['key']])
                ->implode('; '),
            'table' => collect($answer)->map(function (array $row, int $index) use ($question): string {
                $prefix = $question['table']['mode'] === 'fixed' ? ($question['table']['rows'][$index] ?? 'Baris '.($index + 1)) : '['.($index + 1).']';
                $cells = collect($question['table']['columns'])
                    ->filter(fn (array $column): bool => ($row[$column['id']] ?? '') !== '')
                    ->map(fn (array $column): string => $column['label'].'='.$row[$column['id']])
                    ->implode(', ');

                return $prefix.' '.$cells;
            })->implode(' | '),
            default => is_array($answer) ? implode('; ', $answer) : (string) $answer,
        };
    }

    public static function formatRupiah(string|int|float $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }
}
