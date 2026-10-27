{{-- Light/dark theme: the visitor's choice (saved on this device) or else the system setting.
     Set before the page draws so it never flashes the other theme. --}}
<script>
    (() => {
        let theme = null;
        try { theme = localStorage.getItem('ruang-kuesioner:theme'); } catch {}
        if (theme !== 'light' && theme !== 'dark') theme = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        document.documentElement.dataset.theme = theme;
    })();
</script>
<link rel="stylesheet" href="{{ asset('css/theme.css') }}">
