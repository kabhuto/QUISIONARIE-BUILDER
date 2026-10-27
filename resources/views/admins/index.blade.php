@extends('layouts.admin')

@section('title', 'Admin')

@section('content')
    <div class="page-intro">
        <div>
            <div class="eyebrow"><a class="back-link" href="{{ route('surveys.index') }}">← DASHBOARD</a> <span>/</span> ADMIN</div>
            <h1>Kelola admin</h1>
            <p>Setiap admin bisa membuat dan mengubah kuesioner, melihat respons, serta menambah admin lain.</p>
        </div>
        <div class="stat-row">
            <div class="stat-tile"><span>Jumlah admin</span><strong>{{ $admins->count() }}</strong></div>
        </div>
    </div>

    @error('admin')
        <div class="validation-alert" role="alert">{{ $message }}</div>
    @enderror

    <div class="admin-grid">
        <section class="panel-section admin-list-section">
            <div class="section-title"><span class="eyebrow">01</span><h2>Daftar admin</h2></div>
            <ul class="admin-list">
                @foreach ($admins as $admin)
                    @php($isMe = auth()->user()?->is($admin))
                    @php($bag = $errors->getBag('edit'.$admin->id))
                    <li class="admin-card">
                        <div class="admin-card-main">
                            <span class="user-avatar user-avatar-large" aria-hidden="true">{{ mb_strtoupper(mb_substr($admin->name, 0, 1)) }}</span>
                            <div class="admin-card-copy">
                                <strong>{{ $admin->name }} @if ($isMe)<span class="status-badge is-published">Anda</span>@endif</strong>
                                <span class="admin-login"><span class="muted">Masuk dengan</span> <code>{{ $admin->loginName() }}</code></span>
                                @if ($admin->username && $admin->hasRealEmail())
                                    <span class="muted">{{ $admin->email }}</span>
                                @endif
                                <span class="muted">Ditambahkan {{ $admin->created_at?->translatedFormat('d M Y') ?? '—' }}</span>
                            </div>
                        </div>
                        <details class="admin-edit" @if ($bag->any()) open @endif>
                            <summary class="button button-ghost button-small">Ubah nama / kata sandi</summary>
                            <form method="POST" action="{{ route('admins.update', $admin) }}" class="admin-form">
                                @csrf
                                @method('PUT')
                                <label class="field"><span>Nama</span>
                                    <input type="text" name="name" value="{{ $bag->any() ? old('name') : $admin->name }}" maxlength="100" required>
                                </label>
                                <label class="field"><span>Kata sandi baru <small>(kosongkan bila tidak diganti)</small></span>
                                    <input type="password" name="password" autocomplete="new-password" minlength="8">
                                </label>
                                <label class="field"><span>Ulangi kata sandi baru</span>
                                    <input type="password" name="password_confirmation" autocomplete="new-password">
                                </label>
                                @if ($bag->any())
                                    <p class="validation-alert" role="alert">{{ $bag->first() }}</p>
                                @endif
                                <button class="button button-primary" type="submit">Simpan perubahan</button>
                            </form>
                        </details>
                        @unless ($isMe || $admins->count() <= 1)
                            <form method="POST" action="{{ route('admins.destroy', $admin) }}" class="admin-delete" onsubmit="return confirm(@js('Hapus admin '.$admin->loginName().'? Ia tidak bisa masuk lagi.'))">
                                @csrf
                                @method('DELETE')
                                <button class="link-danger" type="submit">Hapus</button>
                            </form>
                        @endunless
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="panel-section">
            <div class="section-title"><span class="eyebrow">02</span><h2>Tambah admin</h2></div>
            <form method="POST" action="{{ route('admins.store') }}" class="admin-form admin-form-card">
                @csrf
                <label class="field"><span>Nama</span>
                    <input type="text" name="name" value="{{ $errors->create->any() ? old('name') : '' }}" maxlength="100" placeholder="mis. Humas BPS" required>
                </label>
                <label class="field"><span>Username</span>
                    <input type="text" name="username" value="{{ $errors->create->any() ? old('username') : '' }}" autocapitalize="none" spellcheck="false" placeholder="mis. humas3526">
                </label>
                <label class="field"><span>Email <small>(opsional bila username diisi)</small></span>
                    <input type="email" name="email" value="{{ $errors->create->any() ? old('email') : '' }}" placeholder="mis. humas@bps.go.id">
                </label>
                <label class="field"><span>Kata sandi <small>(minimal 8 karakter)</small></span>
                    <input type="password" name="password" autocomplete="new-password" minlength="8" required>
                </label>
                <label class="field"><span>Ulangi kata sandi</span>
                    <input type="password" name="password_confirmation" autocomplete="new-password" required>
                </label>
                @if ($errors->create->any())
                    <p class="validation-alert" role="alert">{{ $errors->create->first() }}</p>
                @endif
                <button class="button button-primary button-block" type="submit">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                    Tambah admin
                </button>
                <p class="setting-note">Admin baru masuk di halaman <b>/masuk</b> dengan username atau email di atas.</p>
            </form>
        </section>
    </div>
@endsection
