<?php

namespace App\Support;

use App\Models\Survey;

/**
 * Field logic in the style of OpnForm: every question has base flags (required, hidden, disabled)
 * and optional logic — "when these rules (AND/OR, one level of groups) are true, apply these actions".
 *
 * Mirrors public/js/fill.js. Rules may only point at earlier questions, and a hidden question
 * counts as unanswered for everything after it.
 */
class SurveyLogic
{
    public const ACTIONS = [
        'hide' => 'sembunyikan',
        'show' => 'tampilkan',
        'disable' => 'nonaktifkan',
        'enable' => 'aktifkan',
        'require' => 'jadikan wajib',
        'optional' => 'jadikan opsional',
    ];

    public const VALUELESS_OPERATORS = ['answered', 'empty'];

    public const MAX_RULES = 20;

    /**
     * Operators that compare the answer with one number.
     */
    public const NUMERIC_OPERATORS = ['greater_than', 'less_than', 'greater_or_equal', 'less_or_equal'];

    /**
     * Operators that compare the answer with a range of numbers, written "from;to" (both ends included).
     */
    public const RANGE_OPERATORS = ['between', 'not_between'];

    /**
     * An answer tag inside a rule value: {{question-id}} or {{question-id|fallback}}.
     */
    public const MENTION = '/\{\{([A-Za-z0-9_-]+)(?:\|([^}]*))?\}\}/';

    /**
     * Fills in the field settings a question may lack, and turns the old single "show when"
     * condition into the equivalent "hidden + show when …" logic.
     *
     * @param  array<string, mixed>  $question
     * @return array<string, mixed>
     */
    public static function upgradeQuestion(array $question): array
    {
        $legacy = $question['condition'] ?? null;
        unset($question['condition']);

        $question += [
            'description' => '',
            'required' => false,
            'hidden' => false,
            'disabled' => false,
            'hide_label' => false,
            'placeholder' => '',
            'prefill' => '',
            'width' => 'full',
            'help_position' => 'below',
            'max_length' => null,
            'code_length' => 0,
            'options' => [],
            'logic' => null,
            'validation' => [],
        ];
        $question['validation'] = (is_array($question['validation']) ? $question['validation'] : []) + ['rules' => [], 'message' => '', 'checks' => []];

        if (is_array($legacy) && ($legacy['question_id'] ?? null) && ! $question['logic']) {
            $question['hidden'] = true;
            $question['logic'] = [
                'conditions' => ['operator' => 'and', 'rules' => [$legacy]],
                'actions' => ['show'],
            ];
        }

        return $question;
    }

    /**
     * @param  array<int, mixed>  $blocks
     * @return array<int, mixed>
     */
    public static function upgradeBlocks(array $blocks): array
    {
        foreach ($blocks as $blockIndex => $block) {
            if (! is_array($block) || ! is_array($block['questions'] ?? null)) {
                continue;
            }
            foreach ($block['questions'] as $questionIndex => $question) {
                if (is_array($question)) {
                    $blocks[$blockIndex]['questions'][$questionIndex] = self::upgradeQuestion($question);
                }
            }
        }

        return $blocks;
    }

    /**
     * The effective hidden / disabled / required state of every question for the given answers.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array<string, mixed>  $answers
     * @return array<string, array{hidden: bool, disabled: bool, required: bool}>
     */
    public static function states(array $blocks, array $answers): array
    {
        return self::analyse($blocks, $answers)['states'];
    }

