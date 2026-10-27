<?php

namespace App\Http\Requests;

use App\Models\Assignment;
use App\Models\Survey;
use App\Support\FieldValidation;
use App\Support\Lookup;
use App\Support\SurveyLogic;
use App\Support\UploadCompressor;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SubmitSurveyResponseRequest extends FormRequest
{
    /**
     * The form's logic applied to the submitted answers; see SurveyLogic::analyse().
     *
     * @var array<string, mixed>|null
     */
    private ?array $analysis = null;

    /**
     * @var list<array<string, mixed>>|null
     */
    private ?array $editableFields = null;

    /**
     * Paths of the files stored by answers(), so a request stores each upload once.
     *
     * @var array<string, string>
     */
    private array $storedFiles = [];

    /**
     * Consistency warnings the respondent confirmed, stored with the response.
     *
     * @var list<array<string, mixed>>
     */
    private array $confirmedWarnings = [];

    /**
     * Consistency warnings still waiting for the respondent's confirmation.
     *
     * @var list<array<string, mixed>>
     */
    private array $pendingWarnings = [];

    private ?Survey $resolvedSurvey = null;

    /**
     * Options fetched for lookup dropdowns, per field key (null: the outside API could not be reached).
     *
     * @var array<string, list<array{value: string, label: string}>|null>
     */
    private array $lookupOptions = [];

    private Assignment|false|null $resolvedAssignment = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The survey as this submission sees it: an assignment's locked pre-filled answers are fixed.
     */
    private function survey(): Survey
    {
        if ($this->resolvedSurvey !== null) {
            return $this->resolvedSurvey;
        }

        $survey = $this->route('survey');
        $assignment = $this->assignment();

        return $this->resolvedSurvey = $assignment ? $assignment->applyTo($survey) : $survey;
    }

    /**
     * The assignment this submission answers, from the private link's token ("a").
     */
    public function assignment(): ?Assignment
    {
        if ($this->resolvedAssignment === null) {
            $token = (string) $this->input('a', '');
            $this->resolvedAssignment = $token === ''
                ? false
                : ($this->route('survey')->assignments()->where('token', $token)->first() ?? false);
        }

        return $this->resolvedAssignment ?: null;
    }

    /**
     * Rupiah answers arrive formatted ("1.500.000"); keep only their digits, in repeating blocks too.
     */
    protected function prepareForValidation(): void
    {
        $answers = (array) $this->input('answers', []);
        $digits = fn ($value) => is_string($value) ? preg_replace('/\D/', '', $value) : $value;

        foreach ($this->survey()->questions() as $question) {
            if ($question['type'] === 'currency' && isset($answers[$question['id']])) {
                $answers[$question['id']] = is_array($answers[$question['id']]) ? array_map($digits, $answers[$question['id']]) : $digits($answers[$question['id']]);
            }
        }

        $this->merge(['answers' => $answers]);
    }

    /**
     * @return array<string, mixed>
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
     * Fields the respondent can see and change (one per question, or per row of a repeating block);
     * only these are validated and taken from the request.
     *
     * @return list<array{question: array<string, mixed>, key: string, row: int|null, state: array{hidden: bool, disabled: bool, required: bool}, known: array<string, mixed>}>
     */
    private function editableFields(): array
    {
        if ($this->editableFields !== null) {
            return $this->editableFields;
        }

        $questions = collect($this->survey()->questions())->keyBy('id');
        $fields = [];

        foreach ($this->analysis()['fields'] as $field) {
            $question = $questions[$field['question']['id']] ?? null;

            if ($question && ! $field['state']['hidden'] && ! $field['state']['disabled'] && ! in_array($question['type'], Survey::COMPUTED_TYPES, true)) {
                $fields[] = ['question' => $question] + $field;
            }
        }

        return $this->editableFields = $fields;
    }

    /**
     * Where a field's "Lainnya" text is posted: next to the question, per row in a repeating block.
     *
     * @param  array<string, mixed>  $field
     */
    private function otherKey(array $field): string
    {
        return $field['question']['id'].Survey::OTHER_SUFFIX.($field['row'] === null ? '' : '.'.$field['row']);
    }

    /**
     * Where a location field's GPS accuracy (metres) is posted, next to the question like the "Lainnya" text.
     *
     * @param  array<string, mixed>  $field
     */
    private function accuracyKey(array $field): string
    {
        return $field['question']['id'].Survey::ACCURACY_SUFFIX.($field['row'] === null ? '' : '.'.$field['row']);
    }

    /**
     * The options a field's choice question offers for the answers given so far.
     *
     * @param  array<string, mixed>  $field
     * @return list<string>
     */
    private function optionsOf(array $field): array
    {
        $options = Survey::optionsFor($field['question'], $field['known']);

        return Survey::hasOtherOption($field['question']) ? [...$options, Survey::OTHER_VALUE] : $options;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return list<array{value: string, label: string}>|null
     */
    private function lookupOptionsOf(array $field): ?array
    {
        if (! array_key_exists($field['key'], $this->lookupOptions)) {
            $this->lookupOptions[$field['key']] = Lookup::options($field['question'], $field['known']);
        }

        return $this->lookupOptions[$field['key']];
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = ['answers' => ['nullable', 'array']];

        foreach ($this->editableFields() as $field) {
            $question = $field['question'];
            $key = 'answers.'.$field['key'];
            $presence = $field['state']['required'] ? 'required' : 'nullable';

            if ($question['type'] === 'region') {
                $rules[$key] = ['nullable', 'array'];

                foreach (Survey::regionFields($question) as $regionField) {
                    if ($regionField['mode'] === 'input') {
                        $rules["{$key}.{$regionField['key']}"] = $regionField['key'] === 'klasifikasi'
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

            if ($question['type'] === 'list') {
                $rules[$key] = ['nullable', 'array', 'max:'.($question['list']['max'] ?? Survey::MAX_LIST_ITEMS)];
                $rules["{$key}.*"] = ['nullable', 'string', 'max:255'];

                continue;
            }

            if (in_array($question['type'], Survey::RANGE_TYPES, true)) {
                $range = $question['range'];
                $rules[$key] = [$presence, 'integer', "between:{$range['min']},{$range['max']}", $this->stepRule($question)];

                continue;
            }

            $maxLength = fn (int $limit): string => 'max:'.min($limit, $question['max_length'] ?: $limit);
            $options = $this->optionsOf($field);
            if (Survey::hasOtherOption($question)) {
                $rules['answers.'.$this->otherKey($field)] = ['nullable', 'string', 'max:255'];
            }
            if ($question['type'] === 'location') {
                $rules['answers.'.$this->accuracyKey($field)] = ['nullable', 'numeric', 'min:0'];
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
                'location' => [$presence, 'string', 'max:80', $this->locationRule($field)],
                'file' => [$presence, $this->fileRule($question)],
                'email' => [$presence, 'email', 'max:160'],
                'phone' => [$presence, 'regex:/^[0-9+\-\s().]{6,20}$/'],
                'code' => $question['code_length'] > 0
                    ? [$presence, 'digits:'.$question['code_length']]
                    : [$presence, 'string', 'max:50'],
                'single' => [$presence, 'string', Rule::in($options)],
                'dropdown' => Lookup::isLookup($question)
                    ? [$presence, 'string', 'max:255', ...($this->lookupOptionsOf($field) === null ? [] : [Rule::in(array_column($this->lookupOptionsOf($field), 'value'))])]
                    : [$presence, 'string', Rule::in($options)],
                'short' => [$presence, 'string', $maxLength(255), ...($question['mask'] !== '' ? [$this->maskRule($question)] : [])],
                'multiple' => [$presence, 'array', ...($field['state']['required'] ? ['min:1'] : [])],
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
     * A location is "latitude, longitude" in decimal degrees, taken with the GPS accuracy the question demands.
     *
     * @param  array<string, mixed>  $field
     */
    private function locationRule(array $field): Closure
    {
        $question = $field['question'];

        return function (string $attribute, mixed $value, Closure $fail) use ($question, $field): void {
            if (! is_string($value) || ! preg_match(Survey::LOCATION_PATTERN, trim($value), $match)
                || abs((float) $match[1]) > 90 || abs((float) $match[2]) > 180) {
                $fail("{$question['text']} harus berupa koordinat \"lintang, bujur\", mis. -7.0321, 112.7464.");

                return;
            }

            $maxAccuracy = $question['location']['max_accuracy'] ?? null;
            $accuracy = $this->input('answers.'.$this->accuracyKey($field));
            if ($maxAccuracy !== null && (! is_numeric($accuracy) || (float) $accuracy > $maxAccuracy)) {
                $fail(is_numeric($accuracy)
                    ? "Akurasi {$question['text']} sekitar ".round((float) $accuracy)." m, harus paling banyak {$maxAccuracy} m. Perbarui lokasi di tempat terbuka."
                    : "{$question['text']} harus direkam dengan GPS (akurasi paling banyak {$maxAccuracy} m).");
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
     * Required tables need a filled cell, required matrices an answer on every row and required lists
     * one name; the builder's "Validasi" rules and consistency checks run once the basics pass.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ((string) $this->input('a', '') !== '') {
                    $assignment = $this->assignment();
                    $problem = match (true) {
                        $assignment === null => 'Tautan penugasan tidak dikenal.',
                        $assignment->isSubmitted() => 'Penugasan ini sudah dikirim.',
                        $assignment->isExpired() => 'Penugasan ini sudah kedaluwarsa.',
                        default => null,
                    };
                    if ($problem !== null) {
                        $validator->errors()->add('a', $problem);

                        return;
                    }
                }

                foreach ($this->editableFields() as $field) {
                    $question = $field['question'];
                    $key = 'answers.'.$field['key'];
                    $required = $field['state']['required'];

                    if ($question['type'] === 'table' && $required && $this->tableRows($question) === []) {
                        $validator->errors()->add($key, $question['text'].' wajib diisi minimal satu baris.');
                    }

                    if ($question['type'] === 'matrix' && $required && in_array('', $this->matrixAnswer($question) ?: [''], true)) {
                        $validator->errors()->add($key, $question['text'].' wajib diisi pada setiap baris.');
                    }

                    if ($question['type'] === 'list' && $required && $this->listAnswer($field) === []) {
                        $validator->errors()->add($key, $question['text'].' wajib diisi minimal satu nama.');
                    }

                    if ($validator->errors()->has($key) || $validator->errors()->has($key.'.*')) {
                        continue;
                    }

                    if (Survey::hasOtherOption($question) && in_array(Survey::OTHER_VALUE, (array) $this->input($key), true)
                        && trim((string) $this->input('answers.'.$this->otherKey($field))) === '') {
                        $validator->errors()->add($key, 'Sebutkan jawaban "'.Survey::OTHER_VALUE.'" untuk '.$question['text'].'.');

                        continue;
                    }

                    if ($message = FieldValidation::failure($question, $this->input($key))) {
                        $validator->errors()->add($key, $message);

                        continue;
                    }

                    $this->checkConsistency($validator, $field);
                }
            },
        ];
    }

    /**
     * Consistency checks (e.g. a man who is pregnant): errors must be fixed, warnings must be confirmed.
     * In a repeating block they run, and are confirmed, per row.
     *
     * @param  array<string, mixed>  $field
     */
    private function checkConsistency(Validator $validator, array $field): void
    {
        $question = $field['question'];

        foreach (FieldValidation::failedChecks($question, $field['known']) as $check) {
            $confirmed = $this->input('confirmations.'.$check['id'].($field['row'] === null ? '' : '.'.$field['row']));

            if ($check['level'] === 'warning' && ! empty($confirmed) && ! is_array($confirmed)) {
                $this->confirmedWarnings[] = ['question_id' => $question['id'], 'check_id' => $check['id'], 'message' => $check['message']]
                    + ($field['row'] === null ? [] : ['row' => $field['row']]);

                continue;
            }

            if ($check['level'] === 'warning') {
                $this->pendingWarnings[] = ['question_id' => $question['id'], 'check_id' => $check['id'], 'message' => $check['message']]
                    + ($field['row'] === null ? [] : ['row' => $field['row']]);
            }

            $validator->errors()->add(
                'answers.'.$field['key'],
                $check['level'] === 'warning' ? 'Peringatan: '.$check['message'] : $check['message'],
            );
        }
    }

    /**
     * Warnings the respondent confirmed, to keep with the response for later review.
     *
     * @return array{confirmed_warnings: list<array<string, mixed>>}|null
     */
    public function flags(): ?array
    {
        return $this->confirmedWarnings === [] ? null : ['confirmed_warnings' => $this->confirmedWarnings];
    }

    /**
     * Warnings that block sending until confirmed, for API callers.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingWarnings(): array
    {
        return $this->pendingWarnings;
    }

    /**
     * Calculated values and repeating-block rows for the submitted answers, for API callers.
     *
     * @return array{computed: array<string, mixed>, rows: array<string, list<string>>, visible: list<string>}
     */
    public function evaluation(): array
    {
        $analysis = $this->analysis();

        return [
            'computed' => $analysis['computed'],
            'rows' => $analysis['rows'],
            'visible' => array_keys(array_filter($analysis['states'], fn (array $state): bool => ! $state['hidden'])),
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

        foreach ($this->editableFields() as $field) {
            $question = $field['question'];
            $key = 'answers.'.$field['key'];
            $rowLabel = $field['row'] === null ? '' : ' (rincian '.($field['row'] + 1).')';
            $attributes[$key] = $question['text'].$rowLabel;

            foreach (Survey::regionFields($question) as $regionField) {
                $attributes["{$key}.{$regionField['key']}"] = $regionField['label'];
            }

            foreach ($question['table']['columns'] ?? [] as $column) {
                $attributes["{$key}.*.{$column['id']}"] = $question['text'].' – '.$column['label'];
            }

            foreach ($question['matrix']['rows'] ?? [] as $index => $row) {
                $attributes["{$key}.{$index}"] = $question['text'].' – '.$row;
            }

            if ($question['type'] === 'list') {
                $attributes["{$key}.*"] = $question['text'];
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
            'max' => ':attribute terlalu panjang (maksimal :max).',
            'string' => ':attribute tidak valid.',
            'array' => ':attribute tidak valid.',
            'date_format' => ':attribute harus berupa jam yang valid (JJ:MM).',
        ];
    }

    /**
     * The answers to store, keyed by question id: what the respondent filled in for editable fields,
     * the pre-filled value for disabled or hidden ones, and the result of calculated ones. Questions
     * of a repeating block hold a list with one entry per row.
     *
     * @return array<string, mixed>
     */
    public function answers(): array
    {
        $editable = [];
        foreach ($this->editableFields() as $field) {
            $editable[$field['key']] = $field;
        }

        $computed = $this->analysis()['computed'];
        $rows = $this->analysis()['rows'];
        $answers = [];

        foreach ($this->survey()->blocks as $block) {
            $repeating = SurveyLogic::repeatSource($block) !== null;
            $rowCount = count($rows[$block['id']] ?? []);

            foreach ($block['questions'] as $question) {
                if ($question['type'] === 'info') {
                    continue;
                }

                if (! $repeating) {
                    $value = isset($computed[$question['id']])
                        ? $computed[$question['id']]
                        : $this->fieldAnswer($editable[$question['id']] ?? null, $question, $answers, $question['id']);
                    if ($value !== '' && $value !== [] && $value !== null) {
                        $answers[$question['id']] = $value;
                    }
                    // Keep the "Lainnya" text, a looked-up label and a GPS accuracy right after their answer.
                    foreach ([Survey::LABEL_SUFFIX, Survey::OTHER_SUFFIX, Survey::ACCURACY_SUFFIX] as $suffix) {
                        if (array_key_exists($question['id'].$suffix, $answers)) {
                            $extra = $answers[$question['id'].$suffix];
                            unset($answers[$question['id'].$suffix]);
                            $answers[$question['id'].$suffix] = $extra;
                        }
                    }

                    continue;
                }

                $values = [];
                for ($row = 0; $row < $rowCount; $row++) {
                    $values[$row] = isset($computed[$question['id']][$row])
                        ? $computed[$question['id']][$row]
                        : $this->fieldAnswer($editable[$question['id'].'.'.$row] ?? null, $question, $answers, $question['id'], $row);
                }

                if (array_filter($values, fn ($value): bool => $value !== '' && $value !== [] && $value !== null) !== []) {
                    $answers[$question['id']] = array_map(fn ($value) => $value ?? '', $values);
                }
            }
        }

        return $answers;
    }

    /**
     * One field's stored answer; its "Lainnya" text is added to $answers next to it.
     *
     * @param  array<string, mixed>|null  $field  null when the field is hidden or disabled
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $answers
     */
    private function fieldAnswer(?array $field, array $question, array &$answers, string $id, ?int $row = null): mixed
    {
        if ($field === null) {
            // Hidden or disabled: the pre-filled value stands, where one makes sense.
            $hidden = $this->analysis()['states'][$id]['hidden'] ?? true;

            return ($question['prefill'] ?? '') !== '' && ! in_array($question['type'], ['region', 'table', 'multiple'], true) && ! ($row !== null && $hidden)
                ? $question['prefill']
                : '';
        }

        $submitted = $this->submittedAnswers();
        $raw = $row === null ? ($submitted[$id] ?? null) : (is_array($submitted[$id] ?? null) ? ($submitted[$id][$row] ?? null) : null);
        $question = $field['question'];

        $answer = match ($question['type']) {
            'region' => $this->regionAnswer($question, (array) $raw),
            'table' => $this->tableRows($question),
            'matrix' => $this->matrixAnswer($question),
            'list' => $this->listAnswer($field),
            'multiple' => array_values(array_intersect($this->optionsOf($field), (array) $raw)),
            'file' => $this->storedFile($field['key'], $raw),
            'location' => preg_replace('/\s*,\s*/', ', ', trim((string) $raw)),
            default => is_scalar($raw) ? trim((string) $raw) : '',
        };

        // A value chosen from a reference list or API keeps its label next to it.
        if (Lookup::isLookup($question) && is_string($answer) && $answer !== '' && ($label = Lookup::labelOf($this->lookupOptionsOf($field), $answer)) !== null) {
            if ($row === null) {
                $answers[$id.Survey::LABEL_SUFFIX] = $label;
            } else {
                $answers[$id.Survey::LABEL_SUFFIX][$row] = $label;
            }
        }

        // The GPS accuracy of a location is kept next to it, rounded to whole metres.
        $accuracy = $question['type'] === 'location' && $answer !== '' ? $this->input('answers.'.$this->accuracyKey($field)) : null;
        if (is_numeric($accuracy)) {
            if ($row === null) {
                $answers[$id.Survey::ACCURACY_SUFFIX] = (int) round((float) $accuracy);
            } else {
                $answers[$id.Survey::ACCURACY_SUFFIX][$row] = (int) round((float) $accuracy);
            }
        }

        $other = trim((string) $this->input('answers.'.$this->otherKey($field)));
        if (Survey::hasOtherOption($question) && $other !== '' && in_array(Survey::OTHER_VALUE, (array) $answer, true)) {
            if ($row === null) {
                $answers[$id.Survey::OTHER_SUFFIX] = $other;
            } else {
                $answers[$id.Survey::OTHER_SUFFIX][$row] = $other;
            }
        }

        return $answer;
    }

    /**
     * The non-empty names of a list question, in order.
     *
     * @param  array<string, mixed>  $field
     * @return list<string>
     */
    private function listAnswer(array $field): array
    {
        $items = array_map(fn ($item): string => is_scalar($item) ? trim((string) $item) : '', (array) $this->input('answers.'.$field['key'], []));

        return array_values(array_filter($items, fn (string $item): bool => $item !== ''));
    }

    /**
     * Stores an uploaded file (compressed) under the survey's upload folder; a file stored earlier keeps its path.
     */
    private function storedFile(string $key, mixed $value): string
    {
        if (Survey::isUploadPath($value)) {
            return $value;
        }
        if (! $value instanceof UploadedFile) {
            return '';
        }

        return $this->storedFiles[$key] ??= UploadCompressor::store($value, 'uploads/'.$this->survey()->id);
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
