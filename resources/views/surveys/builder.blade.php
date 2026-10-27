@php
    $toolIcons = [
        'short' => ['T', 'icon-text'],
        'long' => ['¶', 'icon-paragraph'],
        'single' => ['◉', 'icon-choice'],
        'multiple' => ['☑', 'icon-check'],
        'dropdown' => ['☷', 'icon-select'],
        'number' => ['#', 'icon-number'],
        'currency' => ['Rp', 'icon-number'],
        'date' => ['▦', 'icon-date'],
        'time' => ['◷', 'icon-date'],
        'email' => ['@', 'icon-number'],
        'phone' => ['☏', 'icon-date'],
        'url' => ['↗', 'icon-text'],
        'code' => ['▯▯', 'icon-code'],
        'checkbox' => ['☐', 'icon-check'],
        'rating' => ['★', 'icon-choice'],
        'scale' => ['1–5', 'icon-code'],
        'slider' => ['⇔', 'icon-select'],
        'matrix' => ['⁙', 'icon-choice'],
        'signature' => ['✎', 'icon-paragraph'],
        'region' => ['⌖', 'icon-block'],
        'table' => ['▦▦', 'icon-code'],
        'list' => ['☰', 'icon-text'],
        'calc' => ['ƒx', 'icon-number'],
        'location' => ['⌾', 'icon-block'],
        'file' => ['⇪', 'icon-paragraph'],
        'info' => ['i', 'icon-paragraph'],
    ];
    $isNew = ! $survey->exists;
    $oldBlocks = old('blocks', json_encode($survey->blocks ?? []));
    $oldHeader = old('header', json_encode(['align' => $survey->headerAlign(), 'items' => $survey->headerItems()]));
    $isConfidential = (bool) old('settings.confidential', $settings['confidential']);
    $selectedPaperSize = old('settings.paper_size', $settings['paper_size']);
    $selectedOrientation = old('settings.orientation', $settings['orientation']);
    $selectedPeriodType = old('settings.period_type', $settings['period_type']);
    $selectedTheme = old('settings.theme', $settings['theme']);
    $selectedThemeColor = old('settings.theme_color', $settings['theme_color'] ?? null) ?: '#d6336c';
    $selectedNumbering = old('settings.numbering', $settings['numbering']);
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $isNew ? 'Kuesioner baru' : $survey->title }} | Ruang Kuesioner</title>
    @include('partials.theme', ['accent' => true])
    <link rel="stylesheet" href="{{ asset('css/builder.css') }}">
    <link rel="stylesheet" href="{{ asset('css/ui.css') }}">
