<?php

namespace App\Support;

use App\Models\Survey;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Writes a questionnaire as FormGear files (template, validation, preset). Display logic becomes
 * enableCondition, formulas become VariableInput expressions, repeating blocks become NestedInput,
 * consistency checks and answer rules become testFunctions (1 = warning, 2 = error).
 */
class FormGearExporter
{
    private const EXPORT_TYPES = [
        'short' => 25, 'long' => 30, 'single' => 26, 'multiple' => 29, 'dropdown' => 27, 'number' => 28,
        'currency' => 20, 'date' => 11, 'time' => 13, 'email' => 31, 'phone' => 25, 'url' => 19, 'code' => 25,
        'checkbox' => 16, 'rating' => 26, 'scale' => 26, 'slider' => 18, 'signature' => 36, 'calc' => 4,
        'location' => 33, 'file' => 32, 'list' => 21, 'info' => 3,
    ];

    /** @var array<string, array<string, mixed>> */
    private array $questions = [];

    /** @var array<string, string> question id => id of its repeating block */
    private array $rosterOf = [];

    /** @var list<array<string, mixed>> */
    private array $testFunctions = [];

    /** @var list<string> */
    private array $notes = [];

    public function __construct(private Survey $survey)
    {
        foreach ($survey->blocks ?? [] as $block) {
            foreach ($block['questions'] as $question) {
                $this->questions[$question['id']] = $question;
                if (SurveyLogic::repeatSource($block) !== null) {
                    $this->rosterOf[$question['id']] = $block['id'];
                }
            }
        }
    }

    /**
     * @return array{template: array<string, mixed>, validation: array<string, mixed>, preset: array<string, mixed>, notes: list<string>}
     */
    public function export(): array
    {
        $dataKey = Str::slug($this->survey->form_code ?: $this->survey->title, '_') ?: 'kuesioner';
        $sections = [];
        $lastSection = null;

        foreach ($this->survey->blocks ?? [] as $blockIndex => $block) {
            $source = SurveyLogic::repeatSource($block);
            $components = [];

            foreach ($block['questions'] as $question) {
                $components = [...$components, ...$this->components($question, $source !== null)];
            }

            $title = 'BLOK '.Survey::roman($blockIndex + 1).'. '.$block['title'];

            // A repeating block is a nested input inside the section before it.
            if ($source !== null && $lastSection !== null) {
                $sections[$lastSection]['components'][] = [
                    'label' => $title,
                    'dataKey' => $block['id'],
                    'type' => 2,
                    'description' => $block['title'],
                    'sourceQuestion' => $source,
                    'components' => [$components],
                ];

                continue;
            }

            $section = ['label' => $title, 'dataKey' => $block['id'], 'type' => 1, 'description' => '', 'components' => $components];
            if ($condition = $block['condition'] ?? null) {
                [$expression, $keys] = $this->expression(['operator' => 'and', 'rules' => [$condition]], null);
                $section += ['enableCondition' => $expression, 'componentEnable' => $keys];
            }
            $sections[] = $section;
            $lastSection = array_key_last($sections);
        }

        return [
            'template' => [
                'description' => $this->survey->subtitle ?: $this->survey->activity,
                'dataKey' => $dataKey,
                'title' => $this->survey->title,
                'acronym' => $this->survey->form_code ?: $dataKey,
                'version' => '1.0.0',
                'components' => [$sections],
                // FormGear ignores this; importing the file back here restores the questionnaire exactly.
                'x-ruang-kuesioner' => ['format' => SurveyJson::FORMAT, 'version' => SurveyJson::VERSION, 'survey' => SurveyJson::definitionData($this->survey)],
            ],
            'validation' => ['description' => $this->survey->title, 'dataKey' => $dataKey, 'version' => '1.0.0', 'testFunctions' => $this->testFunctions],
            'preset' => ['description' => $this->survey->title, 'dataKey' => $dataKey, 'predata' => $this->predata()],
            'notes' => $this->notes,
        ];
    }

