<?php

namespace App\Http\Requests;

use App\Models\Survey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSurveyRequest extends FormRequest
{
    private const ID_PATTERN = 'regex:/^[A-Za-z0-9_-]+$/';

    private const LOGO_PATTERN = 'regex:/^logos\/[A-Za-z0-9_-]+\.(png|jpe?g|webp)$/';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The builder posts its blocks and kop as JSON strings; decode them so nested rules can apply.
     */
    protected function prepareForValidation(): void
    {
        foreach (['blocks', 'header'] as $field) {
            $decoded = json_decode((string) $this->input($field), true);

            if (is_array($decoded)) {
                $this->merge([$field => $decoded]);
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $periodValueCount = count(Survey::PERIOD_TYPES[$this->input('settings.period_type')]['values'] ?? []);
        $conditionRules = fn (string $prefix): array => [
            "{$prefix}.condition" => ['nullable', 'array'],
            "{$prefix}.condition.question_id" => ["required_with:{$prefix}.condition", 'string', self::ID_PATTERN],
            "{$prefix}.condition.operator" => ["required_with:{$prefix}.condition", Rule::in(array_keys(Survey::CONDITION_OPERATORS))],
            "{$prefix}.condition.value" => ['nullable', 'string', 'max:255'],
        ];
        $question = 'blocks.*.questions.*';

        return [
            'title' => ['required', 'string', 'max:160'],
            'form_code' => ['nullable', 'string', 'max:40'],
            'subtitle' => ['nullable', 'string', 'max:500'],
            'activity' => ['required', 'string', 'max:255'],
            'settings.paper_size' => ['required', Rule::in(array_keys(Survey::PAPER_SIZES))],
            'settings.orientation' => ['required', Rule::in(['portrait', 'landscape'])],
            'settings.period_type' => ['required', Rule::in(array_keys(Survey::PERIOD_TYPES))],
            'settings.period_value' => $periodValueCount > 0
                ? ['required', 'integer', 'between:1,'.$periodValueCount]
                : ['nullable'],
            'settings.theme' => ['required', Rule::in(array_keys(Survey::THEMES))],
            'settings.numbering' => ['required', Rule::in(array_keys(Survey::NUMBERING_STYLES))],
            'settings.thank_you_message' => ['nullable', 'string', 'max:500'],
            'settings.confidential' => ['required', 'boolean'],
            'settings.sheet_label' => ['nullable', 'string', 'max:60'],
            'settings.sheet_label_position' => ['nullable', Rule::in(array_keys(Survey::HEADER_SLOTS))],
            'settings.topline_label' => ['nullable', 'string', 'max:60'],
            'settings.topline_label_position' => ['nullable', Rule::in(array_keys(Survey::HEADER_SLOTS))],
            'settings.form_code_position' => ['nullable', Rule::in(array_keys(Survey::HEADER_SLOTS))],

            'header' => ['required', 'array'],
            'header.align' => ['nullable', Rule::in(array_keys(Survey::HEADER_ALIGNMENTS))],
            'header.items' => ['present', 'array', 'max:15'],
            'header.items.*.id' => ['required', 'string', 'max:40', self::ID_PATTERN, 'distinct'],
            'header.items.*.type' => ['required', Rule::in(array_keys(Survey::HEADER_ITEM_TYPES))],
            'header.items.*.slot' => ['required', Rule::in(array_keys(Survey::HEADER_SLOTS))],
            'header.items.*.text' => ['nullable', 'string', 'max:120'],
            'header.items.*.style' => ['nullable', 'string', Rule::in(['bold', 'normal', ...array_keys(Survey::BADGE_STYLES)])],
            'header.items.*.path' => ['nullable', 'string', self::LOGO_PATTERN],
            'header.items.*.height' => ['nullable', 'integer', 'between:24,140'],
            'header.items.*.offset' => ['nullable', 'integer', 'between:-80,80'],

            'intro.text' => ['nullable', 'string', 'max:2000'],
            'intro.help_title' => ['nullable', 'string', 'max:160'],
            'intro.help_org' => ['nullable', 'string', 'max:160'],
            'intro.help_phone' => ['nullable', 'string', 'max:60'],
            'intro.help_email' => ['nullable', 'string', 'max:160'],
            'intro.notice' => ['nullable', 'string', 'max:500'],
            'intro.guide' => ['nullable', 'string', 'max:2000'],

            'blocks' => ['required', 'array', 'min:1'],
            'blocks.*.id' => ['required', 'string', 'max:80', self::ID_PATTERN, 'distinct'],
            'blocks.*.title' => ['required', 'string', 'max:160'],
            'blocks.*.layout' => ['sometimes', Rule::in(['full', 'half'])],
            ...$conditionRules('blocks.*'),
            'blocks.*.questions' => ['present', 'array'],
            "{$question}.id" => ['required', 'string', 'max:80', self::ID_PATTERN, 'distinct'],
            "{$question}.type" => ['required', Rule::in(array_keys(Survey::QUESTION_TYPES))],
            "{$question}.label" => ['required', 'string', 'max:255'],
            "{$question}.description" => ['nullable', 'string', 'max:1000'],
            "{$question}.required" => ['sometimes', 'boolean'],
            "{$question}.code_length" => ['sometimes', 'integer', 'between:0,'.Survey::MAX_CODE_LENGTH],
            "{$question}.options" => ['sometimes', 'array'],
            "{$question}.options.*" => ['string', 'max:255'],
            ...$conditionRules($question),

            "{$question}.region" => ['nullable', 'array'],
            "{$question}.region.fields" => ['sometimes', 'array'],
            "{$question}.region.fields.*.key" => ['required', Rule::in(array_keys(Survey::REGION_FIELDS))],
            "{$question}.region.fields.*.label" => ['required', 'string', 'max:120'],
            "{$question}.region.fields.*.enabled" => ['required', 'boolean'],
            "{$question}.region.fields.*.mode" => ['required', Rule::in(['fixed', 'input'])],
            "{$question}.region.fields.*.code" => ['nullable', 'string', 'max:20'],
            "{$question}.region.fields.*.name" => ['nullable', 'string', 'max:120'],

            "{$question}.table" => ['nullable', 'array'],
            "{$question}.table.mode" => ['sometimes', Rule::in(['fixed', 'dynamic'])],
            "{$question}.table.max_rows" => ['sometimes', 'integer', 'between:1,'.Survey::MAX_TABLE_ROWS],
            "{$question}.table.initial_rows" => ['sometimes', 'integer', 'between:1,'.Survey::MAX_TABLE_ROWS],
            "{$question}.table.rows" => ['sometimes', 'array', 'max:50'],
            "{$question}.table.rows.*" => ['nullable', 'string', 'max:120'],
            "{$question}.table.columns" => ['sometimes', 'array', 'max:'.Survey::MAX_TABLE_COLUMNS],
            "{$question}.table.columns.*.id" => ['required', 'string', 'max:40', self::ID_PATTERN],
            "{$question}.table.columns.*.label" => ['required', 'string', 'max:120'],
            "{$question}.table.columns.*.type" => ['required', Rule::in(array_keys(Survey::TABLE_COLUMN_TYPES))],
            "{$question}.table.columns.*.options" => ['sometimes', 'array'],
            "{$question}.table.columns.*.options.*" => ['string', 'max:120'],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $blocks = $this->input('blocks');
                $answerable = collect($blocks)->flatMap(fn (array $block): array => $block['questions'])
                    ->reject(fn (array $question): bool => $question['type'] === 'info');

                if ($answerable->isEmpty()) {
                    $validator->errors()->add('blocks', 'Tambahkan minimal satu pertanyaan.');

                    return;
                }

                $this->validateStructuredQuestions($validator, $blocks);
                $this->validateConditions($validator, $blocks);
            },
        ];
    }

    /**
     * Region and table questions need their configuration to be usable.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function validateStructuredQuestions(Validator $validator, array $blocks): void
    {
        foreach ($blocks as $blockIndex => $block) {
            foreach ($block['questions'] as $questionIndex => $question) {
                $key = "blocks.{$blockIndex}.questions.{$questionIndex}";

                if ($question['type'] === 'region' && collect($question['region']['fields'] ?? [])->where('enabled', true)->isEmpty()) {
                    $validator->errors()->add("{$key}.region", "Identitas wilayah \"{$question['label']}\" membutuhkan minimal satu isian.");
                }

                if ($question['type'] !== 'table') {
                    continue;
                }

                $columns = $question['table']['columns'] ?? [];

                if ($columns === []) {
                    $validator->errors()->add("{$key}.table", "Tabel \"{$question['label']}\" membutuhkan minimal satu kolom.");
                }

                if (collect($columns)->pluck('id')->duplicates()->isNotEmpty()) {
                    $validator->errors()->add("{$key}.table", "Kolom tabel \"{$question['label']}\" tidak valid.");
                }

                if (($question['table']['mode'] ?? 'dynamic') === 'fixed' && collect($question['table']['rows'] ?? [])->filter(fn ($row): bool => trim((string) $row) !== '')->isEmpty()) {
                    $validator->errors()->add("{$key}.table", "Tabel \"{$question['label']}\" dengan baris tetap membutuhkan nama baris.");
                }
            }
        }
    }

    /**
     * A condition may only depend on an earlier, simple question.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function validateConditions(Validator $validator, array $blocks): void
    {
        $numbers = Survey::numberQuestions($blocks, $this->input('settings.numbering'));
        $earlierQuestions = [];

        foreach ($blocks as $blockIndex => $block) {
            $this->checkCondition($validator, $block['condition'] ?? null, $earlierQuestions, "blocks.{$blockIndex}.condition", 'Blok '.Survey::roman($blockIndex + 1));

            foreach ($block['questions'] as $questionIndex => $question) {
                $subject = isset($numbers[$question['id']]) ? "Pertanyaan {$numbers[$question['id']]}" : 'Keterangan';
                $this->checkCondition($validator, $question['condition'] ?? null, $earlierQuestions, "blocks.{$blockIndex}.questions.{$questionIndex}.condition", $subject);

                if (! in_array($question['type'], Survey::NON_LOGIC_TYPES, true)) {
                    $earlierQuestions[$question['id']] = $question;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $condition
     * @param  array<string, array<string, mixed>>  $earlierQuestions
     */
    private function checkCondition(Validator $validator, ?array $condition, array $earlierQuestions, string $key, string $subject): void
    {
        if (! $condition) {
            return;
        }

        if (! isset($earlierQuestions[$condition['question_id']])) {
            $validator->errors()->add($key, "Logika {$subject} harus merujuk pertanyaan yang ada sebelumnya.");

            return;
        }

        if ($condition['operator'] !== 'answered' && trim((string) ($condition['value'] ?? '')) === '') {
            $validator->errors()->add($key, "Logika {$subject} belum memiliki nilai jawaban.");
        }
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'blocks.required' => 'Tambahkan minimal satu blok.',
            'blocks.array' => 'Susunan blok tidak valid.',
            'blocks.min' => 'Tambahkan minimal satu blok.',
            'blocks.*.title.required' => 'Judul blok wajib diisi.',
            'blocks.*.questions.*.label.required' => 'Teks pertanyaan wajib diisi.',
            'blocks.*.questions.*.code_length.between' => 'Jumlah kotak kode maksimal '.Survey::MAX_CODE_LENGTH.'.',
            'blocks.*.questions.*.table.columns.*.label.required' => 'Judul kolom tabel wajib diisi.',
            'header.items.*.path.regex' => 'Logo kop tidak valid. Unggah ulang logonya.',
        ];
    }

    /**
     * Get the validated survey attributes, normalised for storage.
     *
     * @return array<string, mixed>
     */
    public function surveyData(): array
    {
        $settings = $this->validated('settings');
        $intro = $this->validated('intro') ?? [];

        return [
            'title' => $this->validated('title'),
            'form_code' => $this->validated('form_code'),
            'subtitle' => $this->validated('subtitle'),
            'activity' => $this->validated('activity'),
            'settings' => [
                'paper_size' => $settings['paper_size'],
                'orientation' => $settings['orientation'],
                'period_type' => $settings['period_type'],
                'period_value' => $settings['period_type'] === 'none' ? null : (int) $settings['period_value'],
                'theme' => $settings['theme'],
                'numbering' => $settings['numbering'],
                'thank_you_message' => $settings['thank_you_message'] ?? null,
                'confidential' => (bool) $settings['confidential'],
                'sheet_label' => trim((string) ($settings['sheet_label'] ?? '')),
                'sheet_label_position' => $settings['sheet_label_position'] ?? 'left',
                'topline_label' => trim((string) ($settings['topline_label'] ?? '')),
                'topline_label_position' => $settings['topline_label_position'] ?? 'left',
                'form_code_position' => $settings['form_code_position'] ?? 'right',
            ],
            'header' => [
                'align' => $this->validated('header.align') ?? 'center',
                'items' => collect($this->validated('header.items'))->map(fn (array $item): array => [
                    'id' => $item['id'],
                    'type' => $item['type'],
                    'slot' => $item['slot'],
                    'text' => trim((string) ($item['text'] ?? '')),
                    'style' => $item['style'] ?? ($item['type'] === 'badge' ? 'dark' : 'bold'),
                    'path' => $item['type'] === 'logo' ? ($item['path'] ?? null) : null,
                    'height' => (int) ($item['height'] ?? 64),
                    'offset' => (int) ($item['offset'] ?? 0),
                ])->values()->all(),
            ],
            'intro' => collect(array_keys(Survey::DEFAULT_INTRO))
                ->mapWithKeys(fn (string $key): array => [$key => trim((string) ($intro[$key] ?? ''))])
                ->all(),
            'blocks' => collect($this->validated('blocks'))->map(fn (array $block): array => [
                'id' => $block['id'],
                'title' => $block['title'],
                'layout' => $block['layout'] ?? 'full',
                'condition' => $this->normalizeCondition($block['condition'] ?? null),
                'questions' => collect($block['questions'])->map(fn (array $question): array => $this->normalizeQuestion($question))->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $question
     * @return array<string, mixed>
     */
    private function normalizeQuestion(array $question): array
    {
        $type = $question['type'];
        $normalized = [
            'id' => $question['id'],
            'type' => $type,
            'label' => $question['label'],
            'description' => trim((string) ($question['description'] ?? '')),
            'required' => $type !== 'info' && (bool) ($question['required'] ?? false),
            'code_length' => in_array($type, Survey::NON_LOGIC_TYPES, true) ? 0 : (int) ($question['code_length'] ?? 0),
            'options' => in_array($type, Survey::CHOICE_TYPES, true) ? array_values($question['options'] ?? []) : [],
            'condition' => $this->normalizeCondition($question['condition'] ?? null),
        ];

        if ($type === 'region') {
            $fields = collect($question['region']['fields'] ?? [])->keyBy('key');
            $normalized['region'] = [
                'fields' => collect(Survey::REGION_FIELDS)->map(fn (string $label, string $key): array => [
                    'key' => $key,
                    'label' => $fields[$key]['label'] ?? $label,
                    'enabled' => (bool) ($fields[$key]['enabled'] ?? false),
                    'mode' => $fields[$key]['mode'] ?? 'input',
                    'code' => trim((string) ($fields[$key]['code'] ?? '')),
                    'name' => trim((string) ($fields[$key]['name'] ?? '')),
                ])->values()->all(),
            ];
        }

        if ($type === 'table') {
            $table = $question['table'];
            $mode = $table['mode'] ?? 'dynamic';
            $normalized['table'] = [
                'mode' => $mode,
                'max_rows' => (int) ($table['max_rows'] ?? 20),
                'initial_rows' => min((int) ($table['initial_rows'] ?? 3), (int) ($table['max_rows'] ?? 20)),
                'rows' => $mode === 'fixed' ? $this->cleanList($table['rows'] ?? []) : [],
                'columns' => collect($table['columns'])->map(fn (array $column): array => [
                    'id' => $column['id'],
                    'label' => $column['label'],
                    'type' => $column['type'],
                    'options' => $column['type'] === 'choice' ? $this->cleanList($column['options'] ?? []) : [],
                ])->values()->all(),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function cleanList(array $values): array
    {
        return collect($values)->map(fn ($value): string => trim((string) $value))->filter(fn (string $value): bool => $value !== '')->values()->all();
    }

    /**
     * @param  array<string, mixed>|null  $condition
     * @return array<string, string>|null
     */
    private function normalizeCondition(?array $condition): ?array
    {
        if (! $condition) {
            return null;
        }

        return [
            'question_id' => $condition['question_id'],
            'operator' => $condition['operator'],
            'value' => $condition['operator'] === 'answered' ? '' : trim((string) ($condition['value'] ?? '')),
        ];
    }
}
