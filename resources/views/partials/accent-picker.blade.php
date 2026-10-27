{{-- The admin's own colour for the admin pages: ready-made colours or any other; saved to the account. --}}
@auth
    @php($current = auth()->user()->accentColor() ?? \App\Support\Palette::DEFAULT_ACCENT)
    <div class="accent-picker" data-accent-picker data-save-url="{{ route('accent.update') }}" data-css-url="{{ url('/warna') }}" data-css-version="{{ \App\Support\Palette::cssVersion() }}" data-token="{{ csrf_token() }}" data-default="{{ \App\Support\Palette::DEFAULT_ACCENT }}">
        <button class="theme-toggle accent-toggle" type="button" aria-expanded="false" aria-haspopup="true" title="Ganti warna tampilan" data-accent-open>
            <span class="accent-dot" style="--swatch: {{ $current }}" data-accent-dot></span>
            @if ($showLabel ?? false)<span class="theme-toggle-label">Warna</span>@endif
        </button>
        <div class="accent-pop" role="dialog" aria-label="Warna tampilan" hidden data-accent-pop>
            <strong>Warna tampilan</strong>
            <p>Berlaku untuk akun Anda di semua halaman admin.</p>
            <div class="accent-swatches">
                @foreach (\App\Support\Palette::PRESETS as $color => $name)
                    <button type="button" class="accent-swatch" style="--swatch: {{ $color }}" data-accent-choice="{{ $color }}" aria-pressed="{{ $current === $color ? 'true' : 'false' }}" title="{{ $name }}">
                        <span aria-hidden="true"></span><small>{{ $name }}</small>
                    </button>
                @endforeach
            </div>
            <label class="accent-custom">
                <input type="color" value="{{ $current }}" data-accent-custom>
                <span>Warna lain…</span>
                <code data-accent-code>{{ $current }}</code>
            </label>
            <p class="accent-status" aria-live="polite" data-accent-status></p>
        </div>
    </div>
    @once
        <script>
            (() => {
                const picker = document.querySelector('[data-accent-picker]');
                if (!picker) return;
                const pop = picker.querySelector('[data-accent-pop]');
                const toggle = picker.querySelector('[data-accent-open]');
                const status = picker.querySelector('[data-accent-status]');
                const root = document.documentElement;
                let saveTimer = null;

                const open = show => {
                    pop.hidden = !show;
                    toggle.setAttribute('aria-expanded', String(show));
                };
                toggle.addEventListener('click', () => open(pop.hidden));
                document.addEventListener('click', event => {
                    if (!pop.hidden && !picker.contains(event.target)) open(false);
                });
                document.addEventListener('keydown', event => {
                    if (event.key === 'Escape' && !pop.hidden) {
                        open(false);
                        toggle.focus();
                    }
                });

                // Shows the colour right away; the style sheet for it comes from the server.
                function apply(color) {
                    const isDefault = color === picker.dataset.default;
                    let link = document.querySelector('[data-accent-css]');
                    if (isDefault) {
                        link?.remove();
                        delete root.dataset.accent;
                    } else {
                        if (!link) {
                            link = document.createElement('link');
                            link.rel = 'stylesheet';
                            link.dataset.accentCss = '';
                            document.head.append(link);
                        }
                        link.href = `${picker.dataset.cssUrl}/${color.slice(1)}.css?v=${picker.dataset.cssVersion}`;
                        root.dataset.accent = color;
                    }
                    picker.querySelector('[data-accent-dot]').style.setProperty('--swatch', color);
                    picker.querySelector('[data-accent-code]').textContent = color;
                    picker.querySelector('[data-accent-custom]').value = color;
                    picker.querySelectorAll('[data-accent-choice]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.accentChoice === color)));
                    document.dispatchEvent(new CustomEvent('accent-change', { detail: { color } }));
                }

                function save(color) {
                    clearTimeout(saveTimer);
                    saveTimer = setTimeout(async () => {
                        status.textContent = 'Menyimpan…';
                        try {
                            const response = await fetch(picker.dataset.saveUrl, {
                                method: 'PUT',
                                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': picker.dataset.token },
                                body: JSON.stringify({ color }),
                            });
                            status.textContent = response.ok ? 'Tersimpan.' : 'Gagal menyimpan warna.';
                        } catch {
                            status.textContent = 'Gagal menyimpan warna. Periksa koneksi.';
                        }
                    }, 350);
                }

                picker.addEventListener('click', event => {
                    const choice = event.target.closest('[data-accent-choice]');
                    if (!choice) return;
                    apply(choice.dataset.accentChoice);
                    save(choice.dataset.accentChoice);
                });
                picker.querySelector('[data-accent-custom]').addEventListener('input', event => {
                    apply(event.target.value.toLowerCase());
                    save(event.target.value.toLowerCase());
                });
            })();
        </script>
    @endonce
@endauth