    /**
     * The states, plus the answer of every visible question as the logic sees it
     * (a disabled question holds its pre-filled value).
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array<string, mixed>  $answers
     * @return array{states: array<string, array{hidden: bool, disabled: bool, required: bool}>, known: array<string, mixed>}
     */
    public static function analyse(array $blocks, array $answers): array
    {
        $known = [];
        $states = [];

        foreach ($blocks as $block) {
            $blockVisible = self::passes($block['condition'] ?? null, $known);

            foreach ($block['questions'] as $question) {
                $question = self::upgradeQuestion($question);
                $state = [
                    'hidden' => ! $blockVisible || $question['hidden'],
                    'disabled' => $question['disabled'],
                    'required' => $question['required'],
                ];

                $logic = $question['logic'];
                if ($blockVisible && $logic && self::evaluate($logic['conditions'] ?? null, $known)) {
                    $state = self::apply($logic['actions'] ?? [], $state);
                }

                if ($question['type'] === 'info' || $state['hidden'] || $state['disabled']) {
                    $state['required'] = false;
                }

                $states[$question['id']] = $state;

                if (! $state['hidden']) {
                    // A disabled field cannot be changed by the respondent, so it holds its pre-filled value.
                    $known[$question['id']] = $state['disabled'] ? ($question['prefill'] ?: null) : ($answers[$question['id']] ?? null);
                }
            }
        }

        return ['states' => $states, 'known' => $known];
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array<string, mixed>  $answers
     * @return list<string>
     */
    public static function visibleQuestionIds(array $blocks, array $answers): array
    {
        return array_keys(array_filter(self::states($blocks, $answers), fn (array $state): bool => ! $state['hidden']));
    }

    /**
     * @param  list<string>  $actions
     * @param  array{hidden: bool, disabled: bool, required: bool}  $state
     * @return array{hidden: bool, disabled: bool, required: bool}
     */
    public static function apply(array $actions, array $state): array
    {
        foreach ($actions as $action) {
            match ($action) {
                'hide' => $state['hidden'] = true,
                'show' => $state['hidden'] = false,
                'disable' => $state['disabled'] = true,
                'enable' => $state['disabled'] = false,
                'require' => $state['required'] = true,
                'optional' => $state['required'] = false,
                default => null,
            };
        }

        return $state;
    }

    /**
     * Evaluates a group {operator: and|or, rules: [...]}; a rule is a nested group or a single condition.
     *
     * @param  array<string, mixed>|null  $group
     * @param  array<string, mixed>  $answers
     */
    public static function evaluate(?array $group, array $answers): bool
    {
        $rules = $group['rules'] ?? [];

        if ($rules === []) {
            return false;
        }

        $results = array_map(
            fn (array $rule): bool => isset($rule['rules']) ? self::evaluate($rule, $answers) : self::passes($rule, $answers),
            $rules,
        );

        return ($group['operator'] ?? 'and') === 'or' ? in_array(true, $results, true) : ! in_array(false, $results, true);
    }

    /**
     * Ids of the questions whose answers a rule value refers to.
     *
     * @return list<string>
     */
    public static function mentions(string $value): array
    {
        preg_match_all(self::MENTION, $value, $matches);

        return $matches[1];
    }

    /**
     * Replaces answer tags in a rule value with the respondent's answers, or the tag's fallback when unanswered.
     *
     * @param  array<string, mixed>  $answers
     */
    public static function resolve(string $value, array $answers): string
    {
        if (! str_contains($value, '{{')) {
            return $value;
        }

        return (string) preg_replace_callback(self::MENTION, function (array $match) use ($answers): string {
            $answer = $answers[$match[1]] ?? null;
            $text = is_array($answer)
                ? implode(', ', array_map('strval', array_filter($answer, 'is_scalar')))
                : trim((string) $answer);

            return $text !== '' ? $text : ($match[2] ?? '');
        }, $value);
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
        $resolved = self::resolve((string) ($condition['value'] ?? ''), $answers);
        $value = mb_strtolower(trim($resolved));
        $isAnswered = is_array($answer) ? $answer !== [] : trim((string) $answer) !== '';
        $values = array_map(fn ($item): string => mb_strtolower(trim((string) $item)), is_array($answer) ? $answer : [$answer]);
        $text = is_array($answer) ? '' : mb_strtolower(trim((string) $answer));

        $equals = is_array($answer) ? in_array($value, $values, true) : $text === $value;
        $contains = is_array($answer) ? in_array($value, $values, true) : ($value !== '' && str_contains($text, $value));
        $number = self::number($answer);
        $target = self::number($resolved);

        return match ($condition['operator']) {
            'answered' => $isAnswered,
            'empty' => ! $isAnswered,
            'not_equals' => ! $equals,
            'contains' => $contains,
            'not_contains' => ! $contains,
            'greater_than' => $number !== null && $target !== null && $number > $target,
            'less_than' => $number !== null && $target !== null && $number < $target,
            'greater_or_equal' => $number !== null && $target !== null && $number >= $target,
            'less_or_equal' => $number !== null && $target !== null && $number <= $target,
            'between' => self::inRange($number, $resolved) === true,
            'not_between' => self::inRange($number, $resolved) === false,
            default => $equals,
        };
    }

    /**
     * Readable form of a logic definition, e.g. "Jika 102 sama dengan Ya maka tampilkan".
     *
     * @param  array<string, mixed>|null  $logic
     * @param  array<string, string>  $numbers
     */
    public static function describe(?array $logic, array $numbers): ?string
    {
        if (! $logic || ! ($logic['conditions']['rules'] ?? [])) {
            return null;
        }

        $actions = implode(', ', array_map(fn (string $action): string => self::ACTIONS[$action] ?? $action, $logic['actions'] ?? []));

        return 'Jika '.self::describeGroup($logic['conditions'], $numbers).' maka '.$actions;
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array<string, string>  $numbers
     */
    private static function describeGroup(array $group, array $numbers): string
    {
        $parts = array_map(function (array $rule) use ($numbers): string {
            if (isset($rule['rules'])) {
                return '('.self::describeGroup($rule, $numbers).')';
            }

            $operator = Survey::CONDITION_OPERATORS[$rule['operator']] ?? $rule['operator'];
            $value = in_array($rule['operator'], self::VALUELESS_OPERATORS, true) ? '' : ' '.preg_replace_callback(
                self::MENTION,
                fn (array $match): string => 'jawaban '.($numbers[$match[1]] ?? $match[1]),
                in_array($rule['operator'], self::RANGE_OPERATORS, true) ? str_replace(';', ' sampai ', (string) $rule['value']) : (string) $rule['value'],
            );

            return ($numbers[$rule['question_id']] ?? $rule['question_id']).' '.$operator.$value;
        }, $group['rules']);

        return implode(($group['operator'] ?? 'and') === 'or' ? ' atau ' : ' dan ', $parts);
    }

    /**
     * The two ends of a "from;to" range value, or null when it is not a proper range.
     *
     * @return array{float, float}|null
     */
    public static function rangeBounds(string $value): ?array
    {
        $parts = explode(';', $value);
        $from = self::number($parts[0] ?? null);
        $to = self::number($parts[1] ?? null);

        return count($parts) === 2 && $from !== null && $to !== null && $from <= $to ? [$from, $to] : null;
    }

    /**
     * Whether a number lies inside a range; null when either is missing, so neither range operator holds.
     */
    private static function inRange(?float $number, string $range): ?bool
    {
        $bounds = self::rangeBounds($range);

        return $number === null || $bounds === null ? null : $number >= $bounds[0] && $number <= $bounds[1];
    }

    /**
     * What is wrong with a rule's value for its operator, if anything (answer tags are checked elsewhere).
     */
    public static function valueProblem(string $operator, string $value): ?string
    {
        if (self::mentions($value) !== []) {
            return null;
        }
        if (in_array($operator, self::NUMERIC_OPERATORS, true) && self::number($value) === null) {
            return 'harus memakai angka untuk pembanding angka';
        }
        if (in_array($operator, self::RANGE_OPERATORS, true) && self::rangeBounds($value) === null) {
            return 'harus memakai rentang angka yang benar (dari ≤ sampai)';
        }

        return null;
    }

    private static function number(mixed $value): ?float
    {
        if (is_array($value) || $value === null) {
            return null;
        }

        $value = str_replace(',', '.', trim((string) $value));

        return is_numeric($value) ? (float) $value : null;
    }
}