</head>
<body class="admin-page builder-page">
    <header class="topbar">
        <a class="brand" href="{{ route('surveys.index') }}" aria-label="Kembali ke dashboard">
            <span class="brand-mark">b<span>p</span>s</span>
            <span class="brand-name">RUANG<span>KUESIONER</span></span>
        </a>
        <div class="topbar-context">
            <span class="live-dot {{ $survey->isPublished() ? '' : 'is-draft' }}"></span>
            {{ $survey->isPublished() ? 'TERBIT' : 'DRAF' }}
            <span class="context-divider">/</span> {{ $isNew ? 'KUESIONER BARU' : str($survey->title)->limit(40) }}
        </div>
        <div class="topbar-actions">
            @include('partials.accent-picker')
            @include('partials.theme-toggle')
            <button class="button button-ghost" type="submit" form="builder-form" name="intent" value="print" title="Simpan lalu buka tampilan cetak kertas">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 9V3h10v6M7 17H4v-7h16v7h-3M7 14h10v7H7z"/></svg>
                Cetak
            </button>
            <button class="button button-ghost" type="submit" form="builder-form" name="intent" value="preview" title="Simpan lalu lihat seperti responden">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                Pratinjau
            </button>
            @unless ($isNew)
                @if ($survey->isPublished())
                    <button class="button button-ghost" type="submit" form="unpublish-form">Tutup formulir</button>
                @else
                    <button class="button button-accent" type="submit" form="publish-form">Publikasikan</button>
                @endif
            @endunless
            <button class="button button-primary" type="submit" form="builder-form" name="intent" value="save">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h12l4 4v14H3V3h2Zm2 0v6h10V3M7 21v-8h10v8M9 5h5"/></svg>
                Simpan
            </button>
        </div>
    </header>

    @if (session('status'))
        <div class="toast" role="status">{{ session('status') }}</div>
    @endif
    @if (session('importNotes'))
        <details class="import-notes" open>
            <summary>{{ count(session('importNotes')) }} catatan impor — periksa bagian berikut</summary>
            <ul>
                @foreach (session('importNotes') as $note)
                    <li>{{ $note }}</li>
                @endforeach
            </ul>
        </details>
    @endif

    @if ($errors->any())
        <div class="toast toast-error" role="alert">
            @foreach (array_unique($errors->all()) as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <main class="app-shell">
        <div class="page-intro">
            <div>
                <div class="eyebrow"><a class="back-link" href="{{ route('surveys.index') }}">← DASHBOARD</a> <span>/</span> {{ $isNew ? 'BUAT KUESIONER' : 'EDIT KUESIONER' }}</div>
                <h1>Susun kuesioner.</h1>
                <p>Atur kop, susun blok dan pertanyaan, lalu pratinjau, cetak, atau publikasikan.</p>
            </div>
            <div class="intro-meta"><span class="meta-label">FORMAT</span><span class="meta-value" id="format-summary">Kertas A4 · Potret</span></div>
        </div>

        @if ($survey->isPublished())
            <div class="share-bar">
                <span class="share-label">TAUTAN RESPONDEN</span>
                <input class="share-url" type="text" value="{{ route('form.create', $survey) }}" readonly aria-label="Tautan responden" id="share-url">
                <button class="button button-ghost" type="button" data-copy="#share-url">Salin</button>
                <a class="button button-ghost" href="{{ route('form.create', $survey) }}" target="_blank" rel="noopener">Buka</a>
                <a class="button button-ghost" href="{{ route('surveys.responses.index', $survey) }}">Lihat respons</a>
            </div>
        @endif

        <form id="builder-form" class="builder-grid" method="POST" action="{{ $isNew ? route('surveys.store') : route('surveys.update', $survey) }}">
            @csrf
            @unless ($isNew)
                @method('PUT')
            @endunless
            <aside class="toolbox panel">
                <div class="panel-heading">
                    <div><span class="eyebrow">KOMPONEN</span><h2>Struktur formulir</h2></div>
                    <span class="tool-count">{{ str_pad(count($questionTypes) + 1, 2, '0', STR_PAD_LEFT) }}</span>
                </div>
                <p class="panel-hint">Tarik ke lembar atau klik untuk menambahkan.</p>
                <div class="tool-list">
                    <button class="tool-item tool-block" type="button" draggable="true" data-tool="block"><span class="tool-icon icon-block">▤</span><span>Blok baru</span><span class="tool-plus">+</span></button>
                    <button class="tool-item" type="button" data-open-kop><span class="tool-icon icon-block">⚑</span><span>Atur kop & pembuka</span><span class="tool-plus">›</span></button>
                </div>
                <div class="tool-group-label eyebrow">PERTANYAAN & ISIAN</div>
                <div class="tool-list" id="question-tools">
                    @foreach ($questionTypes as $type => $label)
                        <button class="tool-item" type="button" draggable="true" data-tool="{{ $type }}"><span class="tool-icon {{ $toolIcons[$type][1] }}">{{ $toolIcons[$type][0] }}</span><span>{{ $label }}</span><span class="tool-plus">+</span></button>
                    @endforeach
                </div>
                <div class="toolbox-divider"></div>
                <p class="empty-recent"><b>Logika:</b> pilih blok atau pertanyaan, lalu atur “Tampilkan jika…” di panel kanan.</p>
            </aside>

            <section class="editor-column" aria-label="Lembar kuesioner">
                <div class="editor-toolbar">
                    <div class="breadcrumb"><span>Survei</span><b>/</b><strong>{{ $isNew ? 'Draf baru' : 'Edit' }}</strong></div>
                    <span class="page-indicator"><span class="page-dot"></span> HALAMAN 01</span>
                </div>

                <article class="paper" id="question-canvas">
                    <div class="paper-topline" id="topline-rule" data-rule>
                        <div class="rule-slot" data-rule-slot="left"></div>
                        <div class="rule-slot" data-rule-slot="center"></div>
                        <div class="rule-slot" data-rule-slot="right"></div>
                        <span class="rule-chip" data-rule-chip data-position-input="#topline-label-position">
                            <span class="drag-handle" data-rule-drag title="Seret ke kiri, tengah, atau kanan">⠿</span>
                            <input class="topline-input" name="settings[topline_label]" type="text" maxlength="60" value="{{ old('settings.topline_label', $settings['topline_label']) }}" placeholder="Teks atas (kosong = tidak tampil)" aria-label="Teks di atas lembar, mis. FORMULIR PENDATAAN">
                            <button class="rule-clear" type="button" data-rule-clear title="Hilangkan teks" aria-label="Hilangkan teks atas">×</button>
                        </span>
                        <span class="rule-chip" data-rule-chip data-position-input="#form-code-position">
                            <span class="drag-handle" data-rule-drag title="Seret ke kiri, tengah, atau kanan">⠿</span>
                            <input class="form-code" name="form_code" type="text" value="{{ old('form_code', $survey->form_code) }}" maxlength="40" aria-label="Kode formulir (opsional)" placeholder="KODE FORMULIR">
                            <button class="rule-clear" type="button" data-rule-clear title="Hilangkan kode formulir" aria-label="Hilangkan kode formulir">×</button>
                        </span>
                        <input type="hidden" name="settings[topline_label_position]" id="topline-label-position" value="{{ old('settings.topline_label_position', $settings['topline_label_position']) }}">
                        <input type="hidden" name="settings[form_code_position]" id="form-code-position" value="{{ old('settings.form_code_position', $settings['form_code_position']) }}">
                    </div>
                    <div class="kop-preview" id="kop-preview" role="button" tabindex="0" title="Klik untuk mengatur kop, atau seret file logo ke sini" aria-label="Atur kop"></div>
                    <div class="form-title-wrap">
                        <label class="sr-only" for="survey-title">Judul kuesioner</label>
                        <input class="form-title" id="survey-title" name="title" type="text" value="{{ old('title', $survey->title) }}" maxlength="160" placeholder="JUDUL KUESIONER" required>
                        <label class="sr-only" for="survey-subtitle">Subjudul kuesioner</label>
                        <textarea class="form-subtitle" id="survey-subtitle" name="subtitle" rows="2" maxlength="500" placeholder="Subjudul atau keterangan singkat (opsional)">{{ old('subtitle', $survey->subtitle) }}</textarea>
                    </div>
                    @php
                        $sheetLabelPosition = old('settings.sheet_label_position', $settings['sheet_label_position']);
                    @endphp
                    <div class="paper-rule" id="sheet-rule" data-rule>
                        <div class="rule-slot" data-rule-slot="left"></div>
                        <div class="rule-slot" data-rule-slot="center"></div>
                        <div class="rule-slot" data-rule-slot="right"><span class="quarter-label" id="period-label" data-rule-fixed><span id="period-name">Triwulan</span> <b id="period-display">1</b></span></div>
                        <span class="rule-chip" id="sheet-label-chip" data-rule-chip data-position-input="#sheet-label-position">
                            <span class="drag-handle" data-rule-drag title="Seret ke kiri, tengah, atau kanan">⠿</span>
                            <input class="sheet-label-input" name="settings[sheet_label]" type="text" maxlength="60" value="{{ old('settings.sheet_label', $settings['sheet_label']) }}" placeholder="Label lembar (kosong = tidak tampil)" aria-label="Label lembar, mis. RAHASIA">
                            <button class="rule-clear" type="button" data-rule-clear title="Hilangkan label" aria-label="Hilangkan label lembar">×</button>
                        </span>
                        <input type="hidden" name="settings[sheet_label_position]" id="sheet-label-position" value="{{ $sheetLabelPosition }}">
                    </div>
                    <button class="intro-preview" type="button" data-open-kop="intro" id="intro-preview" title="Klik untuk mengatur teks pembuka"></button>

                    <section class="activity-section">
                        <div class="section-band"><span class="section-number">A</span><h2>KETERANGAN KEGIATAN</h2><span class="section-rule"></span></div>
                        <div class="activity-row">
                            <label for="activity">Nama kegiatan</label>
                            <div class="activity-control">
                                <input id="activity" name="activity" type="text" value="{{ old('activity', $survey->activity) }}" maxlength="255" placeholder="mis. Survei Kepuasan Konsumen 2026" required>
                                <div class="activity-mode" role="group" aria-label="Sumber nama kegiatan">
                                    <label><input type="radio" name="activity-source" value="manual" checked> Baru</label>
                                    <label><input type="radio" name="activity-source" value="previous" @disabled($activities->isEmpty())> Sebelumnya</label>
                                </div>
                                <select class="previous-select" id="previous-activity" aria-label="Pilih kegiatan sebelumnya" hidden @disabled($activities->isEmpty())>
                                    <option value="">Pilih kegiatan tersimpan</option>
                                    @foreach ($activities as $activity)
                                        <option value="{{ $activity }}">{{ $activity }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </section>

                    <div class="question-section-heading"><div><span class="eyebrow">B</span><h2>BLOK ISIAN</h2></div><span class="question-total"><b id="block-count">0</b> BLOK · <b id="question-count">0</b> PERTANYAAN</span></div>
                    <div class="block-list" id="block-list" aria-live="polite"></div>
                    <div class="drop-hint" id="drop-hint"><span>+</span><strong>Tarik blok atau pertanyaan ke sini</strong><small>atau klik komponen di panel kiri</small></div>
                    <div class="paper-foot"><span>BADAN PUSAT STATISTIK</span><span>HALAMAN <b>1</b></span></div>
                </article>
                <div class="editor-footnote"><span class="footnote-mark">i</span> Tarik ikon ⠿ untuk memindahkan blok, pertanyaan, atau kolom tabel. Klik kop untuk mengatur logo dan label.</div>
                <input type="hidden" name="blocks" id="blocks-payload" value="{{ $oldBlocks }}">
                <input type="hidden" name="header" id="header-payload" value="{{ $oldHeader }}">
            </section>

            <aside class="settings-panel">
                <div class="panel-tabs" role="tablist" aria-label="Panel pengaturan">
                    <button type="button" role="tab" data-panel-tab="item" aria-selected="true">Isian dipilih</button>
                    <button type="button" role="tab" data-panel-tab="form" aria-selected="false">Properti formulir</button>
                </div>
                <div data-panel-pane="item">
                    <div class="setting-block selected-setting" id="selection-panel"></div>
                </div>
                <div data-panel-pane="form" hidden>
                <div class="settings-title"><span class="eyebrow">PENGATURAN</span><h2>Properti formulir</h2></div>
                <div class="setting-block">
                    <div class="setting-label"><span>Penomoran pertanyaan</span><span class="setting-value">NOMOR</span></div>
                    <div class="segmented">
                        @foreach ($numberingStyles as $style => $label)
                            <label title="{{ $label }}"><input class="sr-only" type="radio" name="settings[numbering]" value="{{ $style }}" @checked($selectedNumbering === $style)><span>{{ $style === 'block' ? '101, 102' : '1, 2, 3' }}</span></label>
                        @endforeach
                    </div>
                </div>
                <div class="setting-block">
                    <div class="setting-label"><span>Tampilan responden</span><span class="setting-value">WARNA</span></div>
                    <div class="theme-options">
                        @foreach ($themes as $theme => $definition)
                            @if ($theme === 'custom')
                                <label @class(['theme-option', 'theme-option-custom', 'has-color' => $selectedTheme === 'custom']) title="Pilih warna sendiri" style="--swatch: {{ $selectedThemeColor }}" data-theme-custom>
                                    <input class="sr-only" type="radio" name="settings[theme]" value="custom" @checked($selectedTheme === 'custom')>
                                    <span></span>
                                    <input type="color" name="settings[theme_color]" value="{{ $selectedThemeColor }}" aria-label="Warna kustom">
                                    <small>{{ $definition['label'] }}</small>
                                </label>
                            @else
                                <label class="theme-option" title="{{ $definition['label'] }}">
                                    <input class="sr-only" type="radio" name="settings[theme]" value="{{ $theme }}" @checked($selectedTheme === $theme)>
                                    <span style="--swatch: {{ $definition['color'] }}"></span>
                                    <small>{{ $definition['label'] }}</small>
                                </label>
                            @endif
                        @endforeach
                    </div>
                    @error('settings.theme_color')<p class="setting-note is-error">{{ $message }}</p>@enderror
                    <script>
                        // Opening the colour input chooses the "Kustom" option; the colour only counts while it is chosen.
                        (() => {
                            const custom = document.querySelector('[data-theme-custom]');
                            const radio = custom.querySelector('input[type=radio]');
                            const color = custom.querySelector('input[type=color]');
                            const sync = () => {
                                custom.classList.toggle('has-color', radio.checked);
                                custom.style.setProperty('--swatch', color.value);
                            };
                            color.addEventListener('click', () => {
                                if (radio.checked) return;
                                radio.checked = true;
                                radio.dispatchEvent(new Event('change', { bubbles: true }));
                            });
                            color.addEventListener('input', sync);
                            document.querySelectorAll('input[name="settings[theme]"]').forEach(input => input.addEventListener('change', sync));
                        })();
                    </script>
                    <label class="required-toggle">
                        <input type="hidden" name="settings[confidential]" value="0">
                        <input type="checkbox" name="settings[confidential]" value="1" id="confidential-toggle" @checked($isConfidential)>
                        <span class="toggle-track"></span><span>Data <b>RAHASIA</b> (cantumkan UU Statistik)</span>
                    </label>
                    <label class="setting-field"><span>Pesan setelah mengirim</span>
                        <textarea class="setting-select setting-textarea" name="settings[thank_you_message]" rows="3" maxlength="500">{{ old('settings.thank_you_message', $settings['thank_you_message']) }}</textarea>
                    </label>
                    <label class="setting-field"><span>Webhook respons baru <small>(opsional)</small></span>
                        <input class="setting-select" type="url" name="settings[webhook_url]" maxlength="500" placeholder="https://aplikasi-anda/terima-respons" value="{{ old('settings.webhook_url', $settings['webhook_url'] ?? '') }}">
                    </label>
                    <p class="setting-note">Setiap respons baru dikirim sebagai JSON (POST) ke alamat ini, ditandatangani dengan header X-Ruang-Signature. Lihat menu API.</p>
                    <label class="setting-field"><span>Google Drive <small>(Web App Apps Script, opsional)</small></span>
                        <input class="setting-select" type="url" name="settings[drive_url]" maxlength="500" placeholder="https://script.google.com/macros/s/…/exec" value="{{ old('settings.drive_url', $settings['drive_url'] ?? '') }}">
                    </label>
                    <p class="setting-note">Foto, berkas, dan tanda tangan setiap respons disalin ke folder Drive, dan satu baris ditambahkan ke spreadsheet rekap.
                        @unless ($isNew) <a href="{{ route('google-drive.show', $survey) }}">Panduan menyambungkan Google Drive →</a> @endunless
                    </p>
                </div>
                <div class="setting-block">
                    <div class="setting-label"><span>Ukuran kertas cetak</span><span class="setting-value" id="paper-dimension">210 × 297 MM</span></div>
                    <div class="paper-size-options">
                        @foreach ($paperSizes as $size => [$width, $height])
                            <label class="paper-size-option">
                                <input class="sr-only" type="radio" name="settings[paper_size]" value="{{ $size }}" @checked($selectedPaperSize === $size)>
                                <span class="paper-size-icon" style="aspect-ratio: {{ $width }} / {{ $height }}"></span>
                                <span><strong>{{ $size }}</strong><small>{{ $width }} × {{ $height }} mm</small></span>
                            </label>
                        @endforeach
                    </div>
                </div>
                <div class="setting-block">
                    <div class="setting-label"><span>Orientasi</span><span class="setting-value" id="orientation-value">POTRET</span></div>
                    <div class="segmented">
                        <label><input class="sr-only" type="radio" name="settings[orientation]" value="portrait" @checked($selectedOrientation === 'portrait')><span><i class="orientation-glyph"></i>Potret</span></label>
                        <label><input class="sr-only" type="radio" name="settings[orientation]" value="landscape" @checked($selectedOrientation === 'landscape')><span><i class="orientation-glyph is-landscape"></i>Lanskap</span></label>
                    </div>
                </div>
                <div class="setting-block">
                    <div class="setting-label"><span>Periode</span><span class="setting-value">DI LEMBAR</span></div>
                    <div class="segmented">
                        @foreach ($periodTypes as $periodType => $period)
                            <label><input class="sr-only" type="radio" name="settings[period_type]" value="{{ $periodType }}" @checked($selectedPeriodType === $periodType)><span>{{ $period['label'] }}</span></label>
                        @endforeach
                    </div>
                    <label class="sr-only" for="period-value">Nilai periode</label>
                    <select class="setting-select" id="period-value" name="settings[period_value]" data-selected="{{ old('settings.period_value', $settings['period_value'] ?? 1) }}"></select>
                </div>
                </div>
            </aside>

            <dialog class="kop-dialog" id="kop-dialog" aria-labelledby="kop-dialog-title">
                <header class="kop-dialog-head">
                    <div><span class="eyebrow">KOP & PEMBUKA</span><h2 id="kop-dialog-title">Atur tampilan pembuka formulir</h2></div>
                    <div class="kop-tabs" role="tablist">
                        <button type="button" role="tab" data-kop-tab="kop" aria-selected="true">Kop</button>
                        <button type="button" role="tab" data-kop-tab="intro" aria-selected="false">Teks pembuka</button>
                    </div>
                    <button class="button button-primary" type="button" data-close-kop>Selesai</button>
                </header>

                <section class="kop-tab-panel" data-kop-panel="kop">
                    <div class="kop-live" id="kop-dialog-preview" aria-label="Pratinjau kop"></div>
                    <p class="panel-hint">Tarik ⠿ untuk memindahkan logo, teks, atau label ke kiri, tengah, atau kanan. <b>Seret file gambar dari komputer</b> ke kolom mana pun untuk menambah logo, atau ke kartu logo untuk menggantinya. Hapus label (mis. CAWI) dengan tombol ×.</p>
                    <p class="kop-status" id="kop-status" role="status" aria-live="polite"></p>
                    <div class="kop-align" role="group" aria-label="Perataan tegak kolom kop">
                        <span>Perataan tegak kolom:</span>
                        @foreach (\App\Models\Survey::HEADER_ALIGNMENTS as $alignment => $label)
                            <button class="mini-button" type="button" data-kop-align="{{ $alignment }}" aria-pressed="false">{{ $label }}</button>
                        @endforeach
                        <small>Pilih <b>Bawah</b> agar label di kiri dan kanan (mis. RAHASIA dan CAWI) sejajar meski salah satunya di bawah logo.</small>
                    </div>
                    <div class="kop-slots" id="kop-slots"></div>
                </section>

                <section class="kop-tab-panel" data-kop-panel="intro" hidden>
                    <div class="intro-grid">
                        <label class="setting-field intro-wide"><span>Teks sambutan</span>
                            <textarea class="setting-select setting-textarea" name="intro[text]" rows="4" maxlength="2000" placeholder="Terima kasih atas partisipasi Bapak/Ibu…">{{ old('intro.text', $intro['text']) }}</textarea>
                        </label>
                        <fieldset class="intro-help intro-wide">
                            <legend>Bantuan pengisian kuesioner <small>(kosongkan bila tidak perlu)</small></legend>
                            <label class="setting-field"><span>Nama unit/sekretariat</span><input class="setting-select" name="intro[help_title]" maxlength="160" value="{{ old('intro.help_title', $intro['help_title']) }}" placeholder="mis. Sekretariat Survei 2026"></label>
                            <label class="setting-field"><span>Instansi</span><input class="setting-select" name="intro[help_org]" maxlength="160" value="{{ old('intro.help_org', $intro['help_org']) }}"></label>
                            <label class="setting-field"><span>Call center</span><input class="setting-select" name="intro[help_phone]" maxlength="60" value="{{ old('intro.help_phone', $intro['help_phone']) }}"></label>
                            <label class="setting-field"><span>Email</span><input class="setting-select" name="intro[help_email]" maxlength="160" value="{{ old('intro.help_email', $intro['help_email']) }}"></label>
                        </fieldset>
                        <label class="setting-field intro-wide"><span>Pemberitahuan <small>(kotak hijau)</small></span>
                            <input class="setting-select" name="intro[notice]" maxlength="500" value="{{ old('intro.notice', $intro['notice']) }}" placeholder="mis. Pendataan ini tidak ada hubungannya dengan pajak dan tidak dipungut biaya apapun.">
                        </label>
                        <label class="setting-field intro-wide"><span>Petunjuk pengisian <small>(kotak biru, satu poin per baris)</small></span>
                            <textarea class="setting-select setting-textarea" name="intro[guide]" rows="4" maxlength="2000">{{ old('intro.guide', $intro['guide']) }}</textarea>
                        </label>
                    </div>
                </section>
            </dialog>
        </form>

        @unless ($isNew)
            <form id="publish-form" method="POST" action="{{ route('published-surveys.store', $survey) }}" hidden>@csrf</form>
            <form id="unpublish-form" method="POST" action="{{ route('published-surveys.destroy', $survey) }}" hidden>@csrf @method('DELETE')</form>
        @endunless
    </main>
    <script>
        window.builderConfig = @json($builderConfig);
        window.headerConfig = @json($headerConfig);
    </script>
    <script src="{{ asset('js/builder-header.js') }}" defer></script>
    <script src="{{ asset('js/builder.js') }}" defer></script>
</body>
</html>
