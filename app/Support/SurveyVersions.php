<?php

namespace App\Support;

use App\Models\Survey;
use App\Models\SurveyVersion;

/**
 * Instrument versions: a new one when the published instrument differs from the last, with what
 * changed (questions added, removed, reworded, options, codes, rules).
 */
class SurveyVersions
{
    /**
     * The current version of a survey, recorded first when the instrument changed since the last.
     */
    public static function record(Survey $survey): SurveyVersion
    {
        $latest = $survey->versions()->orderByDesc('number')->first();
        $blocks = $survey->blocks;

        if ($latest && $latest->title === $survey->title && $latest->blocks == $blocks) {
            return $latest;
        }

        return $survey->versions()->create([
            'number' => ($latest->number ?? 0) + 1,
            'title' => $survey->title,
            'blocks' => $blocks,
            'changes' => $latest ? self::changes($latest->blocks, $blocks) : null,
        ]);
    }

    /**
     * The number of the version new answers belong to: the latest, recorded first if needed.
     */
    public static function current(Survey $survey): int
    {
        return self::record($survey)->number;
    }

    /**
     * What differs between two instruments, question by question (matched by id, so rewording
     * keeps the identity).
     *
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @return list<array{kind: string, question: string, detail: string}>
     */
    public static function changes(array $before, array $after): array
    {
        $old = self::questions($before);
        $new = self::questions($after);
        $changes = [];

        foreach ($new as $id => $question) {
            if (! isset($old[$id])) {
                $changes[] = ['kind' => 'added', 'question' => $question['label'], 'detail' => 'Pertanyaan baru di blok '.$question['block'].'.'];

                continue;
            }

            $was = $old[$id];
            $removed = array_diff($was['options'] ?? [], $question['options'] ?? []);
            $added = array_diff($question['options'] ?? [], $was['options'] ?? []);
            $details = array_filter([
                $was['label'] !== $question['label'] ? 'teks diubah (sebelumnya “'.$was['label'].'”)' : null,
                $was['type'] !== $question['type'] ? 'jenis isian '.(Survey::QUESTION_TYPES[$was['type']] ?? $was['type']).' → '.(Survey::QUESTION_TYPES[$question['type']] ?? $question['type']) : null,
                $removed !== [] ? 'pilihan dihapus: '.implode(', ', $removed) : null,
                $added !== [] ? 'pilihan ditambah: '.implode(', ', $added) : null,
                ($was['variable'] ?? '') !== ($question['variable'] ?? '') ? 'kode variabel '.(($was['variable'] ?? '') ?: '—').' → '.(($question['variable'] ?? '') ?: '—') : null,
                ($was['required'] ?? false) !== ($question['required'] ?? false) ? (($question['required'] ?? false) ? 'menjadi wajib' : 'tidak lagi wajib') : null,
                ($was['validation'] ?? null) != ($question['validation'] ?? null) ? 'aturan validasi diubah' : null,
                ($was['logic'] ?? null) != ($question['logic'] ?? null) ? 'logika diubah' : null,
                ($was['unit'] ?? '') !== ($question['unit'] ?? '') ? 'satuan diubah' : null,
            ]);
            if ($details !== []) {
                $changes[] = ['kind' => 'changed', 'question' => $question['label'], 'detail' => ucfirst(implode('; ', $details)).'.'];
            }
        }

        foreach (array_diff_key($old, $new) as $question) {
            $changes[] = ['kind' => 'removed', 'question' => $question['label'], 'detail' => 'Dihapus dari blok '.$question['block'].'. Jawaban lama tetap tersimpan.'];
        }

        return $changes;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return array<string, array<string, mixed>>
     */
    private static function questions(array $blocks): array
    {
        $questions = [];
        foreach ($blocks as $index => $block) {
            foreach ($block['questions'] ?? [] as $question) {
                if (($question['type'] ?? '') !== 'info') {
                    $questions[$question['id']] = $question + ['block' => Survey::roman($index + 1).' '.($block['title'] ?? '')];
                }
            }
        }

        return $questions;
    }
}
