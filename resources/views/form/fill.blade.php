@php
    $canSubmit = $survey->isPublished() && ! $isPreview;
    $periodLabel = $survey->periodLabel();
    $settings = $survey->formSettings();
    $intro = $survey->formIntro();
    $numbers = $survey->questionNumbers();
    $hasHelp = $intro['help_title'] !== '' || $intro['help_phone'] !== '' || $intro['help_email'] !== '';
    $guideLines = array_filter(array_map('trim', explode("\n", $intro['guide'])));
    $fillConfig = [
        'draftKey' => 'quisioner:'.$survey->slug,
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
    <link rel="stylesheet" href="{{ asset('css/fill.css') }}">
</head>
<body class="fill-page" style="--accent: {{ $survey->themeColor() }}">
    @if ($isPreview)
        <div class="preview-banner">
            <span><b>Mode pratinjau</b> — beginilah tampilan untuk responden. Jawaban tidak disimpan.</span>
            <a href="{{ route('surveys.edit', $survey) }}">← Kembali ke editor</a>
        </div>
    @endif

    <div class="cawi-layout">
        <aside class="cawi-nav" aria-label="Daftar blok">
            <div class="cawi-nav-title">
                <strong>{{ str($survey->title)->limit(48) }}</strong>
                <small>{{ $survey->activity }}</small>
            </div>
            <nav class="cawi-nav-links">
                <a class="cawi-nav-link" href="#pengantar" data-nav-target="pengantar"><span>PENGANTAR</span></a>
                @foreach ($survey->blocks as $blockIndex => $block)
                    <a class="cawi-nav-link" href="#blok-{{ $block['id'] }}" data-nav-target="blok-{{ $block['id'] }}" data-nav-block="{{ $block['id'] }}">
                        <span>BLOK {{ \App\Models\Survey::roman($blockIndex + 1) }}</span>
                        <small>{{ $block['title'] }}</small>
                    </a>
                @endforeach
            </nav>
            @if ($canSubmit || $isPreview)
                <div class="cawi-nav-progress">
                    <div class="fill-progress" aria-hidden="true"><span data-progress-bar></span></div>
                    <p data-progress-text aria-live="polite"></p>
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

                <form class="fill-form" id="fill-form" method="POST" action="{{ $canSubmit ? route('form.store', $survey) : '#' }}" novalidate @if ($isPreview) data-preview @endif>
                    @csrf
                    @foreach ($survey->blocks as $blockIndex => $block)
                        <section class="cawi-card fill-block" id="blok-{{ $block['id'] }}" data-block-id="{{ $block['id'] }}" @if (! empty($block['condition'])) data-condition="{{ json_encode($block['condition']) }}" @endif>
                            <header class="cawi-block-head"><h2>BLOK {{ \App\Models\Survey::roman($blockIndex + 1) }}. {{ $block['title'] }}</h2></header>
                            <div class="cawi-block-body">
                                @foreach ($block['questions'] as $question)
                                    @include('form.partials.question', ['question' => $question, 'numbers' => $numbers])
                                @endforeach
                            </div>
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

    <script>
        window.fillConfig = @json($fillConfig);
    </script>
    <script src="{{ asset('js/fill.js') }}" defer></script>
</body>
</html>
