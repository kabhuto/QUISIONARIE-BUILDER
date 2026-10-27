<button @class(['theme-toggle', $class ?? null]) type="button" data-theme-toggle aria-label="Ganti tema terang/gelap" title="Ganti tema terang/gelap">
    <svg class="theme-icon-dark" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z"/></svg>
    <svg class="theme-icon-light" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
    @if ($showLabel ?? false)<span class="theme-toggle-label">Tema</span>@endif
</button>
@once
    <script>
        document.addEventListener('click', event => {
            if (!event.target.closest('[data-theme-toggle]')) return;
            const theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
            document.documentElement.dataset.theme = theme;
            try { localStorage.setItem('ruang-kuesioner:theme', theme); } catch {}
        });
    </script>
@endonce
