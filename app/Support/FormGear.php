<?php

namespace App\Support;

use App\Models\Survey;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Exchange with FormGear, the form engine behind BPS FASIH: a template (sections, components,
 * enableCondition, expressions, nested inputs, sourceOption/sourceAPI), a validation file
 * (testFunctions) and a preset (predata).
 *
 * Import turns them into this app's questionnaire; the simple JavaScript conditions FormGear uses
 * (getValue('x') == 'A' && Number(getValue('y')) > 5 …) become logic and consistency checks. What
 * cannot be carried over is listed in the notes. Export writes this app's questionnaire as FormGear
 * files, so it can be opened by FormGear-based apps.
 */
class FormGear
{
    /**
     * FormGear control types (src/types/enums.ts) and the closest type here.
     */
    private const IMPORT_TYPES = [
        3 => 'info', 4 => 'calc', 11 => 'date', 12 => 'date', 13 => 'time', 14 => 'short', 15 => 'short',
        16 => 'checkbox', 17 => 'checkbox', 18 => 'slider', 19 => 'url', 20 => 'currency', 21 => 'list',
        22 => 'multiple', 23 => 'multiple', 24 => 'short', 25 => 'short', 26 => 'single', 27 => 'dropdown',
        28 => 'number', 29 => 'multiple', 30 => 'long', 31 => 'email', 32 => 'file', 33 => 'location',
        34 => 'file', 35 => 'time', 36 => 'signature', 37 => 'number', 38 => 'number', 39 => 'file',
        40 => 'short', 41 => 'file', 42 => 'file',
    ];

    private const OPERATORS = ['==' => 'equals', '===' => 'equals', '!=' => 'not_equals', '!==' => 'not_equals', '>' => 'greater_than', '<' => 'less_than', '>=' => 'greater_or_equal', '<=' => 'less_or_equal'];

    /** @var array<string, string> FormGear dataKey => question id here */
    private array $ids = [];

    /** @var array<string, array<string, mixed>> question id => converted question (with its FormGear options) */
    private array $seen = [];

    /** @var array<string, string> question id => block id */
    private array $blockOf = [];

    /** @var array<string, bool> block id => repeats */
    private array $repeating = [];

    /** @var list<array<string, mixed>> */
    private array $blocks = [];

    /** @var list<string> */
    private array $notes = [];

    public static function isTemplate(mixed $data): bool
    {
        return is_array($data) && isset($data['components'], $data['dataKey']) && is_array($data['components']);
    }

    public static function isValidation(mixed $data): bool
    {
        return is_array($data) && is_array($data['testFunctions'] ?? null);
    }

    public static function isPreset(mixed $data): bool
    {
        return is_array($data) && is_array($data['predata'] ?? null);
    }

    // ── Import ────────────────────────────────────────────────────────────

    /**
     * A questionnaire definition (as the builder posts it) plus notes on what was simplified.
     *
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>|null  $validation
     * @param  array<string, mixed>|null  $preset
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public static function import(array $template, ?array $validation = null, ?array $preset = null): array
    {
        if (! self::isTemplate($template)) {
            throw new InvalidArgumentException('File bukan template FormGear (butuh "dataKey" dan "components").');
        }

        // Exported from this app: the original questionnaire travels with the template, so nothing is lost.
        if (is_array($template['x-ruang-kuesioner']['survey'] ?? null)) {
            return [$template['x-ruang-kuesioner']['survey'], ['Template FormGear ini dibuat oleh Ruang Kuesioner; kuesioner aslinya dipulihkan utuh.']];
        }

        $converter = new self;
        $converter->readComponents($template['components']);
        $converter->applyValidation($validation['testFunctions'] ?? []);
        $converter->applyPreset($preset['predata'] ?? []);
        $converter->finish();

        $title = self::text($template['title'] ?? '') ?: self::text($template['description'] ?? '') ?: 'Kuesioner FormGear';

        return [[
            'title' => Str::limit($title, 160, ''),
            'form_code' => Str::limit(self::text($template['acronym'] ?? ''), 40, ''),
            'subtitle' => Str::limit(self::text($template['description'] ?? ''), 500, ''),
            'activity' => Str::limit($title, 255, ''),
            'settings' => Survey::DEFAULT_SETTINGS,
            'header' => ['items' => Survey::defaultHeaderItems()],
            'intro' => Survey::DEFAULT_INTRO,
            'blocks' => $converter->blocks,
        ], $converter->notes];
    }

    /**
     * @param  array<int, mixed>  $components
     */
    private function readComponents(array $components): void
    {
        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }
            // The template's components are a list of section lists.
            if (array_is_list($component)) {
                $this->readComponents($component);

                continue;
            }

