@php
    [$paperWidth, $paperHeight] = \App\Models\Survey::PAPER_SIZES[$settings['paper_size']] ?? [210, 297];
    if ($settings['orientation'] === 'landscape') {
        [$paperWidth, $paperHeight] = [$paperHeight, $paperWidth];
    }
    $periodLabel = $survey->periodLabel();
    $questionIndex = collect($survey->questions())->keyBy('id')->all();
    // Blocks may set their own orientation, so one form can mix portrait and landscape pages.
    [$longSide, $shortSide] = [max($paperWidth, $paperHeight), min($paperWidth, $paperHeight)];
    $orientations = array_map(fn (array $block): string => $survey->blockOrientation($block), $survey->blocks ?? []);
    $firstOrientation = $orientations[0] ?? $settings['orientation'];
    $isMixed = count(array_unique($orientations)) > 1;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cetak · {{ $survey->title }}</title>
    <link rel="stylesheet" href="{{ asset('css/print.css') }}">
    <style>
        @page { size: {{ $paperWidth }}mm {{ $paperHeight }}mm; margin: 10mm; }
        @page potret { size: {{ $shortSide }}mm {{ $longSide }}mm; margin: 10mm; }
        @page lanskap { size: {{ $longSide }}mm {{ $shortSide }}mm; margin: 10mm; }
        .sheet.is-portrait { page: potret; width: {{ $shortSide }}mm; min-height: {{ $longSide }}mm; }
        .sheet.is-landscape { page: lanskap; width: {{ $longSide }}mm; min-height: {{ $shortSide }}mm; }
    </style>
</head>
<body class="print-page">
    <div class="print-toolbar">
        <a href="{{ $response ? route('surveys.responses.show', [$survey, $response]) : route('surveys.edit', $survey) }}">← Kembali</a>
        <span>{{ $response ? 'Formulir terisi' : 'Formulir kosong' }} · kertas {{ $settings['paper_size'] }} {{ $isMixed ? 'potret + lanskap' : ($firstOrientation === 'landscape' ? 'lanskap' : 'potret') }}</span>
        <button type="button" onclick="window.print()">Cetak / simpan PDF</button>
    </div>

    <main class="sheet is-{{ $firstOrientation }}">
        @php
            $topline = array_fill_keys(array_keys(\App\Models\Survey::HEADER_SLOTS), []);
            if ($settings['topline_label'] !== '') {
                $topline[$settings['topline_label_position']][] = '<span class="sheet-topline-text">'.e($settings['topline_label']).'</span>';
            }
            if ($survey->form_code) {
                $topline[$settings['form_code_position']][] = '<span class="sheet-form-code">'.e($survey->form_code).'</span>';
            }
        @endphp
        @if (array_filter($topline))
            <div class="sheet-topline">
                @foreach ($topline as $slot => $items)
                    <div class="is-{{ $slot }}">{!! implode(' ', $items) !!}</div>
                @endforeach
            </div>
        @endif
        @include('partials.kop', ['class' => 'kop-print'])

        <div class="sheet-heading">
            <h1>{{ $survey->title }}</h1>
            @if ($survey->subtitle)
                <p>{{ $survey->subtitle }}</p>
            @endif
        </div>
        @if ($settings['sheet_label'] !== '')
            <div class="sheet-rule is-{{ $settings['sheet_label_position'] }}"><span class="sheet-tag">{{ $settings['sheet_label'] }}</span></div>
        @endif
        <div class="sheet-meta">
            <span>Kegiatan: <b>{{ $survey->activity }}</b></span>
            @if ($periodLabel)
                <span>{{ $periodLabel }}</span>
            @endif
        </div>

        <div class="sheet-blocks">
            @foreach ($survey->blocks as $blockIndex => $block)
                @if ($blockIndex > 0 && $orientations[$blockIndex] !== $orientations[$blockIndex - 1])
                    {{-- A change of orientation starts a new sheet (and a new printed page). --}}
                    </div>
                    </main>
                    <main class="sheet is-{{ $orientations[$blockIndex] }}">
                    <div class="sheet-blocks">
                @endif
                <section @class(['sheet-block', 'is-half' => ($block['layout'] ?? 'full') === 'half'])>
                    <div class="sheet-block-title">BLOK {{ \App\Models\Survey::roman($blockIndex + 1) }}. {{ $block['title'] }}</div>
                    <table class="sheet-table">
                        @php
                            $isHalf = ($block['layout'] ?? 'full') === 'half';
                            // Long rows of code boxes would squeeze every answer in the block, so beyond this
                            // many boxes a question keeps its boxes inside its own answer cell instead.
                            $codeCap = $isHalf ? 4 : 8;
                            $widestCode = max([0, ...array_filter(array_column($block['questions'], 'code_length'), fn (int $length): bool => $length <= $codeCap)]);
                        @endphp
                        <colgroup><col class="col-num"><col class="col-label"><col class="col-answer"><col class="col-code" style="width: {{ $widestCode ? $widestCode * ($isHalf ? 13 : 15) + 12 : 0 }}px"></colgroup>
                        <tbody>
                            @foreach ($block['questions'] as $question)
                                {{-- Always-hidden fields (e.g. tracking values) have no place on paper. --}}
                                @continue($question['hidden'] && ! in_array('show', $question['logic']['actions'] ?? [], true))
                                @include('print.partials.question')
                            @endforeach
                        </tbody>
                    </table>
                </section>
            @endforeach
        </div>

        @if ($response)
            <p class="sheet-footnote">Respons dikirim {{ $response->created_at->translatedFormat('d F Y, H:i') }}.</p>
        @endif
    </main>
</body>
</html>
