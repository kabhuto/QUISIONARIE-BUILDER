@php
    $canSubmit = $survey->isPublished() && ! $isPreview;
    $periodLabel = $survey->periodLabel();
    $settings = $survey->formSettings();
    $intro = $survey->formIntro();
    $numbers = $survey->questionNumbers();
    $hasHelp = $intro['help_title'] !== '' || $intro['help_phone'] !== '' || $intro['help_email'] !== '';
    $guideLines = array_filter(array_map('trim', explode("\n", $intro['guide'])));
    $assignment ??= null;
    $fillConfig = [
        'draftKey' => 'quisioner:'.$survey->slug.($assignment ? ':'.$assignment->token : ''),
        'useDraft' => $canSubmit,
        'restoreDraft' => $canSubmit && ! $errors->any(),
    ];
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $survey->title }}</title>
    @include('partials.theme')
    <link rel="stylesheet" href="{{ asset('css/fill.css') }}">
    @if (collect($survey->questions())->contains('type', 'location'))
        @include('partials.leaflet')
    @endif
</head>
<body class="fill-page" style="--accent: {{ $survey->themeColor() }}; --accent-ink: {{ $survey->themeInk() }}">
    @include('partials.theme-toggle', ['class' => 'theme-toggle-floating'])
    @if ($isPreview)
        <div class="preview-banner">
            <span><b>Mode pratinjau</b> — beginilah tampilan untuk responden. Jawaban tidak disimpan.</span>
            <a href="{{ route('surveys.edit', $survey) }}">← Kembali ke editor</a>
        </div>
    @endif

    @if ($canSubmit || $isPreview)
        {{-- Phones and tablets: the current block, its progress, and buttons to move between blocks. --}}
        <div class="cawi-mobilebar" data-mobilebar>
            <button class="mobilebar-menu" type="button" data-nav-open aria-controls="cawi-nav" aria-expanded="false">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
                <span class="mobilebar-copy"><small data-mobilebar-block>PENGANTAR</small><strong data-mobilebar-title>{{ str($survey->title)->limit(40) }}</strong></span>
            </button>
            <button class="mobilebar-step" type="button" data-block-step="-1" aria-label="Blok sebelumnya"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg></button>
            <button class="mobilebar-step" type="button" data-block-step="1" aria-label="Blok berikutnya"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></button>
            <div class="mobilebar-progress" aria-hidden="true"><span data-progress-bar></span></div>
        </div>
    @endif

    <div class="cawi-layout">
        <div class="cawi-nav-backdrop" data-nav-close hidden></div>
        <aside class="cawi-nav" id="cawi-nav" aria-label="Daftar blok dan pertanyaan">
            <div class="cawi-nav-title">
                <strong>{{ str($survey->title)->limit(48) }}</strong>
                <small>{{ $survey->activity }}</small>
                <button class="cawi-nav-close" type="button" data-nav-close aria-label="Tutup daftar blok">×</button>
            </div>
            <nav class="cawi-nav-links">
                <a class="cawi-nav-link" href="#pengantar" data-nav-target="pengantar"><span>PENGANTAR</span></a>
                @foreach ($survey->blocks as $blockIndex => $block)
                    <div class="cawi-nav-group" data-nav-group="{{ $block['id'] }}">
                        <a class="cawi-nav-link" href="#blok-{{ $block['id'] }}" data-nav-target="blok-{{ $block['id'] }}" data-nav-block="{{ $block['id'] }}">
                            <span>BLOK {{ \App\Models\Survey::roman($blockIndex + 1) }} <em class="nav-count" data-nav-count></em></span>
                            <small>{{ $block['title'] }}</small>
                        </a>
                        <ol class="nav-questions" data-nav-questions="{{ $block['id'] }}"></ol>
                    </div>
                @endforeach
            </nav>
            @if ($canSubmit || $isPreview)
                <div class="cawi-nav-progress">
                    <div class="fill-progress" aria-hidden="true"><span data-progress-bar></span></div>
                    <p data-progress-text aria-live="polite"></p>
                    <div class="nav-legend" aria-hidden="true">
                        <span><i class="status-dot is-filled"></i>Terisi</span><span><i class="status-dot is-empty"></i>Kosong</span><span><i class="status-dot is-warning"></i>Peringatan</span><span><i class="status-dot is-error"></i>Galat</span>
                    </div>
                    <button class="cawi-button is-ghost nav-summary-button" type="button" data-open-summary>Lihat ringkasan isian</button>
                </div>
            @endif
        </aside>

        <main class="cawi-main">
            <section class="cawi-card cawi-cover" id="pengantar">
                @include('partials.kop', ['class' => 'kop-cawi'])
                <div class="cawi-titles">
                    <h1>{{ $survey->title }}</h1>
                    @if ($survey->subtitle)
                        <p>{{ $survey->subtitle }}</p>
                    @endif
                    <div class="fill-chips">
                        <span class="fill-chip">{{ $survey->activity }}</span>
                        @if ($periodLabel)
                            <span class="fill-chip">{{ $periodLabel }}</span>
                        @endif
                        @if ($survey->form_code)
                            <span class="fill-chip is-muted">{{ $survey->form_code }}</span>
                        @endif
                    </div>
                </div>

                <div class="cawi-intro">
                    @if ($intro['text'] !== '')
                        <p>{!! nl2br(e($intro['text'])) !!}</p>
                    @endif
                    @if ($settings['confidential'])
                        <p>Kerahasiaan data yang diberikan dijamin oleh Undang-Undang Nomor 16 Tahun 1997 tentang Statistik.</p>
                    @endif

                    @if ($hasHelp)
                        <div class="cawi-help">
                            <strong>Bantuan Pengisian Kuesioner</strong>
                            <div class="cawi-help-grid">
                                <div><b>{{ $intro['help_title'] ?: $intro['help_org'] }}</b>@if ($intro['help_title'] && $intro['help_org'])<span>{{ $intro['help_org'] }}</span>@endif</div>
                                @if ($intro['help_phone'] !== '')
                                    <div><small>CALL CENTER</small><span>{{ $intro['help_phone'] }}</span></div>
                                @endif
                                @if ($intro['help_email'] !== '')
                                    <div><small>EMAIL</small><span>{{ $intro['help_email'] }}</span></div>
                                @endif
                            </div>
                        </div>
                    @endif

                    @if ($intro['notice'] !== '')
                        <div class="cawi-notice">{{ $intro['notice'] }}</div>
                    @endif
                </div>

                @if ($guideLines !== [])
                    <div class="cawi-guide">
                        <strong>ⓘ Petunjuk Pengisian</strong>
                        <ul>
                            @foreach ($guideLines as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </section>

            @if (! $survey->isPublished() && ! $isPreview)
                <section class="cawi-card fill-closed">
                    <h2>Formulir ini sedang tidak menerima jawaban.</h2>
                    <p>Silakan hubungi petugas yang membagikan tautan ini.</p>
                </section>
            @else
                @if ($errors->any())
                    <div class="fill-alert" role="alert">Ada {{ $errors->count() }} isian yang perlu diperbaiki. Periksa kolom yang ditandai merah.</div>
                @endif

                <form class="fill-form" id="fill-form" method="POST" action="{{ $canSubmit ? route('form.store', $survey) : '#' }}" enctype="multipart/form-data" novalidate @if ($isPreview) data-preview @endif>
                    @csrf
                    {{-- When the respondent opened the form; set by JavaScript, kept with the draft. --}}
                    <input type="hidden" name="_started_at" value="{{ old('_started_at') }}" data-started-at>
                    @if ($assignment)
                        <input type="hidden" name="a" value="{{ $assignment->token }}">
                        <div class="fill-assignment">Penugasan{{ $assignment->label ? ': '.$assignment->label : '' }} — sebagian jawaban sudah terisi dari data awal.</div>
                    @endif
                    @error('a')
                        <div class="fill-alert" role="alert">{{ $message }}</div>
                    @enderror
                    @foreach ($survey->blocks as $blockIndex => $block)
                        @php
                            $repeatSource = \App\Support\SurveyLogic::repeatSource($block);
                            $repeatKind = $repeatSource ? (collect($survey->questions())->firstWhere('id', $repeatSource)['type'] ?? 'number') : null;
                        @endphp
                        <section @class(['cawi-card', 'fill-block', 'is-roster' => $repeatSource]) id="blok-{{ $block['id'] }}" data-block-id="{{ $block['id'] }}" @if (! empty($block['condition'])) data-condition="{{ json_encode($block['condition']) }}" @endif
                            @if ($repeatSource) data-repeat-source="{{ $repeatSource }}" data-repeat-max="{{ $block['repeat']['max'] ?? 20 }}" data-repeat-kind="{{ $repeatKind }}" @endif>
                            <header class="cawi-block-head"><h2>BLOK {{ \App\Models\Survey::roman($blockIndex + 1) }}. {{ $block['title'] }}</h2></header>
                            @if ($repeatSource)
                                {{-- One set of questions per answer of the source question; JavaScript adds and removes rows. --}}
                                <p class="fill-hint roster-empty" data-roster-empty>Blok ini diisi untuk setiap {{ $repeatKind === 'number' ? 'jumlah' : 'jawaban' }} pada pertanyaan {{ $numbers[$repeatSource] ?? '' }}. Jawab pertanyaan itu terlebih dahulu.</p>
                                <div class="roster-rows" data-roster-rows>
                                    @foreach (\App\Support\SurveyLogic::rosterRows($block, (array) old('answers', [])) as $row => $rowLabel)
                                        @include('form.partials.roster-row', ['row' => $row, 'rowLabel' => $rowLabel])
                                    @endforeach
                                </div>
                                <template data-roster-template>
                                    @include('form.partials.roster-row', ['row' => '__R__', 'rowLabel' => ''])
                                </template>
                            @else
                                <div class="cawi-block-body">
                                    @foreach ($block['questions'] as $question)
                                        @include('form.partials.question', ['question' => $question, 'numbers' => $numbers, 'row' => null])
                                    @endforeach
                                </div>
                            @endif
                        </section>
                    @endforeach

                    <div class="cawi-actions">
                        <p class="fill-note" id="fill-note">Tanda <span class="fill-required">*</span> wajib diisi. <span data-draft-status></span></p>
                        @unless ($isPreview)
                            <button class="cawi-button is-ghost" type="button" data-save-draft>Simpan sementara</button>
                        @endunless
                        <button class="cawi-button" type="submit">{{ $isPreview ? 'Coba kirim (pratinjau)' : 'Kirim jawaban' }}</button>
                    </div>
                </form>
            @endif

            <footer class="fill-footer">
                Badan Pusat Statistik
                @if ($settings['confidential'])
                    · Data dijaga kerahasiaannya sesuai UU No. 16 Tahun 1997 tentang Statistik
                @endif
            </footer>
        </main>
    </div>

    @if ($canSubmit || $isPreview)
        {{-- Shown before sending: what is filled, empty, wrong or worth a second look, like FASIH. --}}
        <dialog class="fill-summary" data-summary aria-labelledby="summary-title">
            <div class="summary-head">
                <div>
                    <h2 id="summary-title">Ringkasan isian</h2>
                    <p data-summary-lead>Periksa kembali sebelum mengirim.</p>
                </div>
                <button class="summary-close" type="button" data-summary-close aria-label="Tutup ringkasan">×</button>
            </div>
            <div class="summary-tiles" role="tablist" aria-label="Status isian">
                <button type="button" class="summary-tile is-error" role="tab" data-summary-filter="error"><strong data-summary-count="error">0</strong><span>Galat</span></button>
                <button type="button" class="summary-tile is-warning" role="tab" data-summary-filter="warning"><strong data-summary-count="warning">0</strong><span>Peringatan</span></button>
                <button type="button" class="summary-tile is-empty" role="tab" data-summary-filter="empty"><strong data-summary-count="empty">0</strong><span>Kosong</span></button>
                <button type="button" class="summary-tile is-filled" role="tab" data-summary-filter="filled"><strong data-summary-count="filled">0</strong><span>Terisi</span></button>
            </div>
            <div class="summary-list" data-summary-list role="tabpanel"></div>
            <div class="summary-foot">
                <p class="summary-note" data-summary-note></p>
                <button class="cawi-button is-ghost" type="button" data-summary-close>Kembali mengisi</button>
                <button class="cawi-button" type="button" data-summary-submit>{{ $isPreview ? 'Selesai (pratinjau)' : 'Kirim jawaban' }}</button>
            </div>
        </dialog>
    @endif

    <script>
        window.fillConfig = @json($fillConfig);
    </script>
    <script src="{{ asset('js/fill.js') }}" defer></script>
</body>
</html>
