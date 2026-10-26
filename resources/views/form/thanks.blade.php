<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Terima kasih | {{ $survey->title }}</title>
    <link rel="stylesheet" href="{{ asset('css/fill.css') }}">
</head>
<body class="fill-page" style="--accent: {{ $survey->themeColor() }}">
    <main class="fill-thanks">
        <section class="cawi-card">
            @include('partials.kop', ['class' => 'kop-cawi'])
            @if (($assignmentClosed ?? null) !== null)
                <span class="thanks-mark" aria-hidden="true">{{ $assignmentClosed === 'submitted' ? '✓' : '⏱' }}</span>
                <h1>{{ $assignmentClosed === 'submitted' ? 'Penugasan ini sudah dikirim. Terima kasih.' : 'Tautan penugasan ini sudah kedaluwarsa.' }}</h1>
                <p>{{ $survey->title }}</p>
            @else
            <span class="thanks-mark" aria-hidden="true">✓</span>
            <h1>{{ $survey->formSettings()['thank_you_message'] ?: 'Terima kasih, jawaban Anda sudah kami terima.' }}</h1>
            <p>{{ $survey->title }}</p>
            @endif
            @if ($survey->isPublished() && ($assignmentClosed ?? null) === null)
                <a class="fill-link" href="{{ route('form.create', $survey) }}">Kirim respons lain</a>
            @endif
        </section>
        <footer class="fill-footer">Badan Pusat Statistik</footer>
    </main>
    <script>
        // The submission went through, so the saved draft is no longer needed.
        try {
            localStorage.removeItem(@json('quisioner:'.$survey->slug));
        } catch {}
    </script>
</body>
</html>
