<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Masuk | Ruang Kuesioner</title>
    <link rel="stylesheet" href="{{ asset('css/builder.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="login-page">
    <main class="login-card">
        <div class="brand">
            <span class="brand-mark">b<span>p</span>s</span>
            <span class="brand-name">RUANG<span>KUESIONER</span></span>
        </div>
        <h1>Masuk admin</h1>
        <p class="muted">Untuk membuat kuesioner dan melihat respons. Responden tidak perlu masuk; mereka cukup membuka tautan formulir.</p>

        @if (session('status'))
            <p class="login-status" role="status">{{ session('status') }}</p>
        @endif

        <form method="POST" action="{{ route('login.store') }}">
            @csrf
            <label class="setting-field"><span>Username atau email</span>
                <input class="setting-select" type="text" name="login" value="{{ old('login') }}" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
            </label>
            <label class="setting-field"><span>Kata sandi</span>
                <input class="setting-select" type="password" name="password" autocomplete="current-password" required>
            </label>
            <label class="api-check"><input type="checkbox" name="remember" value="1"> Ingat saya di perangkat ini</label>
            @if ($errors->any())
                <p class="validation-alert" role="alert">{{ $errors->first() }}</p>
            @endif
            <button class="button button-primary" type="submit">Masuk</button>
        </form>
    </main>
</body>
</html>
