<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') | Ruang Kuesioner</title>
    <link rel="stylesheet" href="{{ asset('css/builder.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    @stack('head')
</head>
<body>
    <header class="topbar">
        <a class="brand" href="{{ route('surveys.index') }}" aria-label="Dashboard Ruang Kuesioner">
            <span class="brand-mark">b<span>p</span>s</span>
            <span class="brand-name">RUANG<span>KUESIONER</span></span>
        </a>
        <nav class="topbar-context" aria-label="Navigasi utama">
            <a href="{{ route('surveys.index') }}" @class(['nav-link', 'is-active' => request()->routeIs('surveys.index')])>DASHBOARD</a>
            <a href="{{ route('references.index') }}" @class(['nav-link', 'is-active' => request()->routeIs('references.*')])>DATA REFERENSI</a>
            <a href="{{ route('api-access.index') }}" @class(['nav-link', 'is-active' => request()->routeIs('api-access.*')])>AKSES API</a>
            @auth
                <form class="nav-logout" method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="nav-link" type="submit" title="{{ auth()->user()->email }}">KELUAR</button>
                </form>
            @endauth
        </nav>
        <div class="topbar-actions">
            @yield('actions')
        </div>
    </header>

    @if (session('status'))
        <div class="toast" role="status">{{ session('status') }}</div>
    @endif

    <main class="app-shell">
        @yield('content')
    </main>

    <script>
        document.querySelectorAll('[data-copy]').forEach(button => button.addEventListener('click', async () => {
            const field = document.querySelector(button.dataset.copy);
            try {
                await navigator.clipboard.writeText(field.value);
            } catch {
                field.select();
                document.execCommand('copy');
            }
            const label = button.textContent;
            button.textContent = 'Tersalin';
            setTimeout(() => (button.textContent = label), 1500);
        }));
    </script>
</body>
</html>
