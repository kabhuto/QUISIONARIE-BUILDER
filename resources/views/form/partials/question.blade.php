@php
    $type = $question['type'];
    $number = $numbers[$question['id']] ?? null;
    $name = 'answers['.$question['id'].']';
    $inputId = 'answer-'.$question['id'];
    $prefill = ($question['prefill'] ?? '') !== '' ? $question['prefill'] : null;
    // Old input wins after a failed submission; otherwise start from the pre-filled value.
    $old = old('answers.'.$question['id'], $errors->any() ? null : $prefill);
    $errorKey = 'answers.'.$question['id'];
    $error = $errors->first($errorKey) ?: $errors->first($errorKey.'.*') ?: $errors->first($errorKey.'.*.*');
    $logic = $question['logic'] ?? null;
    $hasRules = ($question['validation']['rules'] ?? []) !== [] || ($question['validation']['checks'] ?? []) !== [];
    $placeholder = $question['placeholder'] ?? '';
    $maxLength = $question['max_length'] ?? null;
    $width = 'w-'.str_replace('/', '-', $question['width'] ?? 'full');
    $help = ($question['description'] ?? '') !== '' ? '<p class="fill-hint">'.\App\Support\Piping::html($question['description']).'</p>' : '';
    $label = \App\Support\Piping::html($question['label']);
    // Options may come from the answers chosen on an earlier question; JavaScript keeps them in step.
    $choiceOptions = \App\Models\Survey::optionsFor($question, (array) old('answers', []));
    $hasOther = \App\Models\Survey::hasOtherOption($question);
    $otherName = 'answers['.$question['id'].\App\Models\Survey::OTHER_SUFFIX.']';
    $otherOld = old('answers.'.$question['id'].\App\Models\Survey::OTHER_SUFFIX);
    $otherLabel = ($question['other_label'] ?? '') !== '' ? $question['other_label'] : \App\Models\Survey::DEFAULT_OTHER_LABEL;
    $helpAbove = ($question['help_position'] ?? 'below') === 'above';
    $isBranch = $question['hidden'] && in_array('show', $logic['actions'] ?? [], true);
    // Shown or hidden by JavaScript from the field logic; the asterisk follows [data-required].
    $required = '<span class="fill-required" aria-hidden="true">*</span>';
    $labelClass = ($question['hide_label'] ?? false) ? 'sr-only' : null;
@endphp

@if ($type === 'info')
    <div @class(['fill-question', 'cawi-info', $width]) data-question-id="{{ $question['id'] }}" data-type="info"
         @if ($question['hidden']) data-static-hidden hidden @endif
         @if ($logic) data-logic="{{ json_encode($logic) }}" @endif>
        <strong>ⓘ {{ $label }}</strong>
        @if ($question['description'] !== '')
            <p>{!! nl2br(\App\Support\Piping::html($question['description'])) !!}</p>
        @endif
    </div>
