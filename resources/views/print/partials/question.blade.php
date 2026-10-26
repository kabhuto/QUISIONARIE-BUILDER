@php
    $type = $question['type'];
    $number = $numbers[$question['id']] ?? null;
    $answer = $answers[$question['id']] ?? null;
    $hasAnswer = $answer !== null && $answer !== '' && $answer !== [];
    // Answer tags in the text: the answer on a filled sheet, the quoted question's number on a blank one.
    $pipe = fn (string $text): string => $response
        ? \App\Support\Piping::resolved($text, fn (string $id): string => isset($questionIndex[$id]) ? $response->displayAnswer($questionIndex[$id]) : '')
        : \App\Support\Piping::text($text, $numbers);
    $printLabel = $pipe($question['label']);
    $printDescription = $pipe($question['description'] ?? '');
    $printOptions = in_array($type, \App\Models\Survey::CHOICE_TYPES, true) ? \App\Models\Survey::optionsFor($question, $answers ?? []) : [];
    if (\App\Models\Survey::hasOtherOption($question)) {
        $printOptions[] = \App\Models\Survey::OTHER_VALUE;
    }
    $otherText = $answers[$question['id'].\App\Models\Survey::OTHER_SUFFIX] ?? '';
    $choiceIndex = in_array($type, ['single', 'dropdown'], true) && $hasAnswer ? array_search($answer, $printOptions, true) : false;
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
        <td colspan="4" class="sheet-info"><b>{{ $printLabel }}</b>@if ($question['description'] !== '') — {{ $printDescription }}@endif</td>
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
            {{ $printLabel }}
            @if ($question['description'] !== '')
                <small>{{ $printDescription }}</small>
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
            {{ $printLabel }}
            @if ($question['description'] !== '')
                <small>{{ $printDescription }}</small>
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
            {{ $printLabel }}
            @if ($question['description'] !== '')
                <small>{{ $printDescription }}</small>
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
            {{ $printLabel }}
            @if ($question['description'] !== '')
                <small>{{ $printDescription }}</small>
            @endif
        </td>
        <td @class(['sheet-answer', 'has-wide-code' => $wideCode]) @if ($wideCode) colspan="2" @endif>
            @if ($wideCode)<div class="sheet-answer-row"><div class="sheet-answer-main">@endif
            @if (in_array($type, \App\Models\Survey::CHOICE_TYPES, true))
                @if (($question['options_from'] ?? '') !== '' && ! $response)
                    <small class="sheet-note">Pilihan dari jawaban pertanyaan {{ $numbers[$question['options_from']] ?? '' }}</small>
                    <span class="sheet-line"></span>
                @else
                    <span @class(['sheet-options', 'is-columns' => count($printOptions) > 3])>
                        @foreach ($printOptions as $optionIndex => $option)
                            @if ($option === \App\Models\Survey::OTHER_VALUE && \App\Models\Survey::hasOtherOption($question))
                                <span @class(['is-chosen' => in_array($option, (array) $answer, true)])>{{ $optionIndex + 1 }}. {{ ($question['other_label'] ?? '') !== '' ? $question['other_label'] : \App\Models\Survey::DEFAULT_OTHER_LABEL }}: @if ($otherText !== '')<b>{{ $otherText }}</b>@else<span class="sheet-dots"></span>@endif</span>
                            @else
                                <span @class(['is-chosen' => in_array($option, (array) $answer, true)])>{{ $optionIndex + 1 }}. {{ $option }}</span>
                            @endif
                        @endforeach
                    </span>
                @endif
            @elseif ($type === 'calc')
                @if ($hasAnswer)
                    <span class="sheet-value">{{ \App\Support\Formula::display((string) $answer, $question['format'] ?? 'number', (int) ($question['decimals'] ?? 0)) }}</span>
                @else
                    <span class="sheet-line"></span>
                @endif
                <small class="sheet-note">= {{ \App\Support\Piping::text($question['formula'] ?? '', $numbers) }}</small>
            @elseif ($type === 'time' && ! $hasAnswer)
                <span class="sheet-date"><span class="date-part"><b>Jam</b><i></i><i></i></span><span class="date-part"><b>Menit</b><i></i><i></i></span></span>
            @elseif ($type === 'location' && ! $hasAnswer)
                <span class="sheet-date"><span class="date-part"><b>Lintang</b><span class="sheet-dots is-wide"></span></span><span class="date-part"><b>Bujur</b><span class="sheet-dots is-wide"></span></span></span>
            @elseif ($type === 'file')
                @if ($hasAnswer && in_array(strtolower(pathinfo((string) $answer, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true))
                    <img class="sheet-photo" src="{{ asset('storage/'.$answer) }}" alt="Foto {{ $printLabel }}">
                @elseif ($hasAnswer)
                    <span class="sheet-value">[berkas terlampir: {{ basename((string) $answer) }}]</span>
                @else
                    <span class="sheet-note">Lampirkan {{ mb_strtolower(\App\Models\Survey::FILE_KINDS[$question['file']['accept'] ?? 'image']['label']) }}</span>
                @endif
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
