<?php

namespace App\Models;

use App\Support\Formula;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['answers', 'flags', 'source', 'drive'])]
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
            'drive' => 'array',
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
     * The assignment this response answered, if any.
     *
     * @return HasOne<Assignment, $this>
     */
    public function assignment(): HasOne
    {
        return $this->hasOne(Assignment::class, 'response_id');
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

        // A question in a repeating block holds one answer per row: "1) … | 2) …".
        if (! empty($question['in_roster']) && is_array($answer)) {
            $others = (array) ($this->answers[$question['id'].Survey::OTHER_SUFFIX] ?? []);
            $labels = (array) ($this->answers[$question['id'].Survey::LABEL_SUFFIX] ?? []);

            return collect($answer)
                ->map(fn ($value, int $row): string => self::displayValue($question, $value, (string) ($others[$row] ?? ''), (string) ($labels[$row] ?? '')))
                ->filter(fn (string $text): bool => $text !== '')
                ->map(fn (string $text, int $row): string => ($row + 1).') '.$text)
                ->implode(' | ');
        }

        return self::displayValue(
            $question,
            $answer,
            (string) ($this->answers[$question['id'].Survey::OTHER_SUFFIX] ?? ''),
            (string) ($this->answers[$question['id'].Survey::LABEL_SUFFIX] ?? ''),
        );
    }

    /**
     * One value of a question as text; $other is the text typed with "Lainnya", $label the name of
     * a code chosen from a reference list or API.
     *
     * @param  array<string, mixed>  $question
     */
    public static function displayValue(array $question, mixed $answer, string $other = '', string $label = ''): string
    {
        if ($answer === null || $answer === '' || $answer === []) {
            return '';
        }

        if ($label !== '' && is_scalar($answer) && $label !== (string) $answer) {
            return $label.' ['.$answer.']';
        }

        $other = trim($other);
        if ($other !== '' && in_array(Survey::OTHER_VALUE, (array) $answer, true)) {
            $answer = array_map(fn ($value) => $value === Survey::OTHER_VALUE ? Survey::OTHER_VALUE.': '.$other : $value, (array) $answer);
            $answer = $question['type'] === 'multiple' ? $answer : $answer[0];
        }

        return match ($question['type']) {
            'currency' => self::formatRupiah($answer),
            'calc' => Formula::display((string) $answer, $question['format'] ?? 'number', (int) ($question['decimals'] ?? 0)),
            'file' => Survey::isUploadPath($answer) ? asset('storage/'.$answer) : (string) $answer,
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