            if ((int) ($component['type'] ?? 0) === 1) {
                $this->readSection($component);
            } else {
                // Loose components outside any section get a block of their own.
                $this->openBlock('Isian', null);
                $this->readItem($component);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function readSection(array $section): void
    {
        $title = self::text($section['label'] ?? '') ?: 'Blok';
        $condition = $this->condition($section['enableCondition'] ?? null, null, 'Blok "'.$title.'"');
        // A block shows on one simple condition; anything richer is given to each of its questions.
        $simple = $condition !== null && count($condition['rules']) === 1 && ! isset($condition['rules'][0]['rules']) ? $condition['rules'][0] : null;
        $this->openBlock($title, $simple, null, $simple === null ? $condition : null);

        if (self::text($section['description'] ?? '') !== '') {
            $this->addQuestion(['id' => $this->id('info_'.($section['dataKey'] ?? 'blok')), 'type' => 'info', 'label' => Str::limit(self::text($section['description']), 255, ''), 'description' => '']);
        }

        foreach ($section['components'] ?? [] as $item) {
            if (is_array($item)) {
                array_is_list($item) ? array_map(fn ($inner) => is_array($inner) ? $this->readItem($inner) : null, $item) : $this->readItem($item);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function readItem(array $item): void
    {
        $type = (int) ($item['type'] ?? 0);

        if ($type === 1) {
            $this->readSection($item);

            return;
        }
        if ($type === 2) {
            $this->readNested($item);

            return;
        }

        $question = $this->convertComponent($item);
        if ($question !== null) {
            $this->addQuestion($question);
        }
    }

    /**
     * A nested input becomes a repeating block, then the rest of the section continues after it.
     *
     * @param  array<string, mixed>  $nested
     */
    private function readNested(array $nested): void
    {
        $current = end($this->blocks);
        $source = $this->ids[(string) ($nested['sourceQuestion'] ?? '')] ?? null;
        $sourceType = $source ? ($this->seen[$source]['type'] ?? null) : null;
        $title = self::text($nested['label'] ?? '') ?: (self::text($nested['description'] ?? '') ?: 'Rincian');
        $canRepeat = $source && in_array($sourceType, Survey::ROSTER_SOURCE_TYPES, true) && ! ($this->repeating[$this->blockOf[$source] ?? ''] ?? false);

        if (! $canRepeat) {
            $this->notes[] = "Rincian \"{$title}\" tidak dapat dijadikan blok berulang (sumber \"".($nested['sourceQuestion'] ?? '?').'" bukan angka/pilihan ganda/daftar di luar rincian lain); diubah menjadi blok biasa.';
        }

        $this->openBlock(Str::upper($title), null, $canRepeat ? ['source' => $source, 'max' => Survey::MAX_ROSTER_ROWS, 'print_rows' => 3] : null);
        foreach ($nested['components'] ?? [] as $item) {
            if (is_array($item)) {
                array_is_list($item) ? array_map(fn ($inner) => is_array($inner) ? $this->readItem($inner) : null, $item) : $this->readItem($item);
            }
        }

        // Questions after the nested input go on in a continuation of the section.
        if ($current) {
            $this->openBlock(Str::limit($current['title'].' (LANJUTAN)', 160, ''), $current['condition'], null, $current['question_logic']);
        }
    }

    /**
     * @param  array<string, mixed>|null  $condition
     * @param  array<string, mixed>|null  $repeat
     */
    private function openBlock(string $title, ?array $condition, ?array $repeat = null, ?array $questionLogic = null): void
    {
        $last = end($this->blocks);
        // Reuse an empty block rather than leaving it behind.
        if ($last && $last['questions'] === [] && ! $last['repeat']) {
            array_pop($this->blocks);
        }

        $id = 'b-'.Str::lower(Str::random(8));
        $this->repeating[$id] = $repeat !== null;
        $this->blocks[] = ['id' => $id, 'title' => Str::limit(Str::upper($title), 160, ''), 'layout' => 'full', 'orientation' => '', 'repeat' => $repeat, 'condition' => $condition, 'questions' => [], 'question_logic' => $questionLogic];
    }

    /**
     * @param  array<string, mixed>  $question
     */
    private function addQuestion(array $question): void
    {
        if ($this->blocks === []) {
            $this->openBlock('Isian', null);
        }

        $index = array_key_last($this->blocks);
        $block = $this->blocks[$index];

        if ($block['repeat'] && ! in_array($question['type'], Survey::ROSTER_TYPES, true)) {
            $this->notes[] = 'Isian "'.$question['label'].'" ('.Survey::QUESTION_TYPES[$question['type']].') tidak dapat berada di blok berulang; diubah menjadi jawaban singkat.';
            $question = ['type' => 'short'] + $question;
        }

        // The section's own condition applies to each question (joined with the question's own, when simple).
        $sectionLogic = $block['question_logic'] ?? null;
        if ($sectionLogic !== null) {
            $own = $question['logic']['conditions'] ?? null;
            $flat = fn (array $group): bool => $group['operator'] === 'and' && ! collect($group['rules'])->contains(fn (array $rule): bool => isset($rule['rules']));
            if ($own === null) {
                $question['hidden'] = true;
                $question['logic'] = ['conditions' => $sectionLogic, 'actions' => ['show']];
            } elseif ($flat($own) && $flat($sectionLogic)) {
                $question['logic']['conditions'] = ['operator' => 'and', 'rules' => [...$sectionLogic['rules'], ...$own['rules']]];
            } else {
                $this->notes[] = 'Syarat blok "'.$block['title'].'" tidak dapat digabung dengan syarat isian "'.$question['label'].'"; hanya syarat isian yang dipakai.';
            }
        }

        $this->blocks[$index]['questions'][] = $question;
        $this->blockOf[$question['id']] = $block['id'];
        $this->seen[$question['id']] = $question;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function convertComponent(array $item): ?array
    {
        $fgType = (int) ($item['type'] ?? 0);
        $type = self::IMPORT_TYPES[$fgType] ?? null;
        $dataKey = (string) ($item['dataKey'] ?? '');
        $label = Str::limit(str_replace('$NAME$', Survey::ROW_TOKEN, self::text($item['label'] ?? '')), 255, '') ?: $dataKey;

        if ($type === null || $dataKey === '') {
            $this->notes[] = "Komponen \"{$label}\" (jenis FormGear {$fgType}) dilewati karena tidak dikenal.";

            return null;
        }

        $id = $this->id($dataKey);
        $question = [
            'id' => $id,
            'type' => $type,
            'label' => $type === 'info' ? 'Keterangan' : $label,
            'description' => Str::limit(self::text(($item['hint'] ?? '') ?: ($item['description'] ?? '')), 1000, ''),
            'required' => (bool) ($item['required'] ?? false),
            'code_length' => 0,
            'options' => [],
            'validation' => ['rules' => [], 'message' => '', 'checks' => []],
        ];

        if ($type === 'info') {
            $question['description'] = Str::limit(self::text($item['label'] ?? ''), 1000, '');
        }

        // Options with their FormGear codes, so conditions on codes can be read back as labels.
        $options = [];
        foreach ((array) ($item['options'] ?? []) as $option) {
            // Options without a label (e.g. rating stars) are named by their value.
            $optionLabel = is_array($option) ? (self::text($option['label'] ?? '') ?: self::text($option['value'] ?? '')) : '';
            if ($optionLabel !== '') {
                if (! empty($option['open'])) {
                    $question['other_option'] = true;
                    $question['other_label'] = Str::limit($optionLabel.' (sebutkan)', 60, '');

                    continue;
                }
                $options[(string) ($option['value'] ?? '')] = Str::limit($optionLabel, 255, '');
            }
        }
        $question['fg_options'] = $options;

        match (true) {
            in_array($type, Survey::CHOICE_TYPES, true) => $this->convertChoices($question, $item, $options),
            $fgType === 4 => $this->convertExpression($question, $item),
            $fgType === 18 => $question['range'] = self::range($item, 0, 100),
            $fgType === 24 => $question['mask'] = Str::limit((string) ($item['maskingFormat'] ?? ''), Survey::MAX_MASK_LENGTH, ''),
            $fgType === 20 && strtoupper((string) ($item['currency'] ?? 'IDR')) !== 'IDR' => $question['type'] = 'number',
            in_array($fgType, [32, 34, 39, 41, 42], true) => $question['file'] = ['accept' => $fgType === 32 ? 'image' : 'any', 'max_mb' => 5],
            default => null,
        };

        if (in_array($fgType, [12, 14, 15, 35, 37, 40], true)) {
            $this->notes[] = "Isian \"{$label}\" (FormGear jenis {$fgType}) disederhanakan menjadi ".Survey::QUESTION_TYPES[$question['type']].'.';
        }

        $this->convertRanges($question, $item);

        $logic = $this->condition($item['enableCondition'] ?? null, $id, 'Isian "'.$label.'"');
        if ($logic !== null) {
            $question['hidden'] = true;
            $question['logic'] = ['conditions' => $logic, 'actions' => ['show']];
        }

        return $question;
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $item
     * @param  array<string, string>  $options
     */
    private function convertChoices(array &$question, array $item, array $options): void
    {
        $typeOption = (int) ($item['typeOption'] ?? 1);
        $question['options'] = array_values(array_unique($options));
        $question['code_length'] = $question['type'] === 'multiple' ? 0 : max(1, strlen((string) max(1, count($options))));

        if ($typeOption === 3) {
            $source = $this->ids[(string) ($item['sourceOption'] ?? '')] ?? null;
            if ($source && ($this->seen[$source]['type'] ?? null) === 'multiple') {
                $question['options_from'] = $source;
                $question['options'] = [];
            } else {
                $this->notes[] = "Pilihan \"{$question['label']}\" diambil dari \"".($item['sourceOption'] ?? '?').'" yang tidak berupa pilihan ganda; isi pilihannya secara manual.';
            }
        }

        $api = $item['sourceAPI'][0] ?? null;
        if ($typeOption === 2 && is_array($api)) {
            if ($question['type'] !== 'dropdown') {
                $this->notes[] = "Isian \"{$question['label']}\" mengambil pilihan dari API; di sini diubah menjadi Daftar pilihan.";
                $question['type'] = 'dropdown';
            }
            $filter = $api['filterDependencies'][0] ?? $api['subResourceDependencies'][0] ?? null;
            $parent = is_array($filter) ? ($this->ids[(string) ($filter['sourceAnswer'] ?? '')] ?? null) : null;
            $url = (string) ($api['baseUrl'] ?? '');
            if ($parent && isset($api['subResourceDependencies'])) {
                $url = rtrim($url, '/').'/{value}/'.ltrim((string) ($filter['params'] ?? ''), '/');
            }
            $question['lookup'] = array_filter([
                'source' => 'api',
                'url' => $url,
                'data_path' => (string) ($api['data'] ?? ''),
                'value_key' => (string) ($api['value'] ?? 'id'),
                'label_key' => (string) ($api['label'] ?? ''),
                'depends_on' => $parent ?? '',
                'param' => $parent && isset($api['filterDependencies']) ? (string) ($filter['params'] ?? '') : '',
            ], fn (string $value): bool => $value !== '');
            $question['options'] = [];
        }

        if ($question['options'] === [] && ($question['options_from'] ?? '') === '' && ! isset($question['lookup'])) {
            $question['options'] = ['Pilihan 1', 'Pilihan 2'];
            $this->notes[] = "Isian \"{$question['label']}\" tidak memiliki pilihan; diisi contoh pilihan.";
        }
    }

    /**
     * FormGear's VariableInput holds JavaScript; simple sums and comparisons of answers become a formula.
     *
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $item
     */
    private function convertExpression(array &$question, array $item): void
    {
        $expression = trim((string) ($item['expression'] ?? ''), " ;\t\n");
        $formula = preg_replace_callback(
            '/(?:Number\(\s*)?getValue\(\s*[\'"]([^\'"]+)[\'"]\s*\)(?:\s*\))?/',
            fn (array $match): string => isset($this->ids[self::baseKey($match[1])]) ? '{{'.$this->ids[self::baseKey($match[1])].'}}' : '{{?}}',
            $expression,
        );
        $formula = str_replace(['Math.round(', 'Math.floor(', 'Math.ceil(', 'Math.abs(', 'Math.max(', 'Math.min('], ['round(', 'floor(', 'ceil(', 'abs(', 'max(', 'min('], (string) $formula);

        if ($formula !== '' && Formula::problem($formula, array_keys($this->seen)) === null) {
            $question['formula'] = $formula;
            $question['format'] = 'number';
            $question['decimals'] = 2;

            return;
        }

        $this->notes[] = "Rumus \"{$question['label']}\" (".Str::limit($expression, 80).') tidak dapat diterjemahkan; isian dijadikan teks yang tidak dapat diubah.';
        $question['type'] = 'short';
        $question['disabled'] = true;
    }

    /**
     * Range and length limits become validation rules.
     *
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $item
     */
    private function convertRanges(array &$question, array $item): void
    {
        $range = $item['rangeInput'][0] ?? null;
        if (is_array($range) && in_array($question['type'], ['number', 'currency'], true)) {
            foreach (['min' => 'min_value', 'max' => 'max_value'] as $bound => $rule) {
                if (is_numeric($range[$bound] ?? null)) {
                    $question['validation']['rules'][] = ['type' => $rule, 'value' => (string) $range[$bound]];
                }
            }
        }
        if (is_array($range) && $question['type'] === 'date') {
            foreach (['min' => 'after_date', 'max' => 'before_date'] as $bound => $rule) {
                if (is_string($range[$bound] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $range[$bound])) {
                    $question['validation']['rules'][] = ['type' => $rule, 'value' => $range[$bound]];
                }
            }
        }

        $length = $item['lengthInput'][0] ?? null;
        if (is_array($length) && in_array($question['type'], ['short', 'long'], true)) {
            foreach (['minlength' => 'min_length', 'maxlength' => 'max_length'] as $bound => $rule) {
                if (is_numeric($length[$bound] ?? null) && (int) $length[$bound] > 0) {
                    $question['validation']['rules'][] = ['type' => $rule, 'value' => (string) (int) $length[$bound]];
                }
            }
        }
    }

    /**
     * @param  list<mixed>  $testFunctions
     */
    private function applyValidation(array $testFunctions): void
    {
        foreach ($testFunctions as $function) {
            $id = $this->ids[self::baseKey((string) ($function['dataKey'] ?? ''))] ?? null;
            if (! $id || ! is_array($function['validations'] ?? null)) {
                continue;
            }

            foreach ($function['validations'] as $index => $validation) {
                $test = trim((string) ($validation['test'] ?? ''));
                $message = Str::limit(self::text($validation['message'] ?? '') ?: 'Jawaban tidak konsisten.', 255, '');
                // FormGear: 1 = warning, 2 = error.
                $level = (int) ($validation['type'] ?? 2) === 1 ? 'warning' : 'error';
                $self = $this->seen[$id];

                // "getValue('x') == ''" or "getValue('x').length == 0" on its own means the answer is required.
                if (preg_match('/^getValue\(\s*[\'"]'.preg_quote((string) $function['dataKey'], '/').'[^\'"]*[\'"]\s*\)(?:\.length\s*(?:===?|<)\s*[01]|\s*===?\s*(?:\'\'|""|undefined|null))$/', $test) && $level === 'error') {
                    $this->update($id, fn (array $question): array => ['required' => true] + $question);

                    continue;
                }

                // "getValue('x').length < 5" on a text answer is a minimum length.
                if (preg_match('/^getValue\(\s*[\'"][^\'"]+[\'"]\s*\)\.length\s*(<|>)\s*(\d+)$/', $test, $match) && in_array($self['type'], ['short', 'long'], true) && $level === 'error') {
                    $rule = $match[1] === '<' ? ['type' => 'min_length', 'value' => $match[2]] : ['type' => 'max_length', 'value' => $match[2]];
                    $this->update($id, function (array $question) use ($rule, $message): array {
                        $question['validation']['rules'][] = $rule;
                        $question['validation']['message'] = $message;

                        return $question;
                    });

                    continue;
                }

                $conditions = $this->condition($test, $id, 'Validasi "'.$self['label'].'"', includeSelf: true);
                if ($conditions === null) {
                    continue;
                }

                $this->update($id, function (array $question) use ($conditions, $message, $level, $index): array {
                    $question['validation']['checks'][] = ['id' => 'k-'.Str::lower(Str::random(6)).$index, 'level' => $level, 'conditions' => $conditions, 'message' => $message];

                    return $question;
                });
            }
        }
    }

    /**
     * @param  list<mixed>  $predata
     */
    private function applyPreset(array $predata): void
    {
        foreach ($predata as $entry) {
            $id = $this->ids[(string) ($entry['dataKey'] ?? '')] ?? null;
            $answer = $entry['answer'] ?? null;
            if (! $id || ! in_array($this->seen[$id]['type'], Survey::PREFILL_TYPES, true)) {
                continue;
            }
            if (is_array($answer)) {
                $answer = $answer[0]['label'] ?? null;
            }
            if (is_scalar($answer) && (string) $answer !== '') {
                $this->update($id, fn (array $question): array => ['prefill' => Str::limit((string) $answer, 255, '')] + $question);
            }
        }
    }

    /**
     * Drops what the builder would reject (helper keys, empty blocks) so the import always loads.
     */
    private function finish(): void
    {
        $this->blocks = array_values(array_filter($this->blocks, fn (array $block): bool => $block['questions'] !== []));

        foreach ($this->blocks as &$block) {
            unset($block['question_logic']);
            foreach ($block['questions'] as &$question) {
                unset($question['fg_options']);
            }
        }
        unset($block, $question);

        if (! collect($this->blocks)->flatMap(fn (array $block): array => $block['questions'])->contains(fn (array $question): bool => $question['type'] !== 'info')) {
            throw new InvalidArgumentException('Template FormGear tidak berisi isian yang bisa diimpor.');
        }
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    private function update(string $id, callable $change): void
    {
        foreach ($this->blocks as $blockIndex => $block) {
            foreach ($block['questions'] as $questionIndex => $question) {
                if ($question['id'] === $id) {
                    $this->blocks[$blockIndex]['questions'][$questionIndex] = $change($question);
                    $this->seen[$id] = $this->blocks[$blockIndex]['questions'][$questionIndex];

                    return;
                }
            }
        }
    }

    /**
     * Reads FormGear's JavaScript conditions: comparisons of getValue('x') (optionally Number(…),
     * [0].value or .length) with a literal, joined by && and || (one level of grouping).
     * Returns null — with a note — when the condition is anything else.
     *
     * @return array<string, mixed>|null
     */
    private function condition(mixed $expression, ?string $selfId, string $subject, bool $includeSelf = false): ?array
    {
        $expression = trim((string) $expression);
        if ($expression === '') {
            return null;
        }

        $groups = [];
        foreach (self::splitTop(self::unwrap($expression), '||') as $part) {
            $rules = [];
            foreach (self::splitTop(self::unwrap($part), '&&') as $atom) {
                $rule = $this->atom(self::unwrap($atom), $selfId, $includeSelf);
                if ($rule === null) {
                    $this->notes[] = "{$subject}: syarat \"".Str::limit($expression, 120).'" tidak dapat diterjemahkan dan dilewati.';

                    return null;
                }
                $rules[] = $rule;
            }
            $groups[] = $rules;
        }

        if (count($groups) === 1) {
            return ['operator' => 'and', 'rules' => $groups[0]];
        }

        return ['operator' => 'or', 'rules' => array_map(fn (array $rules): array => count($rules) === 1 ? $rules[0] : ['operator' => 'and', 'rules' => $rules], $groups)];
    }

    /**
     * @return array{question_id: string, operator: string, value: string}|null
     */
    private function atom(string $atom, ?string $selfId, bool $includeSelf): ?array
    {
        $pattern = '/^(?:Number\(\s*)?getValue\(\s*[\'"]([^\'"]+)[\'"]\s*\)(?:\s*\))?\s*(\[0\]\.value|\.length)?\s*(===|==|!==|!=|>=|<=|>|<)\s*(.+)$/';
        if (! preg_match($pattern, $atom, $match)) {
            return null;
        }

        [, $key, $accessor, $operator, $literal] = $match;
        $id = $this->ids[self::baseKey($key)] ?? null;
        $question = $id ? ($this->seen[$id] ?? null) : null;

        // Rules may only use earlier questions (or the question itself in a check), in the same
        // repeating block when they are in one.
        if (! $question || ($id === $selfId && ! $includeSelf) || in_array($question['type'], Survey::NON_LOGIC_TYPES, true)) {
            return null;
        }
        $currentBlock = end($this->blocks)['id'] ?? null;
        if (($this->repeating[$this->blockOf[$id]] ?? false) && $this->blockOf[$id] !== $currentBlock && $id !== $selfId) {
            return null;
        }

        $literal = trim($literal);
        $value = match (true) {
            (bool) preg_match('/^([\'"])(.*)\1$/s', $literal, $string) => $string[2],
            is_numeric($literal) => $literal,
            in_array($literal, ['true', 'false', 'null', 'undefined'], true) => $literal,
            default => null,
        };
        if ($value === null) {
            return null;
        }

        $equality = in_array($operator, ['==', '===', '!=', '!=='], true);
        $negated = in_array($operator, ['!=', '!=='], true);

        if ($accessor === '.length') {
            if (! is_numeric($value)) {
                return null;
            }
            $empty = ($equality && (int) $value === 0 && ! $negated) || ($operator === '<' && (int) $value === 1);
            $answered = ($operator === '>' && (int) $value === 0) || ($operator === '>=' && (int) $value === 1) || ($negated && (int) $value === 0);

            return $empty || $answered ? ['question_id' => $id, 'operator' => $empty ? 'empty' : 'answered', 'value' => ''] : null;
        }

        if (in_array($value, ['', 'null', 'undefined'], true)) {
            return $equality ? ['question_id' => $id, 'operator' => $negated ? 'answered' : 'empty', 'value' => ''] : null;
        }

        if (in_array($value, ['true', 'false'], true)) {
            $ticked = ($value === 'true') !== $negated;

            return $question['type'] === 'checkbox'
                ? ['question_id' => $id, 'operator' => $ticked ? 'equals' : 'empty', 'value' => $ticked ? Survey::CHECKBOX_VALUE : '']
                : ['question_id' => $id, 'operator' => $ticked ? 'answered' : 'empty', 'value' => ''];
        }

        // A choice is compared by its FormGear code; here answers are the option labels.
        $value = $question['fg_options'][$value] ?? $value;

        if (in_array(self::OPERATORS[$operator], SurveyLogic::NUMERIC_OPERATORS, true) && ! is_numeric($value)) {
            return null;
        }

        return ['question_id' => $id, 'operator' => self::OPERATORS[$operator], 'value' => Str::limit($value, 255, '')];
    }

    /**
     * Splits on an operator that is not inside brackets or quotes.
     *
     * @return list<string>
     */
    private static function splitTop(string $expression, string $operator): array
    {
        $parts = [];
        $depth = 0;
        $quote = null;
        $current = '';
        $length = strlen($expression);

        for ($index = 0; $index < $length; $index++) {
            $character = $expression[$index];
            if ($quote !== null) {
                $quote = $character === $quote ? null : $quote;
            } elseif ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif ($character === '(' || $character === '[') {
                $depth++;
            } elseif ($character === ')' || $character === ']') {
                $depth--;
            } elseif ($depth === 0 && substr($expression, $index, 2) === $operator) {
                $parts[] = $current;
                $current = '';
                $index++;

                continue;
            }
            $current .= $character;
        }
        $parts[] = $current;

        return array_map('trim', $parts);
    }

    /**
     * Removes brackets around a whole expression: "(a && b)" → "a && b".
     */
    private static function unwrap(string $expression): string
    {
        $expression = trim($expression);

        while (str_starts_with($expression, '(') && str_ends_with($expression, ')')) {
            $depth = 0;
            $wraps = true;
            for ($index = 0; $index < strlen($expression) - 1; $index++) {
                $depth += $expression[$index] === '(' ? 1 : ($expression[$index] === ')' ? -1 : 0);
                if ($depth === 0) {
                    $wraps = false;
                    break;
                }
            }
            if (! $wraps) {
                break;
            }
            $expression = trim(substr($expression, 1, -1));
        }

        return $expression;
    }

    /**
     * "frec_coffee@$ROW$" and "frec_coffee#1" name the same question as "frec_coffee".
     */
    private static function baseKey(string $key): string
    {
        return (string) preg_replace('/(@\$ROW\d*\$|#.*)$/', '', $key);
    }

    private function id(string $dataKey): string
    {
        $base = Str::limit(trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', $dataKey), '_') ?: 'isian', 70, '');
        $id = $base;
        for ($index = 2; in_array($id, $this->ids, true); $index++) {
            $id = $base.'_'.$index;
        }

        return $this->ids[$dataKey] = $id;
    }

    /**
     * Plain text from FormGear labels, which may hold HTML.
     */
    private static function text(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $text = html_entity_decode(strip_tags((string) preg_replace('/<(style|script)\b.*?<\/\1>/is', '', (string) $value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{min: int, max: int, step: int}
     */
    private static function range(array $item, int $min, int $max): array
    {
        $range = $item['rangeInput'][0] ?? [];

        return [
            'min' => is_numeric($range['min'] ?? null) ? (int) $range['min'] : $min,
            'max' => is_numeric($range['max'] ?? null) ? (int) $range['max'] : $max,
            'step' => is_numeric($range['step'] ?? null) && (int) $range['step'] > 0 ? (int) $range['step'] : 1,
        ];
    }

    // ── Export ────────────────────────────────────────────────────────────

    /**
     * This questionnaire as FormGear files: template, validation and an empty preset, plus notes on
     * what FormGear cannot express (e.g. "require" or "disable" logic).
     *
     * @return array{template: array<string, mixed>, validation: array<string, mixed>, preset: array<string, mixed>, notes: list<string>}
     */
    public static function export(Survey $survey): array
    {
        $exporter = new FormGearExporter($survey);

        return $exporter->export();
    }
}
