<?php

namespace App\Support;

use App\Models\Survey;

/**
 * Whether an instrument is fit to publish. Errors block publishing (the form would not work or
 * the data would be ambiguous); warnings are worth a look but may be published as they are.
 */
class SurveyCheck
{
    /**
     * @return list<array{level: string, message: string, question: ?string}>
     */
    public static function problems(Survey $survey): array
    {
        $problems = [];
        $numbers = $survey->questionNumbers();
        $add = function (string $level, string $message, ?array $question = null) use (&$problems, $numbers): void {
            $problems[] = ['level' => $level, 'message' => $message, 'question' => $question ? trim(($numbers[$question['id']] ?? '').' '.$question['label']) : null];
        };

        $questions = collect($survey->blocks)->flatMap(fn (array $block): array => $block['questions'] ?? []);
        $answerable = $questions->reject(fn (array $question): bool => $question['type'] === 'info');

        if ($answerable->isEmpty()) {
            $add('error', 'Belum ada pertanyaan yang bisa dijawab.');
        }

        foreach ($survey->blocks as $index => $block) {
            if (($block['questions'] ?? []) === []) {
                $add('warning', 'Blok '.Survey::roman($index + 1).' '.$block['title'].' belum berisi pertanyaan.');
            }
        }

        $variables = [];
        $labels = [];
        $withoutVariable = 0;
        $numbersWithoutRange = [];

        foreach ($answerable as $question) {
            $type = $question['type'];

            $variable = mb_strtoupper((string) ($question['variable'] ?? ''));
            if ($variable === '') {
                $withoutVariable++;
            } elseif (isset($variables[$variable])) {
                $add('error', "Kode variabel {$variable} dipakai lebih dari satu pertanyaan.", $question);
            }
            $variables[$variable] = true;

            if (in_array($type, Survey::CHOICE_TYPES, true) && ($question['options_from'] ?? '') === '' && ! Lookup::isLookup($question)) {
                $options = $question['options'] ?? [];
                if (count($options) < 2) {
                    $add('error', 'Pertanyaan pilihan harus punya minimal dua pilihan jawaban.', $question);
                }
                if (count($options) !== count(array_unique(array_map('mb_strtolower', $options)))) {
                    $add('error', 'Ada pilihan jawaban yang sama persis; data tidak bisa membedakannya.', $question);
                }
            }

            if ($type === 'calc' && trim((string) ($question['formula'] ?? '')) === '') {
                $add('error', 'Hitungan belum memiliki rumus.', $question);
            }

            if ($question['hidden'] && ! in_array('show', $question['logic']['actions'] ?? [], true) && ($question['prefill'] ?? '') === '') {
                $add('warning', 'Tersembunyi tanpa logika yang memunculkannya dan tanpa nilai awal, jadi tidak akan pernah terisi.', $question);
            }

            foreach ($question['validation']['checks'] ?? [] as $check) {
                if (trim((string) ($check['message'] ?? '')) === '') {
                    $add('error', 'Ada pemeriksaan konsistensi tanpa pesan untuk petugas.', $question);
                }
            }

            $label = mb_strtolower(trim(strip_tags((string) $question['label'])));
            if ($label !== '' && isset($labels[$label]) && ! self::inRoster($survey, $question['id'])) {
                $add('warning', 'Teks pertanyaan sama dengan pertanyaan '.$labels[$label].'. Pastikan memang berbeda maksudnya.', $question);
            }
            $labels[$label] ??= $numbers[$question['id']] ?? '';

            if (in_array($type, ['number', 'currency'], true)) {
                $kinds = array_column($question['validation']['rules'] ?? [], 'type');
                if (! in_array('min_value', $kinds, true) && ! in_array('max_value', $kinds, true) && ($question['validation']['checks'] ?? []) === []) {
                    $numbersWithoutRange[] = $numbers[$question['id']] ?? '';
                }
            }
        }

        if ($withoutVariable > 0) {
            $add('warning', "{$withoutVariable} pertanyaan belum memiliki kode variabel (tab Metadata); kolom datanya memakai nomor pertanyaan, yang bisa bergeser bila urutan diubah.");
        }
        if ($numbersWithoutRange !== []) {
            $add('warning', 'Isian angka tanpa batas wajar (nilai minimal/maksimal atau pemeriksaan): '.implode(', ', array_slice($numbersWithoutRange, 0, 12)).(count($numbersWithoutRange) > 12 ? ', …' : '').'.');
        }

        return $problems;
    }

    /**
     * @return list<array{level: string, message: string, question: ?string}>
     */
    public static function errors(Survey $survey): array
    {
        return array_values(array_filter(self::problems($survey), fn (array $problem): bool => $problem['level'] === 'error'));
    }

    private static function inRoster(Survey $survey, string $id): bool
    {
        foreach ($survey->blocks as $block) {
            if (SurveyLogic::repeatSource($block) !== null && in_array($id, array_column($block['questions'] ?? [], 'id'), true)) {
                return true;
            }
        }

        return false;
    }
}
