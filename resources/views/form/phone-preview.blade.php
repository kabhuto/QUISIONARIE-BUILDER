<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Pratinjau HP · {{ $survey->title }} | PRISMA</title>
    @include('partials.theme', ['accent' => true])
    <link rel="stylesheet" href="{{ asset('css/builder.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/ui.css') }}">
</head>
<body class="admin-page phone-preview-page">
    <header class="phone-preview-bar">
        <a class="button button-ghost button-small" href="{{ route('surveys.edit', $survey) }}">← Kembali ke builder</a>
        <strong>{{ $survey->title }}</strong>
        <div class="segmented phone-sizes" role="group" aria-label="Ukuran layar">
            @foreach (['360' => 'HP kecil', '390' => 'HP', '768' => 'Tablet'] as $width => $label)
                <label><input class="sr-only" type="radio" name="size" value="{{ $width }}" @checked($width === '390')><span>{{ $label }}</span></label>
            @endforeach
        </div>
        <a class="button button-ghost button-small" href="{{ route('surveys.preview', $survey) }}">Layar penuh</a>
    </header>
    <main class="phone-stage">
        <div class="phone-frame" data-phone-frame style="--phone-width: 390px">
            <iframe src="{{ route('surveys.preview', $survey) }}" title="Pratinjau formulir di HP"></iframe>
        </div>
        <p class="muted phone-note">Coba isi seperti petugas: alur lompatan, pertanyaan berulang, pesan validasi, nilai awal, dan ringkasan sebelum kirim. Jawaban pratinjau tidak tersimpan.</p>
    </main>
    <script>
        document.querySelectorAll('input[name="size"]').forEach(input => input.addEventListener('change', () => {
            document.querySelector('[data-phone-frame]').style.setProperty('--phone-width', `${input.value}px`);
        }));
    </script>
</body>
</html>
