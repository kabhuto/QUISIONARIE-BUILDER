@extends('layouts.admin')

@section('title', 'Penugasan · '.$survey->title)

@section('actions')
    <a class="button button-ghost" href="{{ route('surveys.responses.index', $survey) }}">← Respons</a>
    <a @class(['button', 'button-primary', 'is-disabled' => $assignments->isEmpty()]) href="{{ route('assignments.export', $survey) }}">Unduh tautan (CSV)</a>
@endsection

@section('content')
    <div class="page-intro">
        <div>
            <div class="eyebrow"><a class="back-link" href="{{ route('surveys.responses.index', $survey) }}">← RESPONS</a> <span>/</span> PENUGASAN</div>
            <h1>Penugasan.</h1>
            <p>Setiap penugasan adalah tautan pribadi untuk satu responden atau satu unit sampel. Formulirnya terbuka dengan jawaban awal (predata) yang bisa dikunci, dan hanya bisa dikirim sekali.</p>
        </div>
        <div class="stat-row">
            <div class="stat-tile"><span>Penugasan</span><strong>{{ $assignments->count() }}</strong></div>
            <div class="stat-tile is-good"><span>Sudah dikirim</span><strong>{{ $assignments->filter->isSubmitted()->count() }}</strong></div>
            <div class="stat-tile"><span>Belum</span><strong>{{ $assignments->reject->isSubmitted()->count() }}</strong></div>
        </div>
    </div>

    <form class="validation-form" method="POST" action="{{ route('assignments.store', $survey) }}" enctype="multipart/form-data">
        @csrf
        <label class="validation-drop">
            <span class="validation-step">1</span>
            <strong>CSV penugasan</strong>
            <small>Kolom <code>label</code> dan nomor pertanyaan (101, 102, …) atau id pertanyaan. Satu baris = satu penugasan.</small>
            <input type="file" name="file" accept=".csv,text/csv" required>
        </label>
        <label class="validation-drop">
            <span class="validation-step">2</span>
            <strong>Kunci predata</strong>
            <small>Jawaban dari CSV tidak bisa diubah responden.</small>
            <span><input type="checkbox" name="lock" value="1"> Kunci</span>
        </label>
        <button class="button button-primary" type="submit">Buat penugasan</button>
    </form>
    @if ($errors->any())
        <div class="validation-alert" role="alert">{{ $errors->first() }}</div>
    @endif

    <details class="validation-help">
        <summary>Contoh CSV</summary>
@php
    $sample = array_slice(array_values(array_filter($questions, fn (array $question): bool => in_array($question['type'], ['short', 'number', 'code', 'single', 'dropdown'], true) && empty($question['in_roster']))), 0, 3);
@endphp
<pre>label;{{ implode(';', array_column($sample, 'number')) }}
Ruta 001;{{ implode(';', array_map(fn (array $question): string => in_array($question['type'], ['single', 'dropdown'], true) ? ($question['options'][0] ?? '') : ($question['type'] === 'short' ? 'Budi' : '1201'), $sample)) }}
Ruta 002;{{ implode(';', array_map(fn (array $question): string => in_array($question['type'], ['single', 'dropdown'], true) ? ($question['options'][1] ?? ($question['options'][0] ?? '')) : ($question['type'] === 'short' ? 'Siti' : '1208'), $sample)) }}</pre>
        <p>Penugasan juga bisa dibuat dari aplikasi lain lewat API (<code>POST /api/v1/surveys/{{ $survey->id }}/assignments</code>).</p>
    </details>

    <section class="panel-section">
        @if ($assignments->isEmpty())
            <div class="empty-state"><h2>Belum ada penugasan</h2><p>Unggah CSV di atas untuk membuat tautan pribadi berpredata.</p></div>
        @else
            <table class="data-table">
                <thead><tr><th>Label</th><th>Status</th><th>Predata</th><th>Tautan</th><th></th></tr></thead>
                <tbody>
                    @foreach ($assignments as $assignment)
                        <tr>
                            <td>{{ $assignment->label ?? '—' }}</td>
                            <td>
                                @if ($assignment->isSubmitted())
                                    <a href="{{ route('surveys.responses.show', [$survey, $assignment->response_id]) }}">Sudah dikirim</a>
                                @else
                                    {{ $assignment->isExpired() ? 'Kedaluwarsa' : 'Belum dikirim' }}
                                @endif
                            </td>
                            <td class="muted">{{ count($assignment->predata ?? []) }} jawaban{{ $assignment->locked ? ', '.count($assignment->locked).' terkunci' : '' }}</td>
                            <td>
                                <input class="share-url assignment-link" type="text" value="{{ $assignment->url() }}" readonly id="assignment-{{ $assignment->id }}" aria-label="Tautan penugasan">
                                <button class="link-button" type="button" data-copy="#assignment-{{ $assignment->id }}">Salin</button>
                            </td>
                            <td>
                                <form method="POST" action="{{ route('assignments.destroy', [$survey, $assignment]) }}" onsubmit="return confirm('Hapus penugasan ini?')">
                                    @csrf @method('DELETE')
                                    <button class="link-danger" type="submit">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endsection