    /**
     * One question as FormGear components (a region or matrix becomes several).
     *
     * @param  array<string, mixed>  $question
     * @return list<array<string, mixed>>
     */
    private function components(array $question, bool $inRoster): array
    {
        $label = str_replace(Survey::ROW_TOKEN, '$NAME$', Piping::text($question['label'], $this->survey->questionNumbers()));

        if ($question['type'] === 'region') {
            return array_map(fn (array $field): array => [
                'label' => $field['label'],
                'dataKey' => $question['id'].'_'.$field['key'],
                'type' => 25,
                ...($field['mode'] === 'fixed' ? ['answer' => Survey::fixedRegionValue($field)] : []),
            ], Survey::regionFields($question));
        }
        if ($question['type'] === 'matrix') {
            return array_map(fn (string $row, int $index): array => [
                'label' => $label.' — '.$row,
                'dataKey' => $question['id'].'_'.($index + 1),
                'type' => 26,
                'typeOption' => 1,
                'options' => array_map(fn (string $column, int $column_index): array => ['label' => $column, 'value' => (string) ($column_index + 1)], $question['matrix']['columns'], array_keys($question['matrix']['columns'])),
            ], $question['matrix']['rows'], array_keys($question['matrix']['rows']));
        }
        if ($question['type'] === 'table') {
            $this->notes[] = "Tabel \"{$question['label']}\" ditulis sebagai keterangan; FormGear memakai rincian (nested) untuk tabel.";

            return [['label' => '<b>'.e($label).'</b> — kolom: '.e(implode(', ', array_column($question['table']['columns'], 'label'))), 'dataKey' => $question['id'], 'type' => 3]];
        }

        $component = [
            'label' => $question['type'] === 'info' ? '<b>'.e($label).'</b><br>'.e($question['description']) : $label,
            'dataKey' => $question['id'],
            'type' => self::EXPORT_TYPES[$question['type']] ?? 25,
        ];

        if ($question['type'] !== 'info' && $question['description'] !== '') {
            $component['hint'] = $question['description'];
        }
        if ($question['required']) {
            $component['required'] = true;
        }

        match (true) {
            in_array($question['type'], Survey::CHOICE_TYPES, true) => $this->choiceComponent($component, $question),
            in_array($question['type'], ['rating', 'scale'], true) => $component += ['typeOption' => 1, 'options' => array_map(fn (int $value): array => ['label' => $question['type'] === 'rating' ? '' : (string) $value, 'value' => (string) $value], Survey::rangeValues($question))],
            $question['type'] === 'slider' => $component['rangeInput'] = [['min' => $question['range']['min'], 'max' => $question['range']['max'], 'step' => $question['range']['step']]],
            $question['type'] === 'currency' => $component += ['currency' => 'IDR', 'separatorFormat' => 'id-ID', 'isDecimal' => false],
            $question['type'] === 'short' && ($question['mask'] ?? '') !== '' => $component = ['type' => 24, 'maskingFormat' => $question['mask']] + $component,
            $question['type'] === 'calc' => $this->calcComponent($component, $question, $inRoster),
            $question['type'] === 'file' && ($question['file']['accept'] ?? 'image') !== 'image' => $component['type'] = 42,
            default => null,
        };

        $this->logic($component, $question, $inRoster);
        $this->validation($question, $inRoster);

        return [$component];
    }

