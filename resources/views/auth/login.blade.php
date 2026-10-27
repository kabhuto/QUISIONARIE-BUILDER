<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Masuk | PRISMA</title>
    @include('partials.theme')
    <link rel="stylesheet" href="{{ asset('css/builder.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/ui.css') }}">
</head>
<body class="login-page">
    @include('partials.theme-toggle', ['class' => 'theme-toggle-floating'])
    <main class="login-shell">
        <section class="login-hero" aria-hidden="true">
            <div class="brand">
                @include('partials.brand', ['full' => true])
            </div>
            <div>
                <h2>Riset, survei, dan pengelolaan data dalam satu platform.</h2>
                <ul class="login-points">
                    <li><span>1</span> Buat formulir dengan blok dan logika lompatan</li>
                    <li><span>2</span> Responden mengisi lewat HP tanpa login</li>
                    <li><span>3</span> Unduh hasil ke Excel / Google Spreadsheet</li>
                </ul>
            </div>
            <small>Badan Pusat Statistik</small>
        </section>

        <section class="login-card">
            <div class="brand login-brand-mobile">
                @include('partials.brand')
            </div>
            <h1>Masuk admin</h1>
            <p class="muted">Untuk membuat kuesioner dan melihat respons. Responden tidak perlu masuk; mereka cukup membuka tautan formulir.</p>

            @if (session('status'))
                <p class="login-status" role="status">{{ session('status') }}</p>
            @endif

            <form method="POST" action="{{ route('login.store') }}">
                @csrf
                <label class="field">
                    <span>Username atau email</span>
                    <input type="text" name="login" value="{{ old('login') }}" autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="mis. admin3526" required autofocus>
                </label>
                <label class="field">
                    <span>Kata sandi</span>
                    <span class="password-field">
                        <input type="password" name="password" autocomplete="current-password" required data-password>
                        <button type="button" class="password-reveal" data-password-reveal aria-label="Tampilkan kata sandi">Lihat</button>
                    </span>
                </label>
                <label class="check-field"><input type="checkbox" name="remember" value="1"> Ingat saya di perangkat ini</label>
                @if ($errors->any())
                    <p class="validation-alert" role="alert">{{ $errors->first() }}</p>
                @endif
                <button class="button button-primary button-block" type="submit">Masuk</button>
            </form>
        </section>
    </main>
    <script>
        document.querySelector('[data-password-reveal]').addEventListener('click', event => {
            const field = document.querySelector('[data-password]');
            const show = field.type === 'password';
            field.type = show ? 'text' : 'password';
            event.currentTarget.textContent = show ? 'Sembunyikan' : 'Lihat';
            event.currentTarget.setAttribute('aria-label', show ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');
        });
    </script>
</body>
</html>
