@php
    $type = $question['type'];
    $number = $numbers[$question['id']] ?? null;
    $answer = $answers[$question['id']] ?? null;
    $hasAnswer = $answer !== null && $answer !== '' && $answer !== [];
    $choiceIndex = in_array($type, ['single', 'dropdown'], true) && $hasAnswer ? array_search($answer, $question['options'], true) : false;
    $codeValue = match (true) {
        $type === 'code' && $hasAnswer => (string) $answer,
        in_array($type, ['number', 'rating', 'scale', 'slider'], true) && $hasAnswer && ctype_digit((string) $answer) && strlen((string) $answer) <= $question['code_length'] => str_pad((string) $answer, $question['code_length'], ' ', STR_PAD_LEFT),
        $choiceIndex !== false => str_pad((string) ($choiceIndex + 1), $question['code_length'], ' ', STR_PAD_LEFT),
        in_array($type, ['currency', 'phone'], true) && $hasAnswer && strlen($digits = preg_replace('/\D/', '', (string) $answer)) <= $question['code_length'] => str_pad($digits, $question['code_length'], ' ', STR_PAD_LEFT),
        default => '',
    };
    $wideCode = ($question['code_length'] ?? 0) > ($codeCap ?? PHP_INT_MAX);
@endphp

@if ($type === 'info')
    <tr>
        <td colspan="4" class="sheet-info"><b>{{ $question['label'] }}</b>@if ($question['description'] !== '') — {{ $question['description'] }}@endif</td>
    </tr>
@elseif ($type === 'region')
    @foreach (\App\Models\Survey::regionFields($question) as $field)
        @php
            $value = $field['mode'] === 'fixed' ? \App\Models\Survey::fixedRegionValue($field) : ($answer[$field['key']] ?? '');
        @endphp
        <tr>
            <td class="sheet-num">{{ $number }}{{ count(\App\Models\Survey::regionFields($question)) > 1 ? '.'.chr(97 + $loop->index) : '' }}.</td>
            <td class="sheet-label">{{ $field['label'] }}</td>
            <td class="sheet-answer" colspan="2">
                @if ($value !== '')
                    <span class="sheet-value">{{ $value }}</span>
                @elseif ($field['key'] === 'klasifikasi')
                    <span class="sheet-options is-inline"><span>1. Perkotaan</span><span>2. Perdesaan</span></span>
                @else
                    <span class="sheet-line"></span>
                @endif
            </td>
        </tr>
    @endforeach