    /**
     * @param  array<string, mixed>  $component
     * @param  array<string, mixed>  $question
     */
    private function choiceComponent(array &$component, array $question): void
    {
        if (Lookup::isLookup($question) && ($question['lookup']['source'] ?? '') === 'api') {
            $api = ['baseUrl' => $question['lookup']['url'], 'headers' => ['Content-Type' => 'application/json'], 'data' => $question['lookup']['data_path'] ?? '', 'value' => $question['lookup']['value_key'] ?? 'id', 'label' => $question['lookup']['label_key'] ?? 'name'];
            if (($question['lookup']['depends_on'] ?? '') !== '') {
                $api['filterDependencies'] = [['params' => $question['lookup']['param'] ?? 'parent', 'sourceAnswer' => $question['lookup']['depends_on']]];
            }
            $component += ['typeOption' => 2, 'sourceAPI' => [$api]];

            return;
        }
        if (Lookup::isLookup($question)) {
            $this->notes[] = "Pilihan \"{$question['label']}\" dari data referensi ditulis sebagai lookup luring (typeOption 4); muat data referensinya di aplikasi FormGear.";
            $component += ['typeOption' => 4, 'sourceOption' => $question['lookup']['reference'] ?? ''];

            return;
        }
        if (($question['options_from'] ?? '') !== '') {
            $component += ['typeOption' => 3, 'sourceOption' => $question['options_from']];

            return;
        }

        $options = array_map(fn (string $option, int $index): array => ['label' => $option, 'value' => (string) ($index + 1)], $question['options'], array_keys($question['options']));
        if (Survey::hasOtherOption($question)) {
            $options[] = ['label' => Survey::OTHER_VALUE, 'value' => '99', 'open' => true];
        }
        $component += ['typeOption' => 1, 'options' => $options];
    }

    /**
     * @param  array<string, mixed>  $component
     * @param  array<string, mixed>  $question
     */
    private function calcComponent(array &$component, array $question, bool $inRoster): void
    {
        try {
            [$expression, $keys] = $this->formulaToJs(Formula::parse((string) ($question['formula'] ?? '')), $inRoster);
        } catch (InvalidArgumentException) {
            $expression = '0';
            $keys = [];
        }

        $component += ['expression' => $expression, 'componentVar' => array_values(array_unique($keys)), 'render' => true, 'renderType' => 1];
    }

    /**
     * Our formula tree as a FormGear (JavaScript) expression.
     *
     * @param  array<int, mixed>  $node
     * @return array{0: string, 1: list<string>}
     */
    private function formulaToJs(array $node, bool $inRoster): array
    {
        $keys = [];
        $render = function (array $node) use (&$render, &$keys, $inRoster): string {
            switch ($node[0]) {
                case 'num':
                    return (string) $node[1];
                case 'str':
                    return json_encode($node[1]);
                case 'tag':
                    $key = $this->key($node[1], $inRoster);
                    $keys[] = $key;
                    // A repeating block's answers outside it are a list: add them up.
                    if (isset($this->rosterOf[$node[1]]) && ! $inRoster) {
                        $this->notes[] = "Rumus yang menjumlah rincian \"{$this->questions[$node[1]]['label']}\" perlu dicek di FormGear.";

                        return "Number(getValue('{$key}'))";
                    }

                    return "Number(getValue('{$key}'))";
                case 'neg':
                    return '-('.$render($node[1]).')';
                case 'bin':
                    $operator = $node[1] === '==' ? '==' : $node[1];

                    return '('.$render($node[2]).' '.$operator.' '.$render($node[3]).')';
                default:
                    [$name, $arguments] = [$node[1], array_map($render, $node[2])];

                    return match ($name) {
                        'sum' => '('.implode(' + ', $arguments).')',
                        'avg' => '(('.implode(' + ', $arguments).') / '.count($arguments).')',
                        'min' => 'Math.min('.implode(', ', $arguments).')',
                        'max' => 'Math.max('.implode(', ', $arguments).')',
                        'round' => isset($arguments[1]) ? "(Math.round({$arguments[0]} * Math.pow(10, {$arguments[1]})) / Math.pow(10, {$arguments[1]}))" : "Math.round({$arguments[0]})",
                        'floor', 'ceil', 'abs' => "Math.{$name}({$arguments[0]})",
                        'if' => "({$arguments[0]} ? {$arguments[1]} : {$arguments[2]})",
                        'count', 'len' => '('.str_replace('Number(getValue', '(getValue', $arguments[0]).' || []).length',
                        'years' => 'Math.floor((new Date('.(isset($arguments[1]) ? str_replace('Number(', 'String(', $arguments[1]) : 'Date.now()').') - new Date('.str_replace('Number(', 'String(', $arguments[0]).')) / 31557600000)',
                        'days' => 'Math.round((new Date('.str_replace('Number(', 'String(', $arguments[1]).') - new Date('.str_replace('Number(', 'String(', $arguments[0]).')) / 86400000)',
                    };
            }
        };

        return [$render($node), $keys];
    }

