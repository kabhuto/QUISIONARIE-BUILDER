<?php

namespace App\Support;

use App\Models\Survey;
use App\Models\SurveyResponse;
use Illuminate\Support\Collection;

/**
 * Per-question aggregates for the responses dashboard.
 */
class SurveySummary
{
    /**
     * @param  Collection<int, SurveyResponse>  $responses
     * @return list<array<string, mixed>>
     */
    public static function build(Survey $survey, Collection $responses): array
    {
        return array_map(function (array $question) use ($responses): array {
            $answered = $responses->filter(fn (SurveyResponse $response): bool => $response->displayAnswer($question) !== '');
            // In a repeating block every row's answer counts.
            $answers = $answered->flatMap(fn (SurveyResponse $response): array => ! empty($question['in_roster'])
                ? array_values(array_filter((array) $response->answerTo($question['id']), fn ($value): bool => $value !== '' && $value !== [] && $value !== null))
                : [$response->answerTo($question['id'])]);

            $summary = [
                'question' => $question,
                'answered' => $answered->count(),
                'kind' => 'samples',
            ];

            if (in_array($question['type'], Survey::CHOICE_TYPES, true)) {
                $counts = array_fill_keys($question['options'], 0);
                foreach ($answers as $answer) {
                    foreach ((array) $answer as $option) {
                        if (array_key_exists($option, $counts)) {
                            $counts[$option]++;
                        }
                    }
                }

                $largest = max([1, ...array_values($counts)]);

                return array_merge($summary, [
                    'kind' => 'choices',
                    'choices' => array_map(fn (string $option, int $count): array => [
                        'label' => $option,
                        'count' => $count,
                        'percent' => $answers->count() ? round($count / $answers->count() * 100) : 0,
                        'width' => round($count / $largest * 100),
                    ], array_keys($counts), array_values($counts)),
                ]);
            }

            if (in_array($question['type'], Survey::NUMERIC_TYPES, true) && $answers->isNotEmpty()) {
                $numbers = $answers->map(fn ($answer): float => (float) $answer);
                $format = $question['type'] === 'currency'
                    ? fn (float $value): string => SurveyResponse::formatRupiah($value)
                    : fn (float $value): string => (string) round($value, 2);

                return array_merge($summary, [
                    'kind' => 'numbers',
                    'average' => $format($numbers->avg()),
                    'min' => $format($numbers->min()),
                    'max' => $format($numbers->max()),
                ]);
            }

            return $summary + [
                'samples' => $answered->take(3)->map(fn (SurveyResponse $response): string => $response->displayAnswer($question))->values()->all(),
            ];
        }, $survey->questions());
    }
}
