<?php

namespace App\Http\Requests;

use App\Models\Survey;
use App\Support\FieldValidation;
use App\Support\SurveyLogic;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SubmitSurveyResponseRequest extends FormRequest
{
    /**
     * @var array{states: array<string, array{hidden: bool, disabled: bool, required: bool}>, known: array<string, mixed>, computed: array<string, string>}|null
     */
    private ?array $analysis = null;

    /**
     * Paths of the files stored by answers(), so a request stores each upload once.
     *
     * @var array<string, string>
     */
    private array $storedFiles = [];

    /**
     * Consistency warnings the respondent confirmed, stored with the response.
     *
     * @var list<array<string, string>>
     */
    private array $confirmedWarnings = [];

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
     * Hidden, disabled and required state of every question after applying the form's logic.
     *
     * @return array<string, array{hidden: bool, disabled: bool, required: bool}>
     */
    private function states(): array
    {
        return $this->analysis()['states'];
    }

    /**
     * @return array{states: array<string, array{hidden: bool, disabled: bool, required: bool}>, known: array<string, mixed>, computed: array<string, string>}
     */
    private function analysis(): array
    {
        return $this->analysis ??= SurveyLogic::analyse($this->survey()->blocks, $this->submittedAnswers());
    }

    /**
     * The submitted answers, uploaded files included.
     *
     * @return array<string, mixed>
     */
    private function submittedAnswers(): array
    {
        return (array) ($this->all()['answers'] ?? []);
    }

    /**
     * Questions the respondent can see and change; only these are validated and taken from the request.
     *
     * @return list<array<string, mixed>>
     */
    private function editableQuestions(): array
    {
        return array_values(array_filter(
            $this->survey()->questions(),
            fn (array $question): bool => ! ($this->states()[$question['id']]['hidden'] ?? true) && ! $this->states()[$question['id']]['disabled']
                && ! in_array($question['type'], Survey::COMPUTED_TYPES, true),
        ));
    }

    /**
     * @param  array<string, mixed>  $question
     */
    private function isRequired(array $question): bool
    {
        return $this->states()[$question['id']]['required'] ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = ['answers' => ['nullable', 'array']];

        foreach ($this->editableQuestions() as $question) {
            $key = 'answers.'.$question['id'];
            $presence = $this->isRequired($question) ? 'required' : 'nullable';

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

            if ($question['type'] === 'matrix') {
                $rules[$key] = ['nullable', 'array'];
                $rules["{$key}.*"] = ['nullable', 'string', Rule::in($question['matrix']['columns'])];

                continue;
            }

            if (in_array($question['type'], Survey::RANGE_TYPES, true)) {
                $range = $question['range'];
                $rules[$key] = [$presence, 'integer', "between:{$range['min']},{$range['max']}", $this->stepRule($question)];

                continue;
            }

            $maxLength = fn (int $limit): string => 'max:'.min($limit, $question['max_length'] ?: $limit);
            $options = Survey::optionsFor($question, $this->analysis()['known']);
            if (Survey::hasOtherOption($question)) {
                $options[] = Survey::OTHER_VALUE;
                $rules["{$key}".Survey::OTHER_SUFFIX] = ['nullable', 'string', 'max:255'];
            }

            $rules[$key] = match ($question['type']) {
                'long' => [$presence, 'string', $maxLength(5000)],
                'url' => [$presence, 'url:http,https', 'max:500'],
                'checkbox' => [$presence, 'string', Rule::in([Survey::CHECKBOX_VALUE])],
                'signature' => [$presence, 'string', 'max:'.Survey::MAX_SIGNATURE_LENGTH, $this->signatureRule($question)],
                'number' => [$presence, 'numeric'],
                'currency' => [$presence, 'digits_between:1,18'],
                'date' => [$presence, 'date'],
                'time' => [$presence, 'date_format:H:i'],
                'location' => [$presence, 'string', 'max:80', $this->locationRule($question)],
                'file' => [$presence, $this->fileRule($question)],
                'email' => [$presence, 'email', 'max:160'],
                'phone' => [$presence, 'regex:/^[0-9+\-\s().]{6,20}$/'],
                'code' => $question['code_length'] > 0
                    ? [$presence, 'digits:'.$question['code_length']]
                    : [$presence, 'string', 'max:50'],
                'single', 'dropdown' => [$presence, 'string', Rule::in($options)],
                'short' => [$presence, 'string', $maxLength(255), ...($question['mask'] !== '' ? [$this->maskRule($question)] : [])],
                'multiple' => [$presence, 'array', ...($this->isRequired($question) ? ['min:1'] : [])],
                default => [$presence, 'string', $maxLength(255)],
            };

            if ($question['type'] === 'multiple') {
                $rules[$key.'.*'] = ['string', Rule::in($options)];
            }
        }

        return $rules;
    }

    /**
     * Range answers must sit on one of the steps the question offers.
     *
     * @param  array<string, mixed>  $question
     */
    private function stepRule(array $question): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($question): void {
            $range = $question['range'];

            if (is_numeric($value) && ((int) $value - $range['min']) % $range['step'] !== 0) {
                $fail("{$question['text']} harus kelipatan {$range['step']} dari {$range['min']}.");
            }
        };
    }

    /**
     * Masked answers (e.g. "99.99.999") must match their mask exactly.
     *
     * @param  array<string, mixed>  $question
     */
    private function maskRule(array $question): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($question): void {
            if (is_string($value) && ! preg_match(Survey::maskPattern($question['mask']), $value)) {
                $fail("{$question['text']} harus sesuai format {$question['mask']}.");
            }
        };
    }

    /**
     * A location is "latitude, longitude" in decimal degrees.
     *
     * @param  array<string, mixed>  $question
     */
    private function locationRule(array $question): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($question): void {
            if (! is_string($value) || ! preg_match('/^(-?\d{1,2}(?:\.\d+)?),\s*(-?\d{1,3}(?:\.\d+)?)$/', trim($value), $match)
                || abs((float) $match[1]) > 90 || abs((float) $match[2]) > 180) {
                $fail("{$question['text']} harus berupa koordinat \"lintang, bujur\", mis. -7.0321, 112.7464.");
            }
        };
    }

    /**
     * An upload of an accepted kind and size, or a file already stored for this survey.
     *
     * @param  array<string, mixed>  $question
     */
    private function fileRule(array $question): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($question): void {
            if (Survey::isUploadPath($value) && str_starts_with($value, 'uploads/'.$this->survey()->id.'/')) {
                return;
            }
            if (! $value instanceof UploadedFile || ! $value->isValid()) {
                $fail("{$question['text']} gagal diunggah. Pilih berkasnya lagi.");

                return;
            }

            $extensions = Survey::fileExtensions($question);
            $extension = strtolower($value->getClientOriginalExtension());
            $maxMb = $question['file']['max_mb'] ?? 5;

            if (! in_array($extension, $extensions, true) || ! in_array(strtolower((string) $value->guessExtension()), [...$extensions, 'jpg', 'bin', 'zip', 'txt', 'xml'], true)) {
                $fail("{$question['text']} harus berupa berkas ".implode(', ', $extensions).'.');
            } elseif ($value->getSize() > $maxMb * 1024 * 1024) {
                $fail("{$question['text']} maksimal {$maxMb} MB.");
            }
        };
    }

    /**
     * @param  array<string, mixed>  $question
     */
    private function signatureRule(array $question): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($question): void {
            if (! Survey::isSignatureImage($value)) {
                $fail("{$question['text']} tidak terbaca. Ulangi tanda tangan.");
            }
        };
    }

    /**
     * Required tables need a filled cell and required matrices an answer on every row;
     * the builder's "Validasi" rules run once the basics pass.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach ($this->editableQuestions() as $question) {
                    $key = 'answers.'.$question['id'];

                    if ($question['type'] === 'table' && $this->isRequired($question) && $this->tableRows($question) === []) {
                        $validator->errors()->add($key, $question['text'].' wajib diisi minimal satu baris.');
                    }

                    if ($question['type'] === 'matrix' && $this->isRequired($question) && in_array('', $this->matrixAnswer($question) ?: [''], true)) {
                        $validator->errors()->add($key, $question['text'].' wajib diisi pada setiap baris.');
                    }

                    if ($validator->errors()->has($key) || $validator->errors()->has($key.'.*')) {
                        continue;
                    }

                    if (Survey::hasOtherOption($question) && in_array(Survey::OTHER_VALUE, (array) $this->input($key), true)
                        && trim((string) $this->input($key.Survey::OTHER_SUFFIX)) === '') {
                        $validator->errors()->add($key, 'Sebutkan jawaban "'.Survey::OTHER_VALUE.'" untuk '.$question['text'].'.');

                        continue;
                    }

                    if ($message = FieldValidation::failure($question, $this->input($key))) {
                        $validator->errors()->add($key, $message);

                        continue;
                    }

                    $this->checkConsistency($validator, $question);
                }
            },
        ];
    }

    /**
     * Consistency checks (e.g. a man who is pregnant): errors must be fixed, warnings must be confirmed.
     *
     * @param  array<string, mixed>  $question
     */
    private function checkConsistency(Validator $validator, array $question): void
    {
        $confirmations = (array) $this->input('confirmations', []);

        foreach (FieldValidation::failedChecks($question, $this->analysis()['known']) as $check) {
            if ($check['level'] === 'warning' && ! empty($confirmations[$check['id']])) {
                $this->confirmedWarnings[] = ['question_id' => $question['id'], 'check_id' => $check['id'], 'message' => $check['message']];

                continue;
            }

            $validator->errors()->add(
                'answers.'.$question['id'],
                $check['level'] === 'warning' ? 'Peringatan: '.$check['message'] : $check['message'],
            );
        }
    }

    /**
     * Warnings the respondent confirmed, to keep with the response for later review.
     *
     * @return array{confirmed_warnings: list<array<string, string>>}|null
     */
    public function flags(): ?array
    {
        return $this->confirmedWarnings === [] ? null : ['confirmed_warnings' => $this->confirmedWarnings];
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
            $attributes[$key] = $question['text'];

            foreach (Survey::regionFields($question) as $field) {
                $attributes["{$key}.{$field['key']}"] = $field['label'];
            }

            foreach ($question['table']['columns'] ?? [] as $column) {
                $attributes["{$key}.*.{$column['id']}"] = $question['text'].' – '.$column['label'];
            }

            foreach ($question['matrix']['rows'] ?? [] as $index => $row) {
                $attributes["{$key}.{$index}"] = $question['text'].' – '.$row;
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
            'integer' => ':attribute harus berupa bilangan bulat.',
            'between' => ':attribute harus di antara :min dan :max.',
            'url' => ':attribute harus berupa tautan yang diawali http:// atau https://.',
            'date' => ':attribute harus berupa tanggal yang valid.',
            'digits' => ':attribute harus berisi :digits digit angka.',
            'digits_between' => ':attribute harus berupa nominal Rupiah yang valid.',
            'email' => ':attribute harus berupa alamat email yang valid.',
            'regex' => ':attribute harus berupa nomor telepon yang valid.',
            'in' => 'Pilihan untuk :attribute tidak valid.',
            'max' => ':attribute terlalu panjang (maksimal :max karakter).',
            'string' => ':attribute tidak valid.',
            'array' => ':attribute tidak valid.',
        ];
    }

    /**
     * The answers to store, keyed by question id: what the respondent filled in for editable questions,
     * and the pre-filled value for disabled or hidden ones.
     *
     * @return array<string, mixed>
     */
    public function answers(): array
    {
        $submitted = $this->submittedAnswers();
        $editable = array_column($this->editableQuestions(), 'id', 'id');
        $computed = $this->analysis()['computed'];
        $answers = [];

        foreach ($this->survey()->questions() as $question) {
            if (isset($computed[$question['id']])) {
                $answers[$question['id']] = $computed[$question['id']];

                continue;
            }

            if (! isset($editable[$question['id']])) {
                if (($question['prefill'] ?? '') !== '' && ! in_array($question['type'], ['region', 'table', 'multiple'], true)) {
                    $answers[$question['id']] = $question['prefill'];
                }

                continue;
            }

            $answer = match ($question['type']) {
                'region' => $this->regionAnswer($question, (array) ($submitted[$question['id']] ?? [])),
                'table' => $this->tableRows($question),
                'matrix' => $this->matrixAnswer($question),
                'multiple' => array_values(array_intersect(
                    [...Survey::optionsFor($question, $this->analysis()['known']), ...(Survey::hasOtherOption($question) ? [Survey::OTHER_VALUE] : [])],
                    (array) ($submitted[$question['id']] ?? []),
                )),
                'file' => $this->storedFile($question, $submitted[$question['id']] ?? null),
                'location' => preg_replace('/\s*,\s*/', ', ', trim((string) ($submitted[$question['id']] ?? ''))),
                default => trim((string) ($submitted[$question['id']] ?? '')),
            };

            if ($answer !== '' && $answer !== []) {
                $answers[$question['id']] = $answer;
            }

            $other = trim((string) ($submitted[$question['id'].Survey::OTHER_SUFFIX] ?? ''));
            if (Survey::hasOtherOption($question) && $other !== '' && in_array(Survey::OTHER_VALUE, (array) $answer, true)) {
                $answers[$question['id'].Survey::OTHER_SUFFIX] = $other;
            }
        }

        return $answers;
    }

    /**
     * Stores an uploaded file under the survey's upload folder; a file stored earlier keeps its path.
     *
     * @param  array<string, mixed>  $question
     */
    private function storedFile(array $question, mixed $value): string
    {
        if (Survey::isUploadPath($value)) {
            return $value;
        }
        if (! $value instanceof UploadedFile) {
            return '';
        }

        return $this->storedFiles[$question['id']] ??= $value->storeAs(
            'uploads/'.$this->survey()->id,
            Str::lower(Str::random(32)).'.'.strtolower($value->getClientOriginalExtension()),
            'public',
        );
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
     * The chosen column of each matrix row, in row order; unanswered rows stay empty strings.
     *
     * @param  array<string, mixed>  $question
     * @return list<string>
     */
    private function matrixAnswer(array $question): array
    {
        $submitted = (array) ($this->input('answers.'.$question['id']) ?? []);
        $answer = [];

        foreach (array_keys($question['matrix']['rows']) as $index) {
            $answer[] = is_scalar($submitted[$index] ?? null) ? trim((string) $submitted[$index]) : '';
        }

        return implode('', $answer) === '' ? [] : $answer;
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
