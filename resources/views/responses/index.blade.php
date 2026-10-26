@extends('layouts.admin')

@section('title', 'Respons · '.$survey->title)

@section('actions')
    <a class="button button-ghost" href="{{ route('surveys.edit', $survey) }}">Edit kuesioner</a>
    <a class="button button-ghost" href="{{ route('surveys.print', $survey) }}" target="_blank" rel="noopener">Cetak formulir</a>
    <a class="button button-ghost" href="{{ route('surveys.responses.listing', $survey) }}" target="_blank" rel="noopener">Tampilan listing</a>
    <a class="button button-ghost" href="{{ route('surveys.export-json', $survey) }}" title="Struktur, aturan validasi, dan semua respons">Ekspor JSON</a>
    <a @class(['button', 'button-primary', 'is-disabled' => $responses->isEmpty()]) href="{{ route('surveys.responses.export', $survey) }}" @if ($responses->isEmpty()) aria-disabled="true" @endif>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0-4-4m4 4 4-4M5 21h14"/></svg>
        Ekspor CSV
    </a>
@endsection

@section('content')
    <div class="page-intro">
        <div>
            <div class="eyebrow"><a class="back-link" href="{{ route('surveys.index') }}">← DASHBOARD</a> <span>/</span> RESPONS</div>
            <h1>{{ $survey->title }}</h1>
            <p>{{ $survey->activity }}@if ($survey->periodLabel()) · {{ $survey->periodLabel() }}@endif</p>
        </div>
        <div class="stat-row">
            <div class="stat-tile"><span>Total respons</span><strong>{{ $responses->count() }}</strong></div>
            <div class="stat-tile"><span>Respons terakhir</span><strong class="stat-small">{{ $responses->first()?->created_at->translatedFormat('d M Y, H:i') ?? '—' }}</strong></div>
            <div class="stat-tile"><span>Status</span><strong class="stat-small">{{ $survey->isPublished() ? 'Terbit' : 'Draf / ditutup' }}</strong></div>
        </div>
    </div>

    @if ($survey->isPublished())
        <div class="share-bar">
            <span class="share-label">TAUTAN RESPONDEN</span>
            <input class="share-url" type="text" value="{{ route('form.create', $survey) }}" readonly aria-label="Tautan responden" id="share-url">
            <button class="button button-ghost" type="button" data-copy="#share-url">Salin</button>
        </div>
    @endif

    @if ($responses->isEmpty())
        <div class="empty-state">
            <span class="empty-icon">✉</span>
            <h2>Belum ada respons</h2>
            <p>{{ $survey->isPublished() ? 'Bagikan tautan responden di atas. Jawaban akan muncul di sini.' : 'Publikasikan kuesioner agar responden bisa mulai mengisi.' }}</p>
        </div>
    @else
        <section class="panel-section">
            <div class="section-title"><span class="eyebrow">01</span><h2>Ringkasan jawaban</h2></div>
            <div class="summary-grid">
                @foreach ($summary as $item)
                    <article class="summary-card">
                        <header>
                            <span class="mono-chip">{{ $item['question']['number'] }}</span>
                            <h3>{{ $item['question']['label'] }}</h3>
                            <span class="muted">{{ $item['answered'] }} dari {{ $responses->count() }} menjawab</span>
                        </header>

                        @if ($item['kind'] === 'choices')
                            <ul class="bar-list">
                                @foreach ($item['choices'] as $choice)
                                    <li title="{{ $choice['label'] }}: {{ $choice['count'] }} jawaban ({{ $choice['percent'] }}%)">
                                        <span class="bar-label">{{ $choice['label'] }}</span>
                                        <span class="bar-track"><span class="bar-fill" style="width: {{ $choice['width'] }}%"></span></span>
                                        <span class="bar-value">{{ $choice['count'] }} <small>{{ $choice['percent'] }}%</small></span>
                                    </li>
                                @endforeach
                            </ul>
                        @elseif ($item['kind'] === 'numbers')
                            <dl class="number-stats">
                                <div><dt>Rata-rata</dt><dd>{{ $item['average'] }}</dd></div>
                                <div><dt>Terkecil</dt><dd>{{ $item['min'] }}</dd></div>
                                <div><dt>Terbesar</dt><dd>{{ $item['max'] }}</dd></div>
                            </dl>
                        @elseif ($item['samples'] !== [])
                            <ul class="sample-list">
                                @foreach ($item['samples'] as $sample)
                                    <li>{{ $sample }}</li>
                                @endforeach
                            </ul>
                        @else
                            <p class="muted">Belum ada jawaban.</p>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>

        <section class="panel-section">
            <div class="section-title"><span class="eyebrow">02</span><h2>Semua respons</h2></div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">No</th>
                            <th scope="col">Waktu kirim</th>
                            @foreach ($previewQuestions as $question)
                                <th scope="col">{{ $question['number'] }} {{ $question['text'] }}</th>
                            @endforeach
                            <th scope="col"><span class="sr-only">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($responses as $response)
                            <tr>
                                <td class="mono">{{ $responses->count() - $loop->index }}@if ($response->flags['confirmed_warnings'] ?? null)<span class="flag-dot" title="Ada peringatan konsistensi yang dikonfirmasi responden">⚠</span>@endif</td>
                                <td>{{ $response->created_at->translatedFormat('d M Y, H:i') }}</td>
                                @foreach ($previewQuestions as $question)
                                    <td>{{ \Illuminate\Support\Str::limit($response->displayAnswer($question), 40) ?: '—' }}</td>
                                @endforeach
                                <td class="align-end"><a class="table-link" href="{{ route('surveys.responses.show', [$survey, $response]) }}">Detail →</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection
