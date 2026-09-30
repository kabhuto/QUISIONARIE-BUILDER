@php
    $settings = $survey->formSettings();
    $logos = array_filter($survey->headerItems(), fn (array $item): bool => $item['type'] === 'logo' && $item['path']);
    $texts = array_filter($survey->headerItems(), fn (array $item): bool => $item['type'] === 'text' && $item['text'] !== '');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Listing · {{ $survey->title }}</title>
    <link rel="stylesheet" href="{{ asset('css/print.css') }}">
    <style>
        @page { size: 297mm 210mm; margin: 10mm; }
        .sheet { width: 297mm; min-height: 210mm; }
    </style>
</head>
<body class="print-page">
    <div class="print-toolbar">
        <a href="{{ route('surveys.responses.index', $survey) }}">← Respons</a>
        @if ($tables !== [])
            <form method="GET" action="{{ route('surveys.responses.listing', $survey) }}">
                <label>Tampilkan
                    <select name="source" onchange="this.form.submit()">
                        <option value="responses" @selected($source === 'responses')>Satu baris per respons</option>
                        @foreach ($tables as $table)
                            <option value="{{ $table['id'] }}" @selected($source === $table['id'])>Baris tabel: {{ $table['label'] }}</option>
                        @endforeach
                    </select>
                </label>
            </form>
        @endif
        <span>Klik teks bergaris putus-putus untuk mengubahnya sebelum mencetak.</span>
        <button type="button" onclick="window.print()">Cetak / simpan PDF</button>
    </div>

    <main class="sheet listing">
        <header class="listing-head">
            <div class="listing-box">{{ $settings['confidential'] ? 'RAHASIA' : '' }}</div>
            <div class="listing-brand">
                @foreach ($logos as $logo)
                    <img src="{{ asset('storage/'.$logo['path']) }}" style="height: {{ min($logo['height'], 56) }}px" alt="">
                @endforeach
                @if ($texts !== [])
                    <div class="listing-brand-text">
                        @foreach ($texts as $text)
                            <div @class(['is-bold' => $text['style'] === 'bold'])>{{ $text['text'] }}</div>
                        @endforeach
                    </div>
                @endif
            </div>
            <div class="listing-box" contenteditable="true" spellcheck="false">LISTING</div>
        </header>

        <div class="listing-title" contenteditable="true" spellcheck="false">{{ $survey->title }}</div>

        <div class="listing-fields">
            <div><span contenteditable="true" spellcheck="false">Nama Petugas Lapangan</span> : <span class="listing-line" contenteditable="true"></span></div>
            <div><span contenteditable="true" spellcheck="false">Tanggal Pendataan</span> : <span class="listing-line" contenteditable="true"></span></div>
            <div><span contenteditable="true" spellcheck="false">Nama Pemeriksa Lapangan</span> : <span class="listing-line" contenteditable="true"></span></div>
        </div>

        <table class="listing-table">
            <thead>
                <tr>
                    <th>No</th>
                    @foreach ($columns as $column)
                        <th>{{ $column }}</th>
                    @endforeach
                </tr>
                <tr class="sheet-col-numbers">
                    <td>(1)</td>
                    @foreach ($columns as $column)
                        <td>({{ $loop->iteration + 1 }})</td>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td class="is-center">{{ $loop->iteration }}</td>
                        @foreach ($row as $cell)
                            <td>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @endforeach
                @for ($blank = 0; $blank < $blankRows; $blank++)
                    <tr class="is-blank">
                        <td></td>
                        @foreach ($columns as $column)
                            <td></td>
                        @endforeach
                    </tr>
                @endfor
            </tbody>
        </table>

        <div class="listing-sign">
            <div contenteditable="true" spellcheck="false">Mengetahui,<br>Kepala Dusun</div>
            <div class="listing-sign-name">( <span contenteditable="true" spellcheck="false">………………………………</span> )</div>
        </div>
    </main>
</body>
</html>