@elseif ($type === 'matrix')
    <tr>
        <td class="sheet-num">{{ $number }}.</td>
        <td class="sheet-label" colspan="3">
            {{ $question['label'] }}
            @if ($question['description'] !== '')
                <small>{{ $question['description'] }}</small>
            @endif
            <table class="sheet-subtable">
                <thead>
                    <tr>
                        <th></th>
                        @foreach ($question['matrix']['columns'] as $column)
                            <th>{{ $column }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($question['matrix']['rows'] as $rowIndex => $row)
                        <tr>
                            <th>{{ $row }}</th>
                            @foreach ($question['matrix']['columns'] as $column)
                                <td><span @class(['sheet-mark', 'is-chosen' => ($answer[$rowIndex] ?? null) === $column])></span></td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </td>
    </tr>
@elseif ($type === 'signature')
    <tr>
        <td class="sheet-num">{{ $number }}.</td>
        <td class="sheet-label">
            {{ $question['label'] }}
            @if ($question['description'] !== '')
                <small>{{ $question['description'] }}</small>
            @endif
        </td>
        <td class="sheet-answer" colspan="2">
            <span class="sheet-signature">
                @if (\App\Models\Survey::isSignatureImage($answer))
                    <img src="{{ $answer }}" alt="Tanda tangan">
                @endif
            </span>
        </td>
    </tr>
@elseif ($type === 'table')
    @php
        $table = $question['table'];
        $isFixed = $table['mode'] === 'fixed';
        $rows = $isFixed ? $table['rows'] : ($hasAnswer ? $answer : array_fill(0, min($table['initial_rows'] ?? 3, $table['max_rows']), []));
    @endphp
    <tr>
        <td class="sheet-num">{{ $number }}.</td>
        <td class="sheet-label" colspan="3">
            {{ $question['label'] }}
            @if ($question['description'] !== '')
                <small>{{ $question['description'] }}</small>
            @endif
            <table class="sheet-subtable">
                <thead>
                    <tr>
                        <th>{{ $isFixed ? '' : 'No' }}</th>
                        @foreach ($table['columns'] as $column)
                            <th>{{ $column['label'] }}@if ($column['type'] === 'choice')<small>@foreach ($column['options'] as $option){{ $loop->iteration }}. {{ $option }}@unless ($loop->last)<br>@endunless @endforeach</small>@endif</th>
                        @endforeach
                    </tr>
                    <tr class="sheet-col-numbers">
                        <td>(1)</td>
                        @foreach ($table['columns'] as $column)
                            <td>({{ $loop->iteration + 1 }})</td>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $rowIndex => $row)
                        @php
                            $values = $isFixed ? ($answer[$rowIndex] ?? []) : $row;
                        @endphp
                        <tr>
                            <th>{{ $isFixed ? $row : $rowIndex + 1 }}</th>
                            @foreach ($table['columns'] as $column)
                                <td>{{ $values[$column['id']] ?? '' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </td>
    </tr>
@else
    <tr>
        <td class="sheet-num">{{ $number }}.</td>
        <td class="sheet-label">
            {{ $question['label'] }}
            @if ($question['description'] !== '')
                <small>{{ $question['description'] }}</small>
            @endif
        </td>
        <td @class(['sheet-answer', 'has-wide-code' => $wideCode]) @if ($wideCode) colspan="2" @endif>
            @if ($wideCode)<div class="sheet-answer-row"><div class="sheet-answer-main">@endif
            @if (in_array($type, \App\Models\Survey::CHOICE_TYPES, true))
                <span @class(['sheet-options', 'is-columns' => count($question['options']) > 3])>
                    @foreach ($question['options'] as $optionIndex => $option)
                        <span @class(['is-chosen' => in_array($option, (array) $answer, true)])>{{ $optionIndex + 1 }}. {{ $option }}</span>
                    @endforeach
                </span>
            @elseif (in_array($type, ['rating', 'scale'], true))
                <span class="sheet-options is-inline is-scale">
                    @foreach (\App\Models\Survey::rangeValues($question) as $value)
                        <span @class(['is-chosen' => $hasAnswer && (string) $answer === (string) $value])>{{ $value }}</span>
                    @endforeach
                </span>
            @elseif ($type === 'checkbox')
                <span class="sheet-check"><span @class(['sheet-mark', 'is-box', 'is-chosen' => $hasAnswer])></span> {{ \App\Models\Survey::CHECKBOX_VALUE }}</span>
            @elseif ($type === 'slider' && ! $hasAnswer)
                <span class="sheet-line"></span><small>Isi angka {{ $question['range']['min'] }}–{{ $question['range']['max'] }}</small>
            @elseif ($type === 'date')
                @if ($hasAnswer)
                    <span class="sheet-value">{{ \Illuminate\Support\Carbon::parse($answer)->translatedFormat('d F Y') }}</span>
                @else
                    <span class="sheet-date"><span class="date-part"><b>Tgl</b><i></i><i></i></span><span class="date-part"><b>Bln</b><i></i><i></i></span><span class="date-part"><b>Thn</b><i></i><i></i><i></i><i></i></span></span>
                @endif
            @elseif ($type === 'code')
                @if ($question['code_length'] === 0)
                    @if ($hasAnswer)<span class="sheet-value">{{ $answer }}</span>@else<span class="sheet-line"></span>@endif
                @endif
            @elseif ($type === 'long' && ! $hasAnswer)
                <span class="sheet-line"></span><span class="sheet-line"></span><span class="sheet-line"></span>
            @elseif ($hasAnswer)
                <span class="sheet-value">{{ $type === 'currency' ? \App\Models\SurveyResponse::formatRupiah($answer) : $answer }}</span>
            @else
                <span class="sheet-line">@if ($type === 'currency')Rp @endif</span>
            @endif
            @if ($wideCode)
                </div>
                <span class="sheet-boxes">@for ($digit = 0; $digit < $question['code_length']; $digit++)<i>{{ trim(substr($codeValue, $digit, 1)) }}</i>@endfor</span>
                </div>
            @endif
        </td>
        @unless ($wideCode)
            <td class="sheet-code">
                @if ($question['code_length'] > 0)
                    <span class="sheet-boxes">
                        @for ($digit = 0; $digit < $question['code_length']; $digit++)
                            <i>{{ trim(substr($codeValue, $digit, 1)) }}</i>
                        @endfor
                    </span>
                @endif
            </td>
        @endunless
    </tr>
@endif
