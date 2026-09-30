<?php

namespace App\Support;

/**
 * Evaluates "show this block/question when…" conditions.
 *
 * Mirrors the evaluation in public/js/fill.js: conditions may only point at earlier questions,
 * and a hidden question counts as unanswered for everything after it.
 */
class SurveyLogic
{
    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array<string, mixed>  $answers
     * @return list<string>
     */
    public static function visibleQuestionIds(array $blocks, array $answers): array
    {
        $visibleAnswers = [];

        foreach ($blocks as $block) {
            if (! self::passes($block['condition'] ?? null, $visibleAnswers)) {
                continue;
            }

            foreach ($block['questions'] as $question) {
                if (self::passes($question['condition'] ?? null, $visibleAnswers)) {
                    $visibleAnswers[$question['id']] = $answers[$question['id']] ?? null;
                }
            }
        }

        return array_keys($visibleAnswers);
    }

    /**
     * @param  array<string, mixed>|null  $condition
     * @param  array<string, mixed>  $answers
     */
    public static function passes(?array $condition, array $answers): bool
    {
        if (! $condition) {
            return true;
        }

        $answer = $answers[$condition['question_id']] ?? null;
        $value = (string) ($condition['value'] ?? '');
        $isAnswered = is_array($answer) ? $answer !== [] : trim((string) $answer) !== '';
        $equals = is_array($answer)
            ? in_array($value, array_map('strval', $answer), true)
            : trim((string) $answer) === $value;

        return match ($condition['operator']) {
            'answered' => $isAnswered,
            'not_equals' => ! $equals,
            default => $equals,
        };
    }
}
