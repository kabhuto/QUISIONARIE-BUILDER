{{-- Light/dark theme: the visitor's choice (saved on this device) or else the system setting.
     Set before the page draws so it never flashes the other theme. With 'accent', the admin's own
     colour re-colours the page (see App\Support\Palette). --}}
@php($accentColor = ($accent ?? false) ? auth()->user()?->accentColor() : null)
<script>
    (() => {
        let theme = null;
        try { theme = localStorage.getItem('ruang-kuesioner:theme'); } catch {}
        if (theme !== 'light' && theme !== 'dark') theme = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        document.documentElement.dataset.theme = theme;
        @if ($accentColor) document.documentElement.dataset.accent = @json($accentColor); @endif
    })();
</script>
<link rel="stylesheet" href="{{ asset('css/theme.css') }}">
@if ($accentColor)
    <link rel="stylesheet" href="{{ route('accent.css', ['hex' => ltrim($accentColor, '#'), 'v' => \App\Support\Palette::cssVersion()]) }}" data-accent-css>
@endif