    /**
     * Logic that shows or hides a question becomes enableCondition; FormGear has no "require" or "disable".
     *
     * @param  array<string, mixed>  $component
     * @param  array<string, mixed>  $question
     */
    private function logic(array &$component, array $question, bool $inRoster): void
    {
        $logic = $question['logic'] ?? null;

        if (! $logic) {
            if ($question['hidden']) {
                $component += ['enableCondition' => 'false', 'componentEnable' => []];
            }

            return;
        }

        [$expression, $keys] = $this->expression($logic['conditions'], $inRoster);
        $actions = $logic['actions'];

        if (in_array('show', $actions, true)) {
            $component += ['enableCondition' => $expression, 'componentEnable' => $keys];
        } elseif (in_array('hide', $actions, true)) {
            $component += ['enableCondition' => "!({$expression})", 'componentEnable' => $keys];
        }

        if (array_diff($actions, ['show', 'hide']) !== []) {
            $this->notes[] = "Logika \"{$question['label']}\" (".implode(', ', array_map(fn (string $action): string => SurveyLogic::ACTIONS[$action], array_diff($actions, ['show', 'hide']))).') tidak ada padanannya di FormGear.';
        }
    }

    /**
     * Answer rules and consistency checks become testFunctions; a test that holds shows its message.
     *
     * @param  array<string, mixed>  $question
     */
    private function validation(array $question, bool $inRoster): void
    {
        $key = $this->key($question['id'], $inRoster);
        $value = "getValue('{$key}')";
        $validations = [];
        $message = $question['validation']['message'] ?? '';

        foreach ($question['validation']['rules'] ?? [] as $rule) {
            $target = is_numeric($rule['value']) ? $rule['value'] : json_encode($rule['value']);
            $test = match ($rule['type']) {
                'min_length' => "{$value} != undefined && String({$value}).length < {$rule['value']}",
                'max_length' => "{$value} != undefined && String({$value}).length > {$rule['value']}",
                'min_value' => "{$value} !== '' && {$value} != undefined && Number({$value}) < {$target}",
                'max_value' => "{$value} !== '' && {$value} != undefined && Number({$value}) > {$target}",
                'contains' => "{$value} != undefined && !String({$value}).toLowerCase().includes({$target}.toLowerCase())",
                'not_contains' => "{$value} != undefined && String({$value}).toLowerCase().includes({$target}.toLowerCase())",
                'starts_with' => "{$value} != undefined && !String({$value}).toLowerCase().startsWith({$target}.toLowerCase())",
                'pattern' => "{$value} != undefined && !new RegExp(".json_encode($rule['value']).").test(String({$value}))",
                'after_date' => "{$value} != undefined && {$value} < {$target}",
                'before_date' => "{$value} != undefined && {$value} > {$target}",
                'min_selected' => "({$value} || []).length < {$rule['value']}",
                'max_selected' => "({$value} || []).length > {$rule['value']}",
                default => null,
            };
            if ($test !== null) {
                $validations[] = ['test' => $test, 'message' => $message ?: FieldValidation::defaultMessage($rule['type'], (string) $rule['value']), 'type' => 2];
            }
        }

        foreach ($question['validation']['checks'] ?? [] as $check) {
            [$expression] = $this->expression($check['conditions'], $inRoster);
            $validations[] = ['test' => $expression, 'message' => $check['message'], 'type' => $check['level'] === 'warning' ? 1 : 2];
        }

        if ($validations !== []) {
            $components = array_values(array_unique([$key, ...array_merge(...array_map(fn (array $check): array => $this->expression($check['conditions'], $inRoster)[1], $question['validation']['checks'] ?? []))]));
            $this->testFunctions[] = ['dataKey' => $question['id'], 'componentValidation' => $components, 'validations' => $validations];
        }
    }

