@php
    $type = $question['type'];
    $number = $numbers[$question['id']] ?? null;
    $name = 'answers['.$question['id'].']';
    $inputId = 'answer-'.$question['id'];
    $old = old('answers.'.$question['id']);
    $errorKey = 'answers.'.$question['id'];
    $error = $errors->first($errorKey) ?: $errors->first($errorKey.'.*') ?: $errors->first($errorKey.'.*.*');
    $conditionAttribute = ! empty($question['condition']) ? json_encode($question['condition']) : null;
    $required = $question['required'] ? '<span class="fill-required" aria-label="wajib diisi">*</span>' : '';
@endphp

@if ($type === 'info')
    <div class="fill-question cawi-info" data-question-id="{{ $question['id'] }}" data-type="info" @if ($conditionAttribute) data-condition="{{ $conditionAttribute }}" @endif>
        <strong>ⓘ {{ $question['label'] }}</strong>
        @if ($question['description'] !== '')
            <p>{!! nl2br(e($question['description'])) !!}</p>
        @endif
    </div>
@else
    <div @class(['fill-question', 'cawi-question', 'is-'.$type, 'is-branch' => ! empty($question['condition']), 'has-error' => $error])
         data-question-id="{{ $question['id'] }}" data-type="{{ $type }}"
         @if ($question['required']) data-required @endif
         @if ($conditionAttribute) data-condition="{{ $conditionAttribute }}" @endif>

        @if ($type === 'region')
            @php
                $fields = \App\Models\Survey::regionFields($question);
                $fixedFields = array_filter($fields, fn (array $field): bool => $field['mode'] === 'fixed');
                $inputFields = array_filter($fields, fn (array $field): bool => $field['mode'] === 'input');
            @endphp
            @if ($fixedFields !== [])
                <dl class="cawi-region-card">
                    @foreach ($fixedFields as $field)
                        <div><dt>{{ $field['label'] }}</dt><dd>{{ \App\Models\Survey::fixedRegionValue($field) ?: '-' }}</dd></div>
                    @endforeach
                </dl>
            @endif
            @foreach ($inputFields as $field)
                <div class="cawi-row">
                    <div class="cawi-q">
                        <label @unless ($field['key'] === 'klasifikasi') for="{{ $inputId }}-{{ $field['key'] }}" @endunless>{{ $field['label'] }}{!! $required !!}</label>
                    </div>
                    <div class="cawi-a">
                        @if ($field['key'] === 'klasifikasi')
                            <div class="cawi-options" role="radiogroup" aria-label="{{ $field['label'] }}">
                                @foreach (\App\Models\Survey::REGION_CLASSIFICATIONS as $classification)
                                    <label class="cawi-option"><input type="radio" name="{{ $name }}[{{ $field['key'] }}]" value="{{ $classification }}" @checked(($old[$field['key']] ?? null) === $classification)><span>{{ str($classification)->title() }}</span></label>
                                @endforeach
                            </div>
                        @else
                            <input class="fill-input" id="{{ $inputId }}-{{ $field['key'] }}" name="{{ $name }}[{{ $field['key'] }}]" type="text" maxlength="255" value="{{ $old[$field['key']] ?? '' }}" data-region-input>
                        @endif
                    </div>
                </div>
            @endforeach
        @elseif ($type === 'table')
            @php
                $table = $question['table'];
                $isFixed = $table['mode'] === 'fixed';
                $oldRows = is_array($old) ? array_values($old) : [];
                $rowCount = $isFixed ? count($table['rows']) : min($table['max_rows'], max($table['initial_rows'] ?? 3, count($oldRows)));
            @endphp
            <div class="cawi-q cawi-q-wide">
                <span class="fill-label"><span class="cawi-number">{{ $number }}.</span> {{ $question['label'] }}{!! $required !!}</span>
                @if ($question['description'] !== '')
                    <p class="fill-hint">{{ $question['description'] }}</p>
                @endif
            </div>
            <div class="cawi-table-wrap">
                <table class="cawi-table" data-table="{{ $question['id'] }}" data-mode="{{ $table['mode'] }}" data-max-rows="{{ $table['max_rows'] }}">
                    <thead>
                        <tr>
                            <th scope="col">{{ $isFixed ? 'Uraian' : 'No' }}</th>
                            @foreach ($table['columns'] as $column)
                                <th scope="col">{{ $column['label'] }}</th>
                            @endforeach
                            @unless ($isFixed)
                                <th scope="col"><span class="sr-only">Hapus baris</span></th>
                            @endunless
                        </tr>
                        <tr class="cawi-col-numbers">
                            <td></td>
                            @foreach ($table['columns'] as $column)
                                <td>({{ $loop->iteration }})</td>
                            @endforeach
                            @unless ($isFixed)
                                <td></td>
                            @endunless
                        </tr>
                    </thead>
                    <tbody data-table-body>
                        @for ($row = 0; $row < $rowCount; $row++)
                            @include('form.partials.table-row', ['row' => $row, 'values' => $oldRows[$row] ?? []])
                        @endfor
                    </tbody>
                </table>
                @unless ($isFixed)
                    <template data-row-template>
                        @include('form.partials.table-row', ['row' => '__ROW__', 'values' => []])
                    </template>
                @endunless
            </div>
            @unless ($isFixed)
                <button class="cawi-add-row" type="button" data-add-row>+ Tambah baris</button>
                <p class="fill-hint">Maksimal {{ $table['max_rows'] }} baris.</p>
            @endunless
        @else
            <div class="cawi-row">
                <div class="cawi-q">
                    <label class="fill-label" @unless (in_array($type, ['single', 'multiple', 'code'], true)) for="{{ $inputId }}" @endunless id="{{ $inputId }}-label">
                        <span class="cawi-number">{{ $number }}.</span> {{ $question['label'] }}{!! $required !!}
                    </label>
                    @if ($question['description'] !== '')
                        <p class="fill-hint">{{ $question['description'] }}</p>
                    @endif
                </div>
                <div class="cawi-a">
                    @switch($type)
                        @case('long')
                            <textarea class="fill-input" id="{{ $inputId }}" name="{{ $name }}" rows="3" maxlength="5000">{{ $old }}</textarea>
                            @break
                        @case('number')
                            <input class="fill-input" id="{{ $inputId }}" name="{{ $name }}" type="number" step="any" inputmode="decimal" value="{{ $old }}">
                            @break
                        @case('currency')
                            <div class="cawi-money"><span>Rp</span><input class="fill-input" id="{{ $inputId }}" name="{{ $name }}" type="text" inputmode="numeric" autocomplete="off" value="{{ $old }}" data-currency></div>
                            @break
                        @case('date')
                            <input class="fill-input cawi-short" id="{{ $inputId }}" name="{{ $name }}" type="date" value="{{ $old }}">
                            @break
                        @case('email')
                            <input class="fill-input" id="{{ $inputId }}" name="{{ $name }}" type="email" maxlength="160" autocomplete="email" value="{{ $old }}">
                            @break
                        @case('phone')
                            <input class="fill-input cawi-short" id="{{ $inputId }}" name="{{ $name }}" type="tel" maxlength="20" autocomplete="tel" value="{{ $old }}">
                            @break
                        @case('code')
                            @if ($question['code_length'] > 0)
                                <div class="code-input" role="group" aria-labelledby="{{ $inputId }}-label">
                                    @for ($digit = 0; $digit < $question['code_length']; $digit++)
                                        <input class="code-digit" type="text" inputmode="numeric" maxlength="1" value="{{ substr((string) $old, $digit, 1) }}" aria-label="Digit {{ $digit + 1 }}" autocomplete="off">
                                    @endfor
                                    <input type="hidden" name="{{ $name }}" value="{{ $old }}">
                                </div>
                            @else
                                <input class="fill-input cawi-short" id="{{ $inputId }}" name="{{ $name }}" type="text" inputmode="numeric" value="{{ $old }}">
                            @endif
                            @break
                        @case('single')
                        @case('multiple')
                            <div @class(['cawi-options', 'is-columns' => count($question['options']) > 4]) role="{{ $type === 'single' ? 'radiogroup' : 'group' }}" aria-labelledby="{{ $inputId }}-label">
                                @foreach ($question['options'] as $optionIndex => $option)
                                    <label @class(['cawi-option', 'is-multiple' => $type === 'multiple'])>
                                        @if ($type === 'single')
                                            <input type="radio" name="{{ $name }}" value="{{ $option }}" @checked($old === $option)>
                                        @else
                                            <input type="checkbox" name="{{ $name }}[]" value="{{ $option }}" @checked(in_array($option, (array) $old, true))>
                                        @endif
                                        <span>{{ $option }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @break
                        @case('dropdown')
                            <select class="fill-input" id="{{ $inputId }}" name="{{ $name }}">
                                <option value="">Pilih jawaban…</option>
                                @foreach ($question['options'] as $option)
                                    <option value="{{ $option }}" @selected($old === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                            @break
                        @default
                            <input class="fill-input" id="{{ $inputId }}" name="{{ $name }}" type="text" maxlength="255" value="{{ $old }}">
                    @endswitch
                </div>
            </div>
        @endif

        <p class="fill-error" data-error>{{ $error }}</p>
    </div>
@endif
