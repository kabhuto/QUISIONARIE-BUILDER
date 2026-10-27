@extends('layouts.admin')

@section('title', 'Dashboard')

@section('actions')
    <a class="button button-ghost" href="{{ route('validation.create') }}">Validasi data</a>
    <form class="import-form" method="POST" action="{{ route('surveys.import') }}" enctype="multipart/form-data">
        @csrf
        <label class="button button-ghost" title="File ekspor JSON PRISMA, atau template FormGear/FASIH (boleh pilih template.json + validation.json + preset.json sekaligus)">
            Impor JSON / FormGear
            <input class="sr-only" type="file" name="files[]" accept=".json,application/json" multiple onchange="this.form.submit()">
        </label>
    </form>
    <a class="button button-primary" href="{{ route('surveys.create') }}">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
        Buat kuesioner
    </a>
@endsection

@section('content')
    @if ($errors->has('file'))
        <div class="toast toast-error" role="alert">{{ $errors->first('file') }}</div>
    @endif
    <div class="page-intro">
        <div>
            <div class="eyebrow"><span>00</span> DASHBOARD</div>
            <h1>PRISMA.</h1>
            <p>Platform riset, survei, dan manajemen data untuk semua kegiatan BPS: buat formulir, publikasikan, lalu pantau dan olah responsnya.</p>
        </div>
        <div class="stat-row">
            <div class="stat-tile"><span>Kuesioner</span><strong>{{ $surveys->count() }}</strong></div>
            <div class="stat-tile"><span>Terbit</span><strong>{{ $surveys->filter->isPublished()->count() }}</strong></div>
            <div class="stat-tile"><span>Total respons</span><strong>{{ $surveys->sum('responses_count') }}</strong></div>
        </div>
    </div>

    <section class="template-section" aria-labelledby="template-heading">
        <div class="section-title"><span class="eyebrow">MULAI</span><h2 id="template-heading">Buat dari template</h2></div>
        <div class="template-grid">
            @foreach ($templates as $key => $template)
                <a class="template-card" href="{{ route('surveys.create', ['template' => $key]) }}">
                    <strong>{{ $template['label'] }}</strong>
                    <span>{{ $template['description'] }}</span>
                </a>
            @endforeach
        </div>
    </section>

    <div class="section-title"><span class="eyebrow">DAFTAR</span><h2>Kuesioner saya</h2></div>
    @forelse ($surveys as $survey)
        @if ($loop->first)
            <div class="survey-list">
        @endif
        <article class="survey-card">
            <div class="survey-card-main">
                <div class="survey-card-meta">
                    <span @class(['status-badge', 'is-published' => $survey->isPublished()])>{{ $survey->isPublished() ? 'Terbit' : 'Draf' }}</span>
                    @if ($survey->form_code)
                        <span class="mono-chip">{{ $survey->form_code }}</span>
                    @endif
                    <span class="muted">Diubah {{ $survey->updated_at->translatedFormat('d M Y, H:i') }}</span>
                </div>
                <h2><a href="{{ route('surveys.edit', $survey) }}">{{ $survey->title }}</a></h2>
                <p>{{ $survey->activity }} · {{ count($survey->blocks ?? []) }} blok · {{ count($survey->questions()) }} pertanyaan</p>
            </div>
            <div class="survey-card-count">
                <strong>{{ $survey->responses_count }}</strong>
                <span>respons</span>
            </div>
            <div class="survey-card-actions">
                <a class="button button-ghost" href="{{ route('surveys.edit', $survey) }}">Edit</a>
                <a class="button button-ghost" href="{{ route('surveys.preview', $survey) }}">Pratinjau</a>
                <a class="button button-ghost" href="{{ route('surveys.responses.index', $survey) }}">Respons</a>
                <a class="button button-ghost" href="{{ route('surveys.export-json', $survey) }}" title="Struktur, aturan validasi, dan semua respons">Ekspor JSON</a>
                <a class="button button-ghost" href="{{ route('surveys.export-formgear', $survey) }}" title="Template, validasi, dan preset FormGear/FASIH (ZIP)">Ekspor FormGear</a>
                @if ($survey->isPublished())
                    <input class="sr-only" type="text" value="{{ route('form.create', $survey) }}" id="share-{{ $survey->id }}" readonly tabindex="-1" aria-hidden="true">
                    <button class="button button-ghost" type="button" data-copy="#share-{{ $survey->id }}">Salin tautan</button>
                    <form method="POST" action="{{ route('published-surveys.destroy', $survey) }}">
                        @csrf @method('DELETE')
                        <button class="button button-ghost" type="submit">Tutup</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('published-surveys.store', $survey) }}">
                        @csrf
                        <button class="button button-accent" type="submit">Publikasikan</button>
                    </form>
                @endif
                <form method="POST" action="{{ route('surveys.destroy', $survey) }}" onsubmit="return confirm('Hapus kuesioner ini beserta semua responsnya?')">
                    @csrf @method('DELETE')
                    <button class="button button-danger" type="submit" aria-label="Hapus {{ $survey->title }}">Hapus</button>
                </form>
            </div>
        </article>
        @if ($loop->last)
            </div>
        @endif
    @empty
        <div class="empty-state">
            <span class="empty-icon">▤</span>
            <h2>Belum ada kuesioner</h2>
            <p>Pilih salah satu template di atas atau mulai dari formulir kosong, lalu bagikan tautannya ke responden.</p>
            <a class="button button-primary" href="{{ route('surveys.create') }}">Buat kuesioner kosong</a>
        </div>
    @endforelse
@endsection
