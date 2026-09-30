<?php

namespace App\Http\Controllers;

use App\Models\Survey;
use App\Support\ResponseValidator;
use App\Support\SurveyJson;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Uses an exported JSON file as a validation template: every record is checked with the
 * questionnaire's own rules and the problems are listed per record.
 */
class DataValidationController extends Controller
{
    public function create(): View
    {
        return view('validation.index', ['result' => null]);
    }

    public function store(Request $request): View
    {
        $request->validate([
            'template' => ['required', 'file', 'extensions:json', 'max:20480'],
            'data' => ['nullable', 'file', 'extensions:json', 'max:20480'],
        ], [
            'template.required' => 'Pilih file JSON template (hasil ekspor kuesioner).',
            'template.extensions' => 'Template harus berformat .json.',
            'data.extensions' => 'File data harus berformat .json.',
            '*.max' => 'Ukuran file maksimal 20 MB.',
        ]);

        try {
            $template = $this->decode($request->file('template'), 'Template');
            $survey = SurveyJson::templateSurvey($template);
            $records = $this->records($request->file('data') ? $this->decode($request->file('data'), 'File data') : $template);
        } catch (InvalidArgumentException $failure) {
            return view('validation.index', ['result' => null])->withErrors(['template' => $failure->getMessage()]);
        } catch (ValidationException $failure) {
            return view('validation.index', ['result' => null])
                ->withErrors(['template' => 'Kuesioner di dalam template tidak valid: '.collect($failure->errors())->flatten()->first()]);
        }

        return view('validation.index', ['result' => $this->check($survey, $records, $request->file('data') !== null)]);
    }

    /**
     * @return array<mixed>
     */
    private function decode(UploadedFile $file, string $name): array
    {
        $decoded = json_decode((string) $file->get(), true);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException("{$name} bukan JSON yang valid.");
        }

        return $decoded;
    }

    /**
     * Accepts an export file, {"responses": [...]}, or a plain list of records.
     * Each record is either {"answers": {...}} or the answers object itself.
     *
     * @param  array<mixed>  $data
     * @return list<array{label: string, answers: array<string, mixed>}>
     */
    private function records(array $data): array
    {
        $items = match (true) {
            array_is_list($data) => $data,
            is_array($data['responses'] ?? null) => $data['responses'],
            default => throw new InvalidArgumentException('Format data tidak dikenali. Gunakan file ekspor JSON, {"responses": [...]}, atau daftar record.'),
        };

        return array_map(fn ($item, int $index): array => [
            'label' => is_array($item) && isset($item['id']) && is_scalar($item['id']) ? 'ID '.$item['id'] : 'Record '.($index + 1),
            'answers' => is_array($item) ? (is_array($item['answers'] ?? null) ? $item['answers'] : $item) : [],
        ], $items, array_keys($items));
    }

    /**
     * @param  list<array{label: string, answers: array<string, mixed>}>  $records
     * @return array<string, mixed>
     */
    private function check(Survey $survey, array $records, bool $externalData): array
    {
        $numbers = $survey->questionNumbers();
        $idsByNumber = array_flip($numbers);
        $labels = collect($survey->questions())->pluck('label', 'id')->all();
        $results = [];

        foreach ($records as $record) {
            // Records may use question ids ("q-nama") or printed numbers ("101") as keys.
            $answers = [];
            $unknown = [];
            foreach ($record['answers'] as $key => $value) {
                $id = isset($numbers[$key]) ? $key : ($idsByNumber[(string) $key] ?? null);
                if ($id === null) {
                    $unknown[] = (string) $key;

                    continue;
                }
                $answers[$id] = $value;
            }

            $outcome = ResponseValidator::check($survey, $answers);
            $results[] = [
                'label' => $record['label'],
                'errors' => array_map(fn (array $error): array => [
                    'number' => $numbers[$error['question_id']] ?? '—',
                    'question' => $labels[$error['question_id']] ?? '',
                    'message' => $error['message'],
                ], $outcome['errors']),
                'unknown' => $unknown,
            ];
        }

        $invalid = count(array_filter($results, fn (array $result): bool => $result['errors'] !== []));

        return [
            'survey' => $survey,
            'source' => $externalData ? 'file data' : 'respons di dalam template',
            'total' => count($results),
            'invalid' => $invalid,
            'valid' => count($results) - $invalid,
            'records' => $results,
        ];
    }
}