@else
    <div @class(['fill-question', 'cawi-question', 'is-'.$type, $width, 'is-narrow' => $width !== 'w-full', 'is-branch' => $isBranch, 'is-disabled' => $question['disabled'], 'has-error' => $error])
         data-question-id="{{ $question['id'] }}" data-type="{{ $type }}"
         @if ($question['required']) data-required data-static-required @endif
         @if ($question['hidden']) data-static-hidden hidden @endif
         @if ($question['disabled']) data-static-disabled @endif
         @if ($logic) data-logic="{{ json_encode($logic) }}" @endif
         @if ($hasRules) data-validation="{{ json_encode($question['validation']) }}" @endif
         @if ($type === 'calc') data-formula="{{ $question['formula'] }}" data-format="{{ $question['format'] }}" data-decimals="{{ $question['decimals'] }}" @endif
         @if (($question['options_from'] ?? '') !== '') data-options-from="{{ $question['options_from'] }}" @endif
         @if (($question['mask'] ?? '') !== '') data-mask="{{ $question['mask'] }}" @endif
         @if ($maxLength) data-max-length="{{ $maxLength }}" @endif>

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
        @elseif ($type === 'matrix')
            <div class="cawi-q cawi-q-wide">
                <span @class(['fill-label', $labelClass])><span class="cawi-number">{{ $number }}.</span> {{ $label }}{!! $required !!}</span>
                {!! $help !!}
            </div>
            <div class="cawi-table-wrap">
                <table class="cawi-table cawi-matrix">
                    <thead>
                        <tr>
                            <td></td>
                            @foreach ($question['matrix']['columns'] as $column)
                                <th scope="col">{{ $column }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($question['matrix']['rows'] as $rowIndex => $row)
                            <tr data-matrix-row>
                                <th class="cawi-row-head" scope="row">{{ $row }}</th>
                                @foreach ($question['matrix']['columns'] as $column)
                                    <td><input type="radio" name="{{ $name }}[{{ $rowIndex }}]" value="{{ $column }}" aria-label="{{ $row }}: {{ $column }}" @checked(($old[$rowIndex] ?? null) === $column)></td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @elseif ($type === 'table')
            @php
                $table = $question['table'];
                $isFixed = $table['mode'] === 'fixed';
                $oldRows = is_array($old) ? array_values($old) : [];
                $rowCount = $isFixed ? count($table['rows']) : min($table['max_rows'], max($table['initial_rows'] ?? 3, count($oldRows)));
            @endphp
            <div class="cawi-q cawi-q-wide">
                <span @class(['fill-label', $labelClass])><span class="cawi-number">{{ $number }}.</span> {{ $label }}{!! $required !!}</span>
                {!! $help !!}
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
                    <label @class(['fill-label', $labelClass]) @unless (in_array($type, ['single', 'multiple', 'code', 'rating', 'scale', 'signature', 'calc'], true)) for="{{ $inputId }}" @endunless id="{{ $inputId }}-label">
                        <span class="cawi-number">{{ $number }}.</span> {{ $label }}{!! $required !!}
                    </label>
                </div>
                <div class="cawi-a">
                    @if ($helpAbove)
                        {!! $help !!}
                    @endif
                    @switch($type)
                        @case('long')
                            <textarea class="fill-input" id="{{ $inputId }}" name="{{ $name }}" rows="3" maxlength="{{ $maxLength ?: 5000 }}" placeholder="{{ $placeholder }}">{{ $old }}</textarea>
                            @break
                        @case('number')
                            <input class="fill-input" id="{{ $inputId }}" name="{{ $name }}" type="number" step="any" inputmode="decimal" value="{{ $old }}" placeholder="{{ $placeholder }}">
                            @break
                        @case('currency')
                            <div class="cawi-money"><span>Rp</span><input class="fill-input" id="{{ $inputId }}" name="{{ $name }}" type="text" inputmode="numeric" autocomplete="off" value="{{ $old }}" placeholder="{{ $placeholder }}" data-currency></div>
                            @break
                        @case('date')
                            <input class="fill-input cawi-short" id="{{ $inputId }}" name="{{ $name }}" type="date" value="{{ $old }}">
                            @break
                        @case('time')
                            <input class="fill-input cawi-short" id="{{ $inputId }}" name="{{ $name }}" type="time" value="{{ $old }}">
                            @break
                        @case('calc')
                            <output class="cawi-calc" data-calc-output aria-live="polite">—</output>
                            <p class="fill-hint">Dihitung otomatis dari jawaban lain.</p>
                            @break
                        @case('location')
                            <div class="cawi-location" data-location>
                                <input class="fill-input" id="{{ $inputId }}" name="{{ $name }}" type="text" inputmode="decimal" maxlength="80" value="{{ $old }}" placeholder="lintang, bujur — mis. -7.0321, 112.7464">
                                <button class="cawi-button is-ghost" type="button" data-locate>📍 Ambil lokasi saat ini</button>
                                <p class="fill-hint" data-location-status></p>
                            </div>
                            @break
                        @case('file')
                            @php
                                $extensions = \App\Models\Survey::fileExtensions($question);
                                $maxMb = $question['file']['max_mb'] ?? 5;
                            @endphp
                            <div class="cawi-file" data-file>
                                <input class="fill-input" id="{{ $inputId }}" name="{{ $name }}" type="file" accept="{{ collect($extensions)->map(fn ($extension) => '.'.$extension)->implode(',') }}" data-max-mb="{{ $maxMb }}" @if (($question['file']['accept'] ?? 'image') === 'image') capture="environment" @endif>
                                <img class="cawi-file-preview" alt="" hidden data-file-preview>
                                <p class="fill-hint">{{ strtoupper(implode(', ', $extensions)) }} · maksimal {{ $maxMb }} MB @if ($errors->any()) · pilih berkas lagi bila sebelumnya sudah dipilih @endif</p>
                            </div>
                            @break
                        @case('email')
                            <input class="fill-input" id="{{ $inputId }}" name="{{ $name }}" type="email" maxlength="160" autocomplete="email" value="{{ $old }}" placeholder="{{ $placeholder }}">
                            @break
                        @case('phone')
                            <input class="fill-input cawi-short" id="{{ $inputId }}" name="{{ $name }}" type="tel" maxlength="20" autocomplete="tel" value="{{ $old }}" placeholder="{{ $placeholder }}">
                            @break
                        @case('url')
                            <input class="fill-input" id="{{ $inputId }}" name="{{ $name }}" type="url" maxlength="500" inputmode="url" value="{{ $old }}" placeholder="{{ $placeholder ?: 'https://' }}">
                            @break
                        @case('checkbox')
                            <label class="cawi-option cawi-tick"><input type="checkbox" id="{{ $inputId }}" name="{{ $name }}" value="{{ \App\Models\Survey::CHECKBOX_VALUE }}" @checked($old === \App\Models\Survey::CHECKBOX_VALUE)><span>{{ \App\Models\Survey::CHECKBOX_VALUE }}</span></label>
                            @break
                        @case('rating')
                        @case('scale')
                            <div @class(['cawi-range', 'is-rating' => $type === 'rating']) role="radiogroup" aria-labelledby="{{ $inputId }}-label">
                                @foreach (\App\Models\Survey::rangeValues($question) as $value)
                                    <label title="{{ $value }}"><input class="sr-only" type="radio" name="{{ $name }}" value="{{ $value }}" @checked((string) $old === (string) $value)><span>{{ $type === 'rating' ? '★' : $value }}</span></label>
                                @endforeach
                            </div>
                            @break
                        @case('slider')
                            @php
                                $range = $question['range'];
                                $hasValue = is_numeric($old);
                            @endphp
                            <div class="cawi-slider" data-slider>
                                <span class="cawi-slider-bound">{{ $range['min'] }}</span>
                                <input type="range" id="{{ $inputId }}" min="{{ $range['min'] }}" max="{{ $range['max'] }}" step="{{ $range['step'] }}" value="{{ $hasValue ? $old : $range['min'] }}" data-slider-range>
                                <span class="cawi-slider-bound">{{ $range['max'] }}</span>
                                <output class="cawi-slider-value" for="{{ $inputId }}" data-slider-output>{{ $hasValue ? $old : '—' }}</output>
                                <button class="link-button" type="button" data-slider-clear @unless ($hasValue) hidden @endunless>Hapus</button>
                                <input type="hidden" name="{{ $name }}" value="{{ $hasValue ? $old : '' }}">
                            </div>
                            @break
                        @case('signature')
                            <div class="sign-pad" data-signature>
                                <canvas width="600" height="200" role="img" aria-labelledby="{{ $inputId }}-label"></canvas>
                                <div class="sign-pad-foot">
                                    <span>Tanda tangani di dalam kotak dengan mouse, jari, atau stylus.</span>
                                    <button class="link-button" type="button" data-signature-clear>Hapus</button>
                                </div>
                                <input type="hidden" name="{{ $name }}" value="{{ is_string($old) ? $old : '' }}">
                            </div>
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
                            <div @class(['cawi-options', 'is-columns' => count($choiceOptions) > 4 && $width === 'w-full']) role="{{ $type === 'single' ? 'radiogroup' : 'group' }}" aria-labelledby="{{ $inputId }}-label" data-options>
                                @foreach ($choiceOptions as $optionIndex => $option)
                                    <label @class(['cawi-option', 'is-multiple' => $type === 'multiple'])>
                                        @if ($type === 'single')
                                            <input type="radio" name="{{ $name }}" value="{{ $option }}" @checked($old === $option)>
                                        @else
                                            <input type="checkbox" name="{{ $name }}[]" value="{{ $option }}" @checked(in_array($option, (array) $old, true))>
                                        @endif
                                        <span>{{ $option }}</span>
                                    </label>
                                @endforeach
                                @if ($hasOther)
                                    <label @class(['cawi-option', 'is-other', 'is-multiple' => $type === 'multiple'])>
                                        <input type="{{ $type === 'single' ? 'radio' : 'checkbox' }}" name="{{ $name }}{{ $type === 'multiple' ? '[]' : '' }}" value="{{ \App\Models\Survey::OTHER_VALUE }}" @checked(in_array(\App\Models\Survey::OTHER_VALUE, (array) $old, true)) data-other-choice>
                                        <span>{{ $otherLabel }}</span>
                                    </label>
                                @endif
                            </div>
                            @if (($question['options_from'] ?? '') !== '')
                                <p class="fill-hint" data-options-empty @if ($choiceOptions !== []) hidden @endif>Pilihan muncul setelah pertanyaan {{ $numbers[$question['options_from']] ?? '' }} dijawab.</p>
                            @endif
                            @if ($hasOther)
                                <input class="fill-input cawi-other" type="text" name="{{ $otherName }}" maxlength="255" value="{{ $otherOld }}" placeholder="Sebutkan…" aria-label="Sebutkan jawaban lainnya" data-other-text @unless (in_array(\App\Models\Survey::OTHER_VALUE, (array) $old, true)) hidden @endunless>
                            @endif
                            @break
                        @case('dropdown')
                            <select class="fill-input" id="{{ $inputId }}" name="{{ $name }}" data-options>
                                <option value="">{{ $placeholder ?: 'Pilih jawaban…' }}</option>
                                @foreach ($choiceOptions as $option)
                                    <option value="{{ $option }}" @selected($old === $option)>{{ $option }}</option>
                                @endforeach
                                @if ($hasOther)
                                    <option value="{{ \App\Models\Survey::OTHER_VALUE }}" @selected($old === \App\Models\Survey::OTHER_VALUE) data-other-choice>{{ $otherLabel }}</option>
                                @endif
                            </select>
                            @if (($question['options_from'] ?? '') !== '')
                                <p class="fill-hint" data-options-empty @if ($choiceOptions !== []) hidden @endif>Pilihan muncul setelah pertanyaan {{ $numbers[$question['options_from']] ?? '' }} dijawab.</p>
                            @endif
                            @if ($hasOther)
                                <input class="fill-input cawi-other" type="text" name="{{ $otherName }}" maxlength="255" value="{{ $otherOld }}" placeholder="Sebutkan…" aria-label="Sebutkan jawaban lainnya" data-other-text @unless ($old === \App\Models\Survey::OTHER_VALUE) hidden @endunless>
                            @endif
                            @break
                        @default
                            @php($mask = $question['mask'] ?? '')
                            <input class="fill-input @if ($mask !== '') cawi-masked @endif" id="{{ $inputId }}" name="{{ $name }}" type="text" maxlength="{{ $mask !== '' ? mb_strlen($mask) : ($maxLength ?: 255) }}" value="{{ $old }}" placeholder="{{ $placeholder !== '' ? $placeholder : ($mask !== '' ? strtr($mask, ['9' => '0', 'a' => 'A', '*' => 'X']) : '') }}" @if ($mask !== '') inputmode="{{ preg_match('/[a*]/', $mask) ? 'text' : 'numeric' }}" autocomplete="off" @endif>
                    @endswitch
                    @unless ($helpAbove)
                        {!! $help !!}
                    @endunless
                    @if ($maxLength)
                        <p class="fill-counter" data-counter></p>
                    @endif
                </div>
            </div>
        @endif

        <p class="fill-error" data-error>{{ $error }}</p>

        {{-- Consistency checks, shown by JavaScript as soon as their conditions hold. --}}
        @foreach ($question['validation']['checks'] ?? [] as $check)
            <div class="cawi-check is-{{ $check['level'] }}" data-check-id="{{ $check['id'] }}" role="alert" hidden>
                <p><b>{{ $check['level'] === 'warning' ? 'Peringatan' : 'Galat' }}:</b> {{ $check['message'] }}</p>
                @if ($check['level'] === 'warning')
                    <label><input type="checkbox" name="confirmations[{{ $check['id'] }}]" value="1" @checked(old('confirmations.'.$check['id']))> Ya, jawaban ini sudah benar</label>
                @endif
            </div>
        @endforeach
    </div>
@endif
