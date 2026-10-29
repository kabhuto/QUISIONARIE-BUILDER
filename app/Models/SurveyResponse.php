<?php

namespace App\Models;

use App\Support\Formula;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['answers', 'flags', 'source', 'drive', 'started_at', 'ip_address', 'user_agent', 'survey_version', 'submission_id'])]
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
            'started_at' => 'datetime',
            'survey_version' => 'integer',
        ];
    }

    /**
     * The sender's device in a few words, from the browser's user agent: "Android · Chrome".
     */
    public function deviceLabel(): string
    {
        $agent = (string) $this->user_agent;
        if ($agent === '') {
            return '';
        }

        $system = match (true) {
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'CrOS') => 'ChromeOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Lainnya',
        };
        $browser = match (true) {
            str_contains($agent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'Firefox/') || str_contains($agent, 'FxiOS') => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => '',
        };

        return $browser === '' ? $system : $system.' · '.$browser;
    }

    /**
     * Seconds from opening the form to sending it, when the form reported when it was opened.
     */
    public function durationSeconds(): ?int
    {
        if (! $this->started_at || ! $this->created_at || $this->started_at->greaterThan($this->created_at)) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->created_at);
    }

    /**
     * The filling time as "4 mnt 12 dtk".
     */
    public function durationLabel(): string
    {
        $seconds = $this->durationSeconds();
        if ($seconds === null) {
            return '';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $rest = $seconds % 60;

        return trim(($hours ? $hours.' jam ' : '').($hours || $minutes ? $minutes.' mnt ' : '').$rest.' dtk');
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
        $status = $this->answers[$question['id'].Survey::STATUS_SUFFIX] ?? null;

        // A question in a repeating block holds one answer per row: "1) … | 2) …".
        if (! empty($question['in_roster']) && (is_array($answer) || is_array($status))) {
            $others = (array) ($this->answers[$question['id'].Survey::OTHER_SUFFIX] ?? []);
            $labels = (array) ($this->answers[$question['id'].Survey::LABEL_SUFFIX] ?? []);
            $statuses = (array) $status;
            $rows = (array) $answer + array_fill_keys(array_keys($statuses), '');
            ksort($rows);

            return collect($rows)
                ->map(fn ($value, int $row): string => isset(Survey::NONRESPONSE[$statuses[$row] ?? ''])
                    ? '['.Survey::NONRESPONSE[$statuses[$row]].']'
                    : self::displayValue($question, $value, (string) ($others[$row] ?? ''), (string) ($labels[$row] ?? '')))
                ->filter(fn (string $text): bool => $text !== '')
                ->map(fn (string $text, int $row): string => ($row + 1).') '.$text)
                ->implode(' | ');
        }

        if (is_string($status) && isset(Survey::NONRESPONSE[$status])) {
            return '['.Survey::NONRESPONSE[$status].']';
        }

        if ($answer === null || $answer === '' || $answer === []) {
            return '';
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
