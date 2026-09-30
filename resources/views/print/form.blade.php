@php
    [$paperWidth, $paperHeight] = \App\Models\Survey::PAPER_SIZES[$settings['paper_size']] ?? [210, 297];
    if ($settings['orientation'] === 'landscape') {
        [$paperWidth, $paperHeight] = [$paperHeight, $paperWidth];
    }
    $periodLabel = $survey->periodLabel();
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
        .sheet { width: {{ $paperWidth }}mm; min-height: {{ $paperHeight }}mm; }
    </style>
</head>
<body class="print-page">
    <div class="print-toolbar">
        <a href="{{ $response ? route('surveys.responses.show', [$survey, $response]) : route('surveys.edit', $survey) }}">← Kembali</a>
        <span>{{ $response ? 'Formulir terisi' : 'Formulir kosong' }} · kertas {{ $settings['paper_size'] }} {{ $settings['orientation'] === 'landscape' ? 'lanskap' : 'potret' }}</span>
        <button type="button" onclick="window.print()">Cetak / simpan PDF</button>
    </div>

    <main class="sheet">
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
                <section @class(['sheet-block', 'is-half' => ($block['layout'] ?? 'full') === 'half'])>
                    <div class="sheet-block-title">BLOK {{ \App\Models\Survey::roman($blockIndex + 1) }}. {{ $block['title'] }}</div>
                    <table class="sheet-table">
                        @php
                            $widestCode = max([0, ...array_column($block['questions'], 'code_length')]);
                        @endphp
                        <colgroup><col class="col-num"><col class="col-label"><col class="col-answer"><col class="col-code" style="width: {{ $widestCode ? $widestCode * (($block['layout'] ?? 'full') === 'half' ? 13 : 15) + 12 : 0 }}px"></colgroup>
                        <tbody>
                            @foreach ($block['questions'] as $question)
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
