<?php

namespace App\Support;

use App\Http\Requests\StoreSurveyRequest;
use App\Models\Survey;
use App\Models\SurveyResponse;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * The JSON exchange file: the questionnaire, its validation rules, its responses and kop logos.
 * The same file can be imported as a new questionnaire or used as a template to validate data.
 */
class SurveyJson
{
    public const FORMAT = 'ruang-kuesioner';

    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function export(Survey $survey): array
    {
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => now()->toIso8601String(),
            'survey' => self::definitionData($survey),
            'validation_rules' => self::rulesSummary($survey),
            'responses' => $survey->responses()->oldest()->get()->map(fn (SurveyResponse $response): array => [
                'id' => $response->id,
                'submitted_at' => $response->created_at->toIso8601String(),
                'answers' => $response->answers,
                ...($response->flags ? ['flags' => $response->flags] : []),
            ])->all(),
            'assets' => ['logos' => self::logoAssets($survey)],
        ];
    }

    /**
     * The questionnaire itself, as the builder saves it.
     *
     * @return array<string, mixed>
     */
    public static function definitionData(Survey $survey): array
    {
        return [
            'title' => $survey->title,
            'form_code' => $survey->form_code,
            'subtitle' => $survey->subtitle,
            'activity' => $survey->activity,
            'settings' => $survey->formSettings(),
            'header' => ['align' => $survey->headerAlign(), 'items' => $survey->headerItems()],
            'intro' => $survey->formIntro(),
            'blocks' => $survey->blocks,
        ];
    }

    /**
     * One response for other systems (API and webhooks).
     *
     * @return array<string, mixed>
     */
    public static function responseData(SurveyResponse $response): array
    {
        return [
            'id' => $response->id,
            'submitted_at' => $response->created_at?->toIso8601String(),
            'source' => $response->source ?? 'form',
            'assignment_id' => $response->assignment?->id,
            'answers' => $response->answers ?? (object) [],
            'flags' => $response->flags,
        ];
    }

    /**
     * Readable validation rules per question, for people and other systems reading the file.
     *
     * @return list<array<string, mixed>>
     */
    public static function rulesSummary(Survey $survey): array
    {
        $numbers = $survey->questionNumbers();

        return array_map(function (array $question) use ($numbers): array {
            $rules = array_values(array_filter([
                $question['required'] ? 'wajib diisi' : 'boleh kosong',
                $question['hidden'] ? 'tersembunyi' : null,
                $question['disabled'] ? 'tidak dapat diubah responden' : null,
                $question['prefill'] !== '' ? 'nilai awal: '.$question['prefill'] : null,
                $question['max_length'] ? "maksimal {$question['max_length']} karakter" : null,
                ($question['mask'] ?? '') !== '' ? "format {$question['mask']}" : null,
                ! empty($question['in_roster']) ? 'diisi per rincian (daftar jawaban per baris)' : null,
            ]));

            $rules[] = match ($question['type']) {
                'short' => 'teks maksimal 255 karakter',
                'long' => 'teks maksimal 5000 karakter',
                'number' => 'angka',
                'currency' => 'nominal rupiah (angka bulat, tanpa titik)',
                'date' => 'tanggal (YYYY-MM-DD)',
                'email' => 'alamat email',
                'phone' => 'nomor telepon 6–20 karakter (angka, +, -, spasi, tanda kurung)',
                'url' => 'tautan http/https maksimal 500 karakter',
                'checkbox' => 'dicentang ("'.Survey::CHECKBOX_VALUE.'") atau kosong',
                'rating', 'scale', 'slider' => "bilangan bulat {$question['range']['min']}–{$question['range']['max']}".($question['range']['step'] > 1 ? " kelipatan {$question['range']['step']}" : ''),
                'matrix' => 'per baris ('.implode(' | ', $question['matrix']['rows']).') salah satu dari: '.implode(' | ', $question['matrix']['columns']),
                'signature' => 'gambar tanda tangan PNG (data URL)',
                'time' => 'jam (JJ:MM)',
                'location' => 'koordinat "lintang, bujur" dalam derajat desimal'.(($question['location']['max_accuracy'] ?? null) ? ", akurasi GPS paling banyak {$question['location']['max_accuracy']} m (dikirim sebagai {$question['id']}".Survey::ACCURACY_SUFFIX.')' : ''),
                'file' => 'berkas '.implode('/', Survey::fileExtensions($question)).' maksimal '.($question['file']['max_mb'] ?? 5).' MB',
                'list' => 'daftar nama/isian, maksimal '.($question['list']['max'] ?? 10),
                'calc' => 'dihitung otomatis: '.Piping::text((string) ($question['formula'] ?? ''), $numbers),
                'code' => $question['code_length'] > 0 ? "tepat {$question['code_length']} digit angka" : 'kode maksimal 50 karakter',
                'dropdown' => Lookup::isLookup($question)
                    ? 'kode dari '.(($question['lookup']['source'] ?? '') === 'api' ? 'API '.($question['lookup']['url'] ?? '') : 'data referensi "'.($question['lookup']['reference'] ?? '').'" kolom '.($question['lookup']['value_column'] ?? '')).(($question['lookup']['depends_on'] ?? '') !== '' ? ', disaring menurut jawaban '.($numbers[$question['lookup']['depends_on']] ?? '') : '')
                    : (($question['options_from'] ?? '') !== ''
                        ? 'salah satu jawaban pertanyaan '.($numbers[$question['options_from']] ?? '')
                        : 'salah satu dari: '.implode(' | ', [...$question['options'], ...(Survey::hasOtherOption($question) ? [Survey::OTHER_VALUE.' (sebutkan)'] : [])])),
                'single' => ($question['options_from'] ?? '') !== ''
                    ? 'salah satu jawaban pertanyaan '.($numbers[$question['options_from']] ?? '')
                    : 'salah satu dari: '.implode(' | ', [...$question['options'], ...(Survey::hasOtherOption($question) ? [Survey::OTHER_VALUE.' (sebutkan)'] : [])]),
                'multiple' => ($question['options_from'] ?? '') !== ''
                    ? 'satu atau lebih jawaban pertanyaan '.($numbers[$question['options_from']] ?? '')
                    : 'satu atau lebih dari: '.implode(' | ', [...$question['options'], ...(Survey::hasOtherOption($question) ? [Survey::OTHER_VALUE.' (sebutkan)'] : [])]),
                'region' => 'isian wilayah: '.implode(', ', array_map(
                    fn (array $field): string => $field['label'].($field['mode'] === 'fixed' ? ' = '.Survey::fixedRegionValue($field) : ''),
                    Survey::regionFields($question),
                )),
                'table' => ($question['table']['mode'] === 'fixed'
                    ? 'tabel baris tetap: '.implode(' | ', $question['table']['rows'])
                    : "tabel maksimal {$question['table']['max_rows']} baris").'; kolom: '.implode(', ', array_map(
                        fn (array $column): string => $column['label'].' ('.Survey::TABLE_COLUMN_TYPES[$column['type']].($column['type'] === 'choice' ? ': '.implode(' | ', $column['options']) : '').')',
                        $question['table']['columns'],
                    )),
                default => $question['type'],
            };

            return [
                'number' => $numbers[$question['id']],
                'id' => $question['id'],
                'label' => $question['label'],
                'type' => $question['type'],
                'rules' => [...$rules, ...FieldValidation::describe($question, $numbers)],
                'logic' => SurveyLogic::describe($question['logic'], $numbers),
                'validation_message' => $question['validation']['message'] ?: null,
            ];
        }, $survey->questions());
    }

    /**
     * @param  array<string, mixed>  $file  a decoded export file
     */
    public static function assertExportFile(array $file): void
    {
        if (($file['format'] ?? null) !== self::FORMAT || ! is_array($file['survey'] ?? null)) {
            throw new InvalidArgumentException('File bukan hasil ekspor JSON Ruang Kuesioner.');
        }
    }

    /**
     * The builder-form payload for an exported questionnaire, so imports get the builder's validation.
     *
     * @param  array<string, mixed>  $survey
     * @return array<string, mixed>
     */
    public static function builderPayload(array $survey): array
    {
        $settings = array_merge(Survey::DEFAULT_SETTINGS, (array) ($survey['settings'] ?? []));
        $settings['confidential'] = $settings['confidential'] ? '1' : '0';

        return [
            'title' => $survey['title'] ?? null,
            'form_code' => $survey['form_code'] ?? null,
            'subtitle' => $survey['subtitle'] ?? null,
            'activity' => $survey['activity'] ?? null,
            'settings' => $settings,
            'intro' => array_merge(Survey::DEFAULT_INTRO, (array) ($survey['intro'] ?? [])),
            'header' => json_encode($survey['header'] ?? ['items' => []]),
            'blocks' => json_encode($survey['blocks'] ?? []),
        ];
    }

    /**
     * Validates an exported questionnaire with the builder's own rules and returns storable attributes.
     *
     * @param  array<string, mixed>  $survey
     * @return array<string, mixed>
     */
    public static function definition(array $survey): array
    {
        $request = StoreSurveyRequest::create('/', 'POST', self::builderPayload($survey));
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();

        return $request->surveyData();
    }

    /**
     * An unsaved survey built from an export file, used as the template when validating data.
     *
     * @param  array<string, mixed>  $file
     */
    public static function templateSurvey(array $file): Survey
    {
        self::assertExportFile($file);

        return new Survey(self::definition($file['survey']));
    }

    /**
     * Writes the logos embedded in an export file that this installation does not have yet.
     *
     * @param  array<string, mixed>  $file
     */
    public static function restoreLogos(array $file): void
    {
        foreach ((array) ($file['assets']['logos'] ?? []) as $path => $dataUri) {
            if (! is_string($path) || ! preg_match('/^logos\/[A-Za-z0-9_-]+\.(png|jpe?g|webp)$/', $path) || Storage::disk('public')->exists($path)) {
                continue;
            }
            if (! is_string($dataUri) || ! preg_match('/^data:image\/(png|jpeg|webp);base64,(.+)$/', $dataUri, $match)) {
                continue;
            }

            $contents = base64_decode($match[2], true);
            // Only real images are written back, whatever the file claims to be.
            if ($contents !== false && strlen($contents) <= 2 * 1024 * 1024 && @getimagesizefromstring($contents)) {
                Storage::disk('public')->put($path, $contents);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private static function logoAssets(Survey $survey): array
    {
        $assets = [];

        foreach ($survey->headerItems() as $item) {
            $path = $item['type'] === 'logo' ? $item['path'] : null;
            if ($path && Storage::disk('public')->exists($path)) {
                $mime = Storage::disk('public')->mimeType($path) ?: 'image/png';
                $assets[$path] = 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($path));
            }
        }

        return $assets;
    }
}
