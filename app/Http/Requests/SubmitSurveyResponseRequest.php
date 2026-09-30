<?php

namespace App\Http\Requests;

use App\Models\Survey;
use App\Support\SurveyLogic;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SubmitSurveyResponseRequest extends FormRequest
{
    /**
     * @var array<string, true>|null
     */
    private ?array $visibleQuestionIds = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    private function survey(): Survey
    {
        return $this->route('survey');
    }

    /**
     * Rupiah answers arrive formatted ("1.500.000"); keep only their digits.
     */
    protected function prepareForValidation(): void
    {
        $answers = (array) $this->input('answers', []);

        foreach ($this->survey()->questions() as $question) {
            if ($question['type'] === 'currency' && is_string($answers[$question['id']] ?? null)) {
                $answers[$question['id']] = preg_replace('/\D/', '', $answers[$question['id']]);
            }
        }

        $this->merge(['answers' => $answers]);
    }

    /**
     * Questions hidden by the form's logic are neither validated nor stored.
     *
     * @return array<string, true>
     */
    private function visibleQuestionIds(): array
    {
        return $this->visibleQuestionIds ??= array_fill_keys(
            SurveyLogic::visibleQuestionIds($this->survey()->blocks, (array) $this->input('answers', [])),
            true,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function visibleQuestions(): array
    {
        return array_values(array_filter(
            $this->survey()->questions(),
            fn (array $question): bool => isset($this->visibleQuestionIds()[$question['id']]),
        ));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = ['answers' => ['nullable', 'array']];

        foreach ($this->visibleQuestions() as $question) {
            $key = 'answers.'.$question['id'];
            $presence = $question['required'] ? 'required' : 'nullable';

            if ($question['type'] === 'region') {
                $rules[$key] = ['nullable', 'array'];

                foreach (Survey::regionFields($question) as $field) {
                    if ($field['mode'] === 'input') {
                        $rules["{$key}.{$field['key']}"] = $field['key'] === 'klasifikasi'
                            ? [$presence, 'string', Rule::in(Survey::REGION_CLASSIFICATIONS)]
                            : [$presence, 'string', 'max:255'];
                    }
                }

                continue;
            }

            if ($question['type'] === 'table') {
                $table = $question['table'];
                $rowLimit = $table['mode'] === 'fixed' ? count($table['rows']) : $table['max_rows'];
                $rules[$key] = ['nullable', 'array', 'max:'.$rowLimit];
                $rules["{$key}.*"] = ['nullable', 'array'];

                foreach ($table['columns'] as $column) {
                    $rules["{$key}.*.{$column['id']}"] = match ($column['type']) {
                        'number' => ['nullable', 'numeric'],
                        'date' => ['nullable', 'date'],
                        'choice' => ['nullable', 'string', Rule::in($column['options'])],
                        default => ['nullable', 'string', 'max:255'],
                    };
                }

                continue;
            }

            $rules[$key] = match ($question['type']) {
                'long' => [$presence, 'string', 'max:5000'],
                'number' => [$presence, 'numeric'],
                'currency' => [$presence, 'digits_between:1,18'],
                'date' => [$presence, 'date'],
                'email' => [$presence, 'email', 'max:160'],
                'phone' => [$presence, 'regex:/^[0-9+\-\s().]{6,20}$/'],
                'code' => $question['code_length'] > 0
                    ? [$presence, 'digits:'.$question['code_length']]
                    : [$presence, 'string', 'max:50'],
                'single', 'dropdown' => [$presence, 'string', Rule::in($question['options'])],
                'multiple' => [$presence, 'array', ...($question['required'] ? ['min:1'] : [])],
                default => [$presence, 'string', 'max:255'],
            };

            if ($question['type'] === 'multiple') {
                $rules[$key.'.*'] = ['string', Rule::in($question['options'])];
            }
        }

        return $rules;
    }

    /**
     * A required table needs at least one filled cell.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach ($this->visibleQuestions() as $question) {
                    if ($question['type'] === 'table' && $question['required'] && $this->tableRows($question) === []) {
                        $validator->errors()->add('answers.'.$question['id'], $question['label'].' wajib diisi minimal satu baris.');
                    }
                }
            },
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach ($this->survey()->questions() as $question) {
            $key = 'answers.'.$question['id'];
            $attributes[$key] = $question['label'];

            foreach (Survey::regionFields($question) as $field) {
                $attributes["{$key}.{$field['key']}"] = $field['label'];
            }

            foreach ($question['table']['columns'] ?? [] as $column) {
                $attributes["{$key}.*.{$column['id']}"] = $question['label'].' – '.$column['label'];
            }
        }

        return $attributes;
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'min' => ':attribute wajib diisi.',
            'numeric' => ':attribute harus berupa angka.',
            'date' => ':attribute harus berupa tanggal yang valid.',
            'digits' => ':attribute harus berisi :digits digit angka.',
            'digits_between' => ':attribute harus berupa nominal Rupiah yang valid.',
            'email' => ':attribute harus berupa alamat email yang valid.',
            'regex' => ':attribute harus berupa nomor telepon yang valid.',
            'in' => 'Pilihan untuk :attribute tidak valid.',
            'max' => ':attribute terlalu panjang.',
            'string' => ':attribute tidak valid.',
            'array' => ':attribute tidak valid.',
        ];
    }

    /**
     * The visible, non-empty answers keyed by question id.
     *
     * @return array<string, mixed>
     */
    public function answers(): array
    {
        $submitted = (array) $this->input('answers', []);
        $answers = [];

        foreach ($this->visibleQuestions() as $question) {
            $answer = match ($question['type']) {
                'region' => $this->regionAnswer($question, (array) ($submitted[$question['id']] ?? [])),
                'table' => $this->tableRows($question),
                'multiple' => array_values(array_intersect($question['options'], (array) ($submitted[$question['id']] ?? []))),
                default => trim((string) ($submitted[$question['id']] ?? '')),
            };

            if ($answer !== '' && $answer !== []) {
                $answers[$question['id']] = $answer;
            }
        }

        return $answers;
    }

    /**
     * Fixed region values are stored alongside what the respondent typed.
     *
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $submitted
     * @return array<string, string>
     */
    private function regionAnswer(array $question, array $submitted): array
    {
        $answer = [];
        $hasInput = false;

        foreach (Survey::regionFields($question) as $field) {
            $value = $field['mode'] === 'fixed'
                ? Survey::fixedRegionValue($field)
                : trim((string) ($submitted[$field['key']] ?? ''));
            $hasInput = $hasInput || ($field['mode'] === 'input' && $value !== '');

            if ($value !== '') {
                $answer[$field['key']] = $value;
            }
        }

        $hasInputFields = collect(Survey::regionFields($question))->contains('mode', 'input');

        return $hasInput || ! $hasInputFields ? $answer : [];
    }

    /**
     * Table rows with trimmed cells. Fixed tables keep one entry per named row; empty dynamic rows are dropped.
     *
     * @param  array<string, mixed>  $question
     * @return list<array<string, string>>
     */
    private function tableRows(array $question): array
    {
        $submitted = array_values((array) ($this->input('answers.'.$question['id']) ?? []));
        $table = $question['table'];
        $rowCount = $table['mode'] === 'fixed' ? count($table['rows']) : count($submitted);
        $rows = [];
        $hasValue = false;

        for ($index = 0; $index < $rowCount; $index++) {
            $cells = is_array($submitted[$index] ?? null) ? $submitted[$index] : [];
            $row = [];

            foreach ($table['columns'] as $column) {
                $cell = $cells[$column['id']] ?? '';
                $row[$column['id']] = is_scalar($cell) ? trim((string) $cell) : '';
            }

            $rowHasValue = implode('', $row) !== '';
            $hasValue = $hasValue || $rowHasValue;

            if ($table['mode'] === 'fixed' || $rowHasValue) {
                $rows[] = $row;
            }
        }

        return $hasValue ? $rows : [];
    }
}
