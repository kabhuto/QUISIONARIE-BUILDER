<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Extra validation rules set per question in the builder's "Validasi" tab.
 * Mirrors public/js/fill.js; a rule only applies once the field has a value.
 */
class FieldValidation
{
    private const TEXT = ['short', 'long', 'email', 'phone', 'code'];

    /**
     * @var array<string, array{label: string, types: list<string>, input: string}>
     */
    public const RULES = [
        'min_length' => ['label' => 'Minimal karakter', 'types' => self::TEXT, 'input' => 'number'],
        'max_length' => ['label' => 'Maksimal karakter', 'types' => self::TEXT, 'input' => 'number'],
        'contains' => ['label' => 'Harus berisi teks', 'types' => self::TEXT, 'input' => 'text'],
        'not_contains' => ['label' => 'Tidak boleh berisi teks', 'types' => self::TEXT, 'input' => 'text'],
        'starts_with' => ['label' => 'Diawali dengan', 'types' => self::TEXT, 'input' => 'text'],
        'pattern' => ['label' => 'Cocok dengan pola (regex)', 'types' => self::TEXT, 'input' => 'text'],
        'min_value' => ['label' => 'Nilai minimal', 'types' => ['number', 'currency'], 'input' => 'number'],
        'max_value' => ['label' => 'Nilai maksimal', 'types' => ['number', 'currency'], 'input' => 'number'],
        'after_date' => ['label' => 'Tanggal paling awal', 'types' => ['date'], 'input' => 'date'],
        'before_date' => ['label' => 'Tanggal paling akhir', 'types' => ['date'], 'input' => 'date'],
        'min_selected' => ['label' => 'Minimal jumlah pilihan', 'types' => ['multiple'], 'input' => 'number'],
        'max_selected' => ['label' => 'Maksimal jumlah pilihan', 'types' => ['multiple'], 'input' => 'number'],
    ];

    public const MAX_RULES = 10;

    public const MAX_CHECKS = 10;

    /**
     * Consistency check levels: an error must be fixed, a warning may be confirmed by the respondent.
     */
    public const CHECK_LEVELS = ['error' => 'Galat', 'warning' => 'Peringatan'];

    /**
     * Consistency checks of a question whose conditions hold for these answers, i.e. the inconsistencies
     * (e.g. "jenis kelamin = Laki-laki dan sedang hamil = Ya").
     *
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $known  answers of the visible questions
     * @return list<array<string, mixed>>
     */
    public static function failedChecks(array $question, array $known): array
    {
        return array_values(array_filter(
            $question['validation']['checks'] ?? [],
            fn (array $check): bool => SurveyLogic::evaluate($check['conditions'] ?? null, $known),
        ));
    }

    /**
     * @return array<string, array{label: string, types: list<string>, input: string}>
     */
    public static function rulesFor(string $type): array
    {
        return array_filter(self::RULES, fn (array $rule): bool => in_array($type, $rule['types'], true));
    }

    /**
     * Why a rule definition is unusable, or null when it is fine.
     */
    public static function definitionProblem(string $type, string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return 'belum memiliki nilai';
        }

        return match (self::RULES[$type]['input']) {
            'number' => is_numeric($value) ? null : 'nilainya harus angka',
            'date' => self::date($value) ? null : 'nilainya harus tanggal (YYYY-MM-DD)',
            default => $type === 'pattern' && @preg_match('/'.str_replace('/', '\/', $value).'/u', '') === false ? 'polanya tidak valid' : null,
        };
    }

    /**
     * The message for the first rule the answer breaks, or null when it passes every rule.
     *
     * @param  array<string, mixed>  $question
     */
    public static function failure(array $question, mixed $answer): ?string
    {
        $validation = $question['validation'] ?? [];

        foreach ($validation['rules'] ?? [] as $rule) {
            if (! self::passes($rule['type'], (string) $rule['value'], $answer)) {
                return trim((string) ($validation['message'] ?? '')) ?: self::defaultMessage($rule['type'], (string) $rule['value']);
            }
        }

        return null;
    }

    public static function passes(string $type, string $value, mixed $answer): bool
    {
        if ($answer === null || $answer === '' || $answer === []) {
            return true;
        }

        $text = is_array($answer) ? '' : (string) $answer;
        $length = mb_strlen($text);

        return match ($type) {
            'min_length' => $length >= (int) $value,
            'max_length' => $length <= (int) $value,
            'contains' => str_contains(mb_strtolower($text), mb_strtolower($value)),
            'not_contains' => ! str_contains(mb_strtolower($text), mb_strtolower($value)),
            'starts_with' => str_starts_with(mb_strtolower($text), mb_strtolower($value)),
            'pattern' => (bool) @preg_match('/'.str_replace('/', '\/', $value).'/u', $text),
            'min_value' => is_numeric($text) && (float) $text >= (float) $value,
            'max_value' => is_numeric($text) && (float) $text <= (float) $value,
            'after_date' => ($date = self::date($text)) !== null && $date->gte(self::date($value)),
            'before_date' => ($date = self::date($text)) !== null && $date->lte(self::date($value)),
            'min_selected' => count((array) $answer) >= (int) $value,
            'max_selected' => count((array) $answer) <= (int) $value,
            default => true,
        };
    }

    public static function defaultMessage(string $type, string $value): string
    {
        return match ($type) {
            'min_length' => "Minimal {$value} karakter.",
            'max_length' => "Maksimal {$value} karakter.",
            'contains' => "Harus berisi \"{$value}\".",
            'not_contains' => "Tidak boleh berisi \"{$value}\".",
            'starts_with' => "Harus diawali dengan \"{$value}\".",
            'pattern' => 'Format isian tidak sesuai.',
            'min_value' => "Nilai minimal {$value}.",
            'max_value' => "Nilai maksimal {$value}.",
            'after_date' => 'Tanggal paling awal '.self::date($value)?->translatedFormat('d F Y').'.',
            'before_date' => 'Tanggal paling akhir '.self::date($value)?->translatedFormat('d F Y').'.',
            'min_selected' => "Pilih minimal {$value} jawaban.",
            'max_selected' => "Pilih maksimal {$value} jawaban.",
            default => 'Isian tidak valid.',
        };
    }

    /**
     * @param  array<string, mixed>  $question
     * @return list<string>
     */
    public static function describe(array $question, array $numbers = []): array
    {
        $rules = array_map(
            fn (array $rule): string => mb_strtolower(self::RULES[$rule['type']]['label']).': '.$rule['value'],
            $question['validation']['rules'] ?? [],
        );

        foreach ($question['validation']['checks'] ?? [] as $check) {
            $condition = SurveyLogic::describe(['conditions' => $check['conditions'], 'actions' => []], $numbers);
            // "Jika … maka " becomes "jika …"; answer values keep their case.
            $condition = (string) preg_replace(['/^Jika /', '/ maka $/'], ['jika ', ''], (string) $condition);
            $rules[] = mb_strtolower(self::CHECK_LEVELS[$check['level']]).' '.$condition.': '.$check['message'];
        }

        return $rules;
    }

    private static function date(string $value): ?Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
