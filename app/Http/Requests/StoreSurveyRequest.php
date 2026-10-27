<?php

namespace App\Http\Requests;

use App\Models\ReferenceList;
use App\Models\Survey;
use App\Support\FieldValidation;
use App\Support\Formula;
use App\Support\GoogleDrive;
use App\Support\Lookup;
use App\Support\Palette;
use App\Support\SurveyLogic;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
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
                $this->merge([$field => $field === 'blocks' ? SurveyLogic::upgradeBlocks($decoded) : $decoded]);
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
        $rangeBound = ['sometimes', 'integer', 'between:-'.Survey::RANGE_LIMIT.','.Survey::RANGE_LIMIT];

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
            'settings.theme_color' => ['nullable', 'required_if:settings.theme,custom', 'regex:'.Palette::HEX_PATTERN],
            'settings.numbering' => ['required', Rule::in(array_keys(Survey::NUMBERING_STYLES))],
            'settings.thank_you_message' => ['nullable', 'string', 'max:500'],
            'settings.webhook_url' => ['nullable', 'url:http,https', 'max:500'],
            'settings.drive_url' => ['nullable', 'max:500', 'regex:'.GoogleDrive::WEB_APP_PATTERN],
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
            'blocks.*.orientation' => ['sometimes', 'nullable', Rule::in(['portrait', 'landscape'])],
            'blocks.*.repeat' => ['nullable', 'array'],
            'blocks.*.repeat.source' => ['nullable', 'string', 'max:80', self::ID_PATTERN],
            'blocks.*.repeat.max' => ['sometimes', 'integer', 'between:1,'.Survey::MAX_ROSTER_ROWS],
            'blocks.*.repeat.print_rows' => ['sometimes', 'integer', 'between:1,30'],
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
            "{$question}.hidden" => ['sometimes', 'boolean'],
            "{$question}.disabled" => ['sometimes', 'boolean'],
            "{$question}.hide_label" => ['sometimes', 'boolean'],
            "{$question}.placeholder" => ['nullable', 'string', 'max:255'],
            "{$question}.prefill" => ['nullable', 'string', 'max:255'],
            "{$question}.width" => ['sometimes', Rule::in(array_keys(Survey::FIELD_WIDTHS))],
            "{$question}.help_position" => ['sometimes', Rule::in(['below', 'above'])],
            "{$question}.max_length" => ['nullable', 'integer', 'between:1,5000'],
            "{$question}.logic" => ['nullable', 'array'],
            "{$question}.validation" => ['nullable', 'array'],
            "{$question}.validation.rules" => ['sometimes', 'array', 'max:'.FieldValidation::MAX_RULES],
            "{$question}.validation.message" => ['nullable', 'string', 'max:255'],
            "{$question}.validation.checks" => ['sometimes', 'array', 'max:'.FieldValidation::MAX_CHECKS],
            "{$question}.options_from" => ['nullable', 'string', 'max:80', self::ID_PATTERN],
            "{$question}.other_option" => ['sometimes', 'boolean'],
            "{$question}.other_label" => ['nullable', 'string', 'max:60'],
            "{$question}.mask" => ['nullable', 'string', 'max:'.Survey::MAX_MASK_LENGTH],
            "{$question}.formula" => ['nullable', 'string', 'max:'.Formula::MAX_LENGTH],
            "{$question}.format" => ['sometimes', Rule::in(array_keys(Formula::FORMATS))],
            "{$question}.decimals" => ['sometimes', 'integer', 'between:0,6'],
            "{$question}.file" => ['nullable', 'array'],
            "{$question}.file.accept" => ['sometimes', Rule::in(array_keys(Survey::FILE_KINDS))],
            "{$question}.file.max_mb" => ['sometimes', 'integer', 'between:1,'.Survey::MAX_FILE_MB],
            "{$question}.location" => ['nullable', 'array'],
            "{$question}.location.max_accuracy" => ['nullable', 'integer', 'between:1,'.Survey::MAX_LOCATION_ACCURACY],
            "{$question}.lookup" => ['nullable', 'array'],
            "{$question}.lookup.source" => ['sometimes', Rule::in(array_keys(Lookup::SOURCES))],
            "{$question}.lookup.reference" => ['nullable', 'string', 'max:120'],
            "{$question}.lookup.value_column" => ['nullable', 'string', 'max:120'],
            "{$question}.lookup.label_column" => ['nullable', 'string', 'max:120'],
            "{$question}.lookup.filter_column" => ['nullable', 'string', 'max:120'],
            "{$question}.lookup.url" => ['nullable', 'string', 'max:500', 'regex:/^https?:\/\/[^\s]+$/i'],
            "{$question}.lookup.data_path" => ['nullable', 'string', 'max:120'],
            "{$question}.lookup.value_key" => ['nullable', 'string', 'max:120'],
            "{$question}.lookup.label_key" => ['nullable', 'string', 'max:120'],
            "{$question}.lookup.param" => ['nullable', 'string', 'max:60'],
            "{$question}.lookup.depends_on" => ['nullable', 'string', 'max:80', self::ID_PATTERN],
            "{$question}.list" => ['nullable', 'array'],
            "{$question}.list.max" => ['sometimes', 'integer', 'between:1,'.Survey::MAX_LIST_ITEMS],

            "{$question}.region" => ['nullable', 'array'],
            "{$question}.region.fields" => ['sometimes', 'array'],
            "{$question}.region.fields.*.key" => ['required', Rule::in(array_keys(Survey::REGION_FIELDS))],
            "{$question}.region.fields.*.label" => ['required', 'string', 'max:120'],
            "{$question}.region.fields.*.enabled" => ['required', 'boolean'],
            "{$question}.region.fields.*.mode" => ['required', Rule::in(['fixed', 'input'])],
            "{$question}.region.fields.*.code" => ['nullable', 'string', 'max:20'],
            "{$question}.region.fields.*.name" => ['nullable', 'string', 'max:120'],

            "{$question}.range" => ['nullable', 'array'],
            "{$question}.range.min" => $rangeBound,
            "{$question}.range.max" => $rangeBound,
            "{$question}.range.step" => ['sometimes', 'integer', 'between:1,'.Survey::RANGE_LIMIT],

            "{$question}.matrix" => ['nullable', 'array'],
            "{$question}.matrix.rows" => ['sometimes', 'array', 'max:'.Survey::MAX_MATRIX_ROWS],
            "{$question}.matrix.rows.*" => ['nullable', 'string', 'max:120'],
            "{$question}.matrix.columns" => ['sometimes', 'array', 'max:'.Survey::MAX_MATRIX_COLUMNS],
            "{$question}.matrix.columns.*" => ['nullable', 'string', 'max:120'],

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
     * Region, range, matrix and table questions need their configuration to be usable.
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

                if (in_array($question['type'], Survey::RANGE_TYPES, true) && ($problem = $this->rangeProblem($question))) {
                    $validator->errors()->add("{$key}.range", $problem);
                }

                if ($question['type'] === 'matrix') {
                    $rows = $this->cleanList($question['matrix']['rows'] ?? []);
                    $columns = $this->cleanList($question['matrix']['columns'] ?? []);

                    if ($rows === [] || count($columns) < 2) {
                        $validator->errors()->add("{$key}.matrix", "Matriks \"{$question['label']}\" membutuhkan minimal satu baris dan dua kolom pilihan.");
                    } elseif (count(array_unique($rows)) < count($rows) || count(array_unique($columns)) < count($columns)) {
                        $validator->errors()->add("{$key}.matrix", "Baris dan kolom matriks \"{$question['label']}\" tidak boleh kembar.");
                    }
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
     * @param  array<string, mixed>  $question
     */
    private function rangeProblem(array $question): ?string
    {
        $range = $this->normalizeRange($question);
        $points = intdiv($range['max'] - $range['min'], $range['step']) + 1;

        return match (true) {
            $range['max'] <= $range['min'] => "Nilai terbesar \"{$question['label']}\" harus lebih dari nilai terkecilnya.",
            $range['step'] > $range['max'] - $range['min'] => "Kelipatan \"{$question['label']}\" melebihi rentang nilainya.",
            $question['type'] !== 'slider' && $points > Survey::MAX_RANGE_POINTS => "\"{$question['label']}\" maksimal memiliki ".Survey::MAX_RANGE_POINTS.' pilihan nilai.',
            default => null,
        };
    }

    /**
     * A condition may only depend on an earlier, simple question.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function validateConditions(Validator $validator, array $blocks): void
    {
        $numbers = Survey::numberQuestions($blocks, $this->input('settings.numbering'));
        // Rules may use earlier questions outside repeating blocks, plus earlier ones in their own
        // repeating block (answered per row). Formulas may also total another repeating block's rows.
        $earlierQuestions = [];
        $earlierAnswerable = [];
        $formulaOnly = [];

        foreach ($blocks as $blockIndex => $block) {
            $blockName = 'Blok '.Survey::roman($blockIndex + 1);
            $this->checkCondition($validator, $block['condition'] ?? null, $earlierQuestions, "blocks.{$blockIndex}.condition", $blockName);
            $source = SurveyLogic::repeatSource($block);
            $rowScope = [];

            if ($source !== null && ! in_array($earlierAnswerable[$source]['type'] ?? null, Survey::ROSTER_SOURCE_TYPES, true)) {
                $validator->errors()->add("blocks.{$blockIndex}.repeat", "{$blockName} hanya dapat diulang per jawaban pertanyaan Angka, Pilihan lebih dari satu, atau Daftar nama sebelumnya (di luar blok berulang).");
            }

            foreach ($block['questions'] as $questionIndex => $question) {
                $subject = isset($numbers[$question['id']]) ? "pertanyaan {$numbers[$question['id']]}" : 'keterangan';
                $key = "blocks.{$blockIndex}.questions.{$questionIndex}";

                if ($source !== null && ! in_array($question['type'], Survey::ROSTER_TYPES, true)) {
                    $validator->errors()->add("{$key}.type", ucfirst($subject).': jenis "'.Survey::QUESTION_TYPES[$question['type']].'" tidak dapat dipakai di blok berulang.');
                }

                $available = $earlierQuestions + $rowScope;
                $this->checkLogic($validator, $question, $available, "{$key}.logic", $subject);
                $this->checkDynamicAnswers($validator, $question, $available, $key, $subject, array_keys($available + $formulaOnly));
                $self = in_array($question['type'], Survey::NON_LOGIC_TYPES, true) ? [] : [$question['id'] => $question];
                $this->checkValidationRules($validator, $question, $available + $self, "{$key}.validation", $subject);

                if ($question['type'] === 'info') {
                    continue;
                }
                if ($source !== null) {
                    if (! in_array($question['type'], Survey::NON_LOGIC_TYPES, true)) {
                        $rowScope[$question['id']] = $question;
                    }
                    $formulaOnly[$question['id']] = $question;

                    continue;
                }
                $earlierAnswerable[$question['id']] = $question;
                if (! in_array($question['type'], Survey::NON_LOGIC_TYPES, true)) {
                    $earlierQuestions[$question['id']] = $question;
                } elseif ($question['type'] === 'list') {
                    $formulaOnly[$question['id']] = $question;
                }
            }
        }
    }

    /**
     * Logic needs rules on earlier questions and at least one action that fits the base flags.
     *
     * @param  array<string, mixed>  $question
     * @param  array<string, array<string, mixed>>  $earlierQuestions
     */
    private function checkLogic(Validator $validator, array $question, array $earlierQuestions, string $key, string $subject): void
    {
        $logic = $question['logic'] ?? null;

        if ($logic === null) {
            return;
        }

        $problem = is_array($logic) ? $this->groupProblem($logic['conditions'] ?? null, $earlierQuestions, 0) : 'tidak valid';
        $actions = $logic['actions'] ?? [];

        if ($problem === null && (! is_array($actions) || $actions === [])) {
            $problem = 'belum memiliki tindakan';
        }
        if ($problem === null && array_diff($actions, self::allowedActions($question)) !== []) {
            $problem = 'memiliki tindakan yang tidak sesuai dengan pengaturan isian';
        }

        if ($problem !== null) {
            $validator->errors()->add($key, 'Logika '.$subject.' '.$problem.'.');
        }
    }

    /**
     * Formulas, answer tags in the text and options taken from another question may only use earlier questions.
     *
     * @param  array<string, mixed>  $question
     * @param  array<string, array<string, mixed>>  $earlierQuestions
     * @param  list<string>  $formulaIds
     */
    private function checkDynamicAnswers(Validator $validator, array $question, array $earlierQuestions, string $key, string $subject, array $formulaIds): void
    {
        $earlierIds = array_keys($earlierQuestions);
        $label = ucfirst($subject);

        if ($question['type'] === 'calc' && ($problem = Formula::problem((string) ($question['formula'] ?? ''), $formulaIds))) {
            $validator->errors()->add("{$key}.formula", "Rumus {$subject} {$problem}.");
        }

        foreach (['label' => 'Teks', 'description' => 'Teks bantuan'] as $field => $name) {
            if (array_diff(SurveyLogic::mentions((string) ($question[$field] ?? '')), $earlierIds) !== []) {
                $validator->errors()->add("{$key}.{$field}", "{$name} {$subject} memakai tag jawaban dari pertanyaan yang tidak ada sebelumnya.");
            }
        }

        if (! in_array($question['type'], Survey::CHOICE_TYPES, true)) {
            return;
        }

        if (Lookup::isLookup($question) && ($problem = $this->lookupProblem($question['lookup'], $earlierQuestions))) {
            $validator->errors()->add("{$key}.lookup", "{$label}: {$problem}.");
        }

        $source = (string) ($question['options_from'] ?? '');

        if ($source !== '' && ($earlierQuestions[$source]['type'] ?? null) !== 'multiple') {
            $validator->errors()->add("{$key}.options_from", "{$label} hanya dapat mengambil pilihan dari pertanyaan pilihan ganda sebelumnya.");
        }

        if ($source === '' && ! empty($question['other_option']) && in_array(Survey::OTHER_VALUE, (array) ($question['options'] ?? []), true)) {
            $validator->errors()->add("{$key}.options", "{$label} sudah memakai opsi \"Lainnya (sebutkan)\" otomatis; hapus pilihan \"".Survey::OTHER_VALUE.'" dari daftar.');
        }
    }

    /**
     * A reference list needs its columns; an API its address and keys; a parent must be an earlier question.
     *
     * @param  array<string, mixed>  $lookup
     * @param  array<string, array<string, mixed>>  $earlierQuestions
     */
    private function lookupProblem(array $lookup, array $earlierQuestions): ?string
    {
        $parent = (string) ($lookup['depends_on'] ?? '');

        if ($parent !== '' && ! isset($earlierQuestions[$parent])) {
            return 'pilihan bertingkat harus bergantung pada pertanyaan sebelumnya';
        }

        if ($lookup['source'] === 'api') {
            return trim((string) ($lookup['url'] ?? '')) === '' ? 'alamat API belum diisi' : null;
        }

        $list = ReferenceList::query()->where('slug', (string) ($lookup['reference'] ?? ''))->first();
        $columns = $list?->columns ?? [];

        return match (true) {
            $list === null => 'data referensi belum dipilih atau sudah dihapus',
            ! in_array($lookup['value_column'] ?? '', $columns, true) => 'kolom nilai (kode) belum dipilih',
            ($lookup['label_column'] ?? '') !== '' && ! in_array($lookup['label_column'], $columns, true) => 'kolom label tidak ada di data referensi',
            $parent !== '' && ! in_array($lookup['filter_column'] ?? '', $columns, true) => 'pilihan bertingkat membutuhkan kolom penyaring',
            default => null,
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $earlierQuestions
     */
    private function groupProblem(mixed $group, array $earlierQuestions, int $depth): ?string
    {
        if (! is_array($group) || ! in_array($group['operator'] ?? null, ['and', 'or'], true) || ! is_array($group['rules'] ?? null)) {
            return 'tidak valid';
        }
        if ($group['rules'] === []) {
            return 'belum memiliki aturan';
        }
        if (count($group['rules']) > SurveyLogic::MAX_RULES) {
            return 'memiliki terlalu banyak aturan';
        }

        foreach ($group['rules'] as $rule) {
            if (! is_array($rule)) {
                return 'tidak valid';
            }

            if (array_key_exists('rules', $rule)) {
                if ($depth >= 1) {
                    return 'hanya boleh berisi satu tingkat grup aturan';
                }
                if ($problem = $this->groupProblem($rule, $earlierQuestions, $depth + 1)) {
                    return $problem;
                }

                continue;
            }

            $operator = $rule['operator'] ?? null;
            $value = trim((string) ($rule['value'] ?? ''));

            if (! is_string($rule['question_id'] ?? null) || ! isset($earlierQuestions[$rule['question_id']])) {
                return 'harus merujuk pertanyaan yang ada sebelumnya';
            }
            if (! is_string($operator) || ! array_key_exists($operator, Survey::CONDITION_OPERATORS)) {
                return 'memakai pembanding yang tidak dikenal';
            }
            if (! in_array($operator, SurveyLogic::VALUELESS_OPERATORS, true) && $value === '') {
                return 'belum memiliki nilai jawaban';
            }
            $mentions = SurveyLogic::mentions($value);

            if (array_diff($mentions, array_keys($earlierQuestions)) !== []) {
                return 'memakai tag jawaban dari pertanyaan yang tidak tersedia';
            }
            if ($problem = SurveyLogic::valueProblem($operator, $value)) {
                return $problem;
            }
            if (mb_strlen($value) > 255) {
                return 'memiliki nilai yang terlalu panjang';
            }
        }

        return null;
    }

    /**
     * The actions that make sense for a question's base flags (e.g. "show" only for a hidden one).
     *
     * @param  array<string, mixed>  $question
     * @return list<string>
     */
    public static function allowedActions(array $question): array
    {
        return [
            ! empty($question['hidden']) ? 'show' : 'hide',
            ! empty($question['disabled']) ? 'enable' : 'disable',
            ! empty($question['required']) ? 'optional' : 'require',
        ];
    }

    /**
     * @param  array<string, mixed>  $question
     */
    private function checkValidationRules(Validator $validator, array $question, array $availableQuestions, string $key, string $subject): void
    {
        foreach ((array) ($question['validation']['checks'] ?? []) as $check) {
            $problem = match (true) {
                ! is_array($check) => 'tidak valid',
                ! array_key_exists($check['level'] ?? '', FieldValidation::CHECK_LEVELS) => 'memiliki tingkat yang tidak dikenal',
                trim((string) ($check['message'] ?? '')) === '' => 'belum memiliki pesan',
                mb_strlen((string) $check['message']) > 255 => 'memiliki pesan yang terlalu panjang',
                default => $this->groupProblem($check['conditions'] ?? null, $availableQuestions, 0),
            };

            if ($problem !== null) {
                $validator->errors()->add($key, 'Pemeriksaan konsistensi '.$subject.' '.$problem.'.');

                return;
            }
        }

        $rules = $question['validation']['rules'] ?? [];
        $allowed = FieldValidation::rulesFor($question['type']);

        foreach ((array) $rules as $rule) {
            $type = is_array($rule) ? ($rule['type'] ?? null) : null;

            if (! is_string($type) || ! isset($allowed[$type])) {
                $validator->errors()->add($key, 'Validasi '.$subject.' memakai aturan yang tidak berlaku untuk jenis isiannya.');

                return;
            }
            if ($problem = FieldValidation::definitionProblem($type, (string) ($rule['value'] ?? ''))) {
                $validator->errors()->add($key, 'Validasi '.$subject.': aturan "'.$allowed[$type]['label'].'" '.$problem.'.');

                return;
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

        if (! in_array($condition['operator'], SurveyLogic::VALUELESS_OPERATORS, true) && trim((string) ($condition['value'] ?? '')) === '') {
            $validator->errors()->add($key, "Logika {$subject} belum memiliki nilai jawaban.");

            return;
        }

        if ($problem = SurveyLogic::valueProblem($condition['operator'], (string) ($condition['value'] ?? ''))) {
            $validator->errors()->add($key, "Logika {$subject} {$problem}.");
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
            'settings.theme_color.required_if' => 'Pilih warna kustom untuk tampilan responden.',
            'settings.theme_color.regex' => 'Warna kustom harus dalam format #RRGGBB.',
            'settings.drive_url.regex' => 'Alamat Google Drive harus alamat Web App Apps Script: https://script.google.com/macros/s/…/exec.',
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
                'theme_color' => $settings['theme'] === 'custom' ? Palette::normalize($settings['theme_color']) : null,
                'numbering' => $settings['numbering'],
                'thank_you_message' => $settings['thank_you_message'] ?? null,
                'confidential' => (bool) $settings['confidential'],
                'sheet_label' => trim((string) ($settings['sheet_label'] ?? '')),
                'sheet_label_position' => $settings['sheet_label_position'] ?? 'left',
                'topline_label' => trim((string) ($settings['topline_label'] ?? '')),
                'topline_label_position' => $settings['topline_label_position'] ?? 'left',
                'form_code_position' => $settings['form_code_position'] ?? 'right',
                'webhook_url' => trim((string) ($settings['webhook_url'] ?? '')),
                'drive_url' => trim((string) ($settings['drive_url'] ?? '')),
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
                // Empty follows the form's orientation; otherwise this block starts its own pages.
                'orientation' => $block['orientation'] ?? '',
                'repeat' => SurveyLogic::repeatSource($block) === null ? null : [
                    'source' => $block['repeat']['source'],
                    'max' => (int) ($block['repeat']['max'] ?? 20),
                    'print_rows' => (int) ($block['repeat']['print_rows'] ?? 3),
                ],
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
            'code_length' => in_array($type, Survey::NO_CODE_TYPES, true) ? 0 : (int) ($question['code_length'] ?? 0),
            'options' => in_array($type, Survey::CHOICE_TYPES, true) ? array_values($question['options'] ?? []) : [],
            'hidden' => (bool) ($question['hidden'] ?? false),
            'disabled' => $type !== 'info' && (bool) ($question['disabled'] ?? false),
            'hide_label' => $type !== 'info' && (bool) ($question['hide_label'] ?? false),
            'placeholder' => in_array($type, Survey::PLACEHOLDER_TYPES, true) ? trim((string) ($question['placeholder'] ?? '')) : '',
            'prefill' => in_array($type, Survey::PREFILL_TYPES, true) ? trim((string) ($question['prefill'] ?? '')) : '',
            'width' => $question['width'] ?? 'full',
            'help_position' => $question['help_position'] ?? 'below',
            'max_length' => in_array($type, ['short', 'long'], true) && ($question['max_length'] ?? null) ? (int) $question['max_length'] : null,
            'options_from' => in_array($type, Survey::CHOICE_TYPES, true) ? trim((string) ($question['options_from'] ?? '')) : '',
            'other_option' => in_array($type, Survey::CHOICE_TYPES, true) && ($question['options_from'] ?? '') === '' && (bool) ($question['other_option'] ?? false),
            'other_label' => trim((string) ($question['other_label'] ?? '')),
            'mask' => $type === 'short' ? trim((string) ($question['mask'] ?? '')) : '',
            'lookup' => Lookup::isLookup($question) ? array_filter([
                'source' => $question['lookup']['source'],
                'reference' => trim((string) ($question['lookup']['reference'] ?? '')),
                'value_column' => trim((string) ($question['lookup']['value_column'] ?? '')),
                'label_column' => trim((string) ($question['lookup']['label_column'] ?? '')),
                'filter_column' => trim((string) ($question['lookup']['filter_column'] ?? '')),
                'url' => trim((string) ($question['lookup']['url'] ?? '')),
                'data_path' => trim((string) ($question['lookup']['data_path'] ?? '')),
                'value_key' => trim((string) ($question['lookup']['value_key'] ?? '')),
                'label_key' => trim((string) ($question['lookup']['label_key'] ?? '')),
                'param' => trim((string) ($question['lookup']['param'] ?? '')),
                'depends_on' => trim((string) ($question['lookup']['depends_on'] ?? '')),
            ], fn (string $value): bool => $value !== '') : null,
            'logic' => $this->normalizeLogic($question['logic'] ?? null),
            'validation' => [
                'rules' => collect($question['validation']['rules'] ?? [])
                    ->filter(fn ($rule): bool => is_array($rule) && isset(FieldValidation::rulesFor($type)[$rule['type'] ?? '']))
                    ->map(fn (array $rule): array => ['type' => $rule['type'], 'value' => trim((string) $rule['value'])])
                    ->values()->all(),
                'message' => trim((string) ($question['validation']['message'] ?? '')),
                'checks' => collect($question['validation']['checks'] ?? [])->map(fn (array $check): array => [
                    'id' => preg_match('/^[A-Za-z0-9_-]{1,40}$/', (string) ($check['id'] ?? '')) ? $check['id'] : 'k-'.Str::lower(Str::random(8)),
                    'level' => $check['level'],
                    'conditions' => $this->normalizeGroup($check['conditions']),
                    'message' => trim((string) $check['message']),
                ])->values()->all(),
            ],
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

        if (in_array($type, Survey::RANGE_TYPES, true)) {
            $normalized['range'] = $this->normalizeRange($question);
        }

        if ($type === 'calc') {
            $normalized['formula'] = trim((string) ($question['formula'] ?? ''));
            $normalized['format'] = $question['format'] ?? 'number';
            $normalized['decimals'] = (int) ($question['decimals'] ?? 0);
        }

        if ($type === 'list') {
            $normalized['list'] = ['max' => (int) ($question['list']['max'] ?? 10)];
        }

        if ($type === 'location') {
            $maxAccuracy = $question['location']['max_accuracy'] ?? null;
            $normalized['location'] = ['max_accuracy' => $maxAccuracy === null || $maxAccuracy === '' ? null : (int) $maxAccuracy];
        }

        if ($type === 'file') {
            $normalized['file'] = [
                'accept' => $question['file']['accept'] ?? 'image',
                'max_mb' => (int) ($question['file']['max_mb'] ?? 5),
            ];
        }

        if ($normalized['options_from'] !== '') {
            $normalized['options'] = [];
        }

        if ($type === 'matrix') {
            $normalized['matrix'] = [
                'rows' => $this->cleanList($question['matrix']['rows'] ?? []),
                'columns' => $this->cleanList($question['matrix']['columns'] ?? []),
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
     * @param  array<string, mixed>|null  $logic
     * @return array<string, mixed>|null
     */
    private function normalizeLogic(?array $logic): ?array
    {
        if (! $logic || ! ($logic['conditions']['rules'] ?? [])) {
            return null;
        }

        return [
            'conditions' => $this->normalizeGroup($logic['conditions']),
            'actions' => array_values(array_unique(array_intersect($logic['actions'], array_keys(SurveyLogic::ACTIONS)))),
        ];
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    private function normalizeGroup(array $group): array
    {
        return [
            'operator' => $group['operator'],
            'rules' => array_map(fn (array $rule): array => isset($rule['rules']) ? $this->normalizeGroup($rule) : [
                'question_id' => $rule['question_id'],
                'operator' => $rule['operator'],
                'value' => in_array($rule['operator'], SurveyLogic::VALUELESS_OPERATORS, true) ? '' : trim((string) ($rule['value'] ?? '')),
            ], $group['rules']),
        ];
    }

    /**
     * The range of a rating, scale or slider. Ratings always count from one star.
     *
     * @param  array<string, mixed>  $question
     * @return array{min: int, max: int, step: int}
     */
    private function normalizeRange(array $question): array
    {
        $range = array_merge(Survey::RANGE_DEFAULTS[$question['type']], array_filter((array) ($question['range'] ?? []), is_numeric(...)));
        $isRating = $question['type'] === 'rating';

        return [
            'min' => $isRating ? 1 : (int) $range['min'],
            'max' => (int) $range['max'],
            'step' => $isRating ? 1 : (int) $range['step'],
        ];
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
            'value' => in_array($condition['operator'], SurveyLogic::VALUELESS_OPERATORS, true) ? '' : trim((string) ($condition['value'] ?? '')),
        ];
    }
}