    /**
     * A rule group as a FormGear condition, with the keys it reads.
     *
     * @param  array<string, mixed>  $group
     * @return array{0: string, 1: list<string>}
     */
    private function expression(array $group, ?bool $inRoster): array
    {
        $keys = [];
        $parts = array_map(function (array $rule) use (&$keys, $inRoster): string {
            if (isset($rule['rules'])) {
                [$inner, $innerKeys] = $this->expression($rule, $inRoster);
                $keys = [...$keys, ...$innerKeys];

                return '('.$inner.')';
            }

            $question = $this->questions[$rule['question_id']] ?? ['type' => 'short'];
            $key = $this->key($rule['question_id'], (bool) $inRoster);
            $keys[] = $key;
            $answer = "getValue('{$key}')";
            $value = (string) $rule['value'];
            $isChoice = in_array($question['type'], ['single', 'dropdown', 'rating', 'scale'], true);
            $code = $isChoice && in_array($value, $question['options'] ?? [], true) ? (string) (array_search($value, $question['options'], true) + 1) : $value;
            $scalar = $isChoice ? "({$answer} != undefined && {$answer}[0] != undefined ? {$answer}[0].value : '')" : $answer;
            $number = "Number({$scalar})";

            return match ($rule['operator']) {
                'answered' => "({$answer} != undefined && {$answer} !== '' && {$answer}.length !== 0)",
                'empty' => "({$answer} == undefined || {$answer} === '' || {$answer}.length === 0)",
                'equals' => $question['type'] === 'multiple' ? "({$answer} || []).some(o => o.label == ".json_encode($value).')' : ($question['type'] === 'checkbox' ? "{$answer} == true" : "{$scalar} == ".json_encode($code)),
                'not_equals' => $question['type'] === 'multiple' ? "!({$answer} || []).some(o => o.label == ".json_encode($value).')' : "{$scalar} != ".json_encode($code),
                'contains' => "String({$scalar}).toLowerCase().includes(".json_encode(mb_strtolower($value)).')',
                'not_contains' => "!String({$scalar}).toLowerCase().includes(".json_encode(mb_strtolower($value)).')',
                'greater_than' => "{$number} > ".$this->operand($value, $inRoster, $keys),
                'less_than' => "{$number} < ".$this->operand($value, $inRoster, $keys),
                'greater_or_equal' => "{$number} >= ".$this->operand($value, $inRoster, $keys),
                'less_or_equal' => "{$number} <= ".$this->operand($value, $inRoster, $keys),
                'between', 'not_between' => (function () use ($rule, $number, $value): string {
                    [$from, $to] = array_map('floatval', explode(';', $value) + [1 => 0]);
                    $inside = "({$number} >= {$from} && {$number} <= {$to})";

                    return $rule['operator'] === 'between' ? $inside : "!{$inside}";
                })(),
                default => 'false',
            };
        }, $group['rules']);

        return [implode($group['operator'] === 'or' ? ' || ' : ' && ', $parts), array_values(array_unique($keys))];
    }

    /**
     * A comparison value: a number, or another answer for an answer tag.
     *
     * @param  list<string>  $keys
     */
    private function operand(string $value, ?bool $inRoster, array &$keys): string
    {
        if (preg_match('/^\{\{([A-Za-z0-9_-]+)(?:\|([^}]*))?\}\}$/', trim($value), $match)) {
            $key = $this->key($match[1], (bool) $inRoster);
            $keys[] = $key;

            return "Number(getValue('{$key}') || ".(is_numeric($match[2] ?? '') ? $match[2] : '0').')';
        }

        return is_numeric($value) ? $value : '0';
    }

    /**
     * Inside a nested input, other questions of the same row are read with "@$ROW$".
     */
    private function key(string $id, bool $inRoster): string
    {
        return $inRoster && isset($this->rosterOf[$id]) ? $id.'@$ROW$' : $id;
    }

    /**
     * Pre-filled answers of the questionnaire, as FormGear predata.
     *
     * @return list<array{dataKey: string, answer: mixed}>
     */
    private function predata(): array
    {
        return array_values(array_map(
            fn (array $question): array => ['dataKey' => $question['id'], 'answer' => $question['prefill']],
            array_filter($this->questions, fn (array $question): bool => ($question['prefill'] ?? '') !== '' && ! isset($this->rosterOf[$question['id']])),
        ));
    }
}
