@extends('layouts.admin')

@section('title', 'Respons · '.$survey->title)

@section('actions')
    <a class="button button-ghost" href="{{ route('surveys.edit', $survey) }}">Edit kuesioner</a>
    <a class="button button-ghost" href="{{ route('surveys.print', $survey) }}" target="_blank" rel="noopener">Cetak formulir</a>
    <a class="button button-ghost" href="{{ route('surveys.responses.listing', $survey) }}" target="_blank" rel="noopener">Tampilan listing</a>
    <a class="button button-ghost" href="{{ route('assignments.index', $survey) }}" title="Tautan pribadi berpredata per responden">Penugasan</a>
    <a class="button button-ghost" href="{{ route('google-drive.show', $survey) }}" title="Salin berkas dan rekap respons ke folder Google Drive">Google Drive</a>
    <a class="button button-ghost" href="{{ route('surveys.export-json', $survey) }}" title="Struktur, aturan validasi, dan semua respons">Ekspor JSON</a>
    <a @class(['button', 'button-ghost', 'is-disabled' => $responses->isEmpty()]) href="{{ route('surveys.responses.export', $survey) }}" @if ($responses->isEmpty()) aria-disabled="true" @endif>Ekspor CSV</a>
    <a @class(['button', 'button-primary', 'is-disabled' => $responses->isEmpty()]) href="{{ route('surveys.responses.export-xlsx', $survey) }}" title="Buka di Excel atau Google Spreadsheet" @if ($responses->isEmpty()) aria-disabled="true" @endif>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0-4-4m4 4 4-4M5 21h14"/></svg>
        Ekspor Spreadsheet
    </a>
@endsection

@section('content')
    @if ($responses->isNotEmpty())
        @pushOnce('head')
            <script src="https://cdnjs.cloudflare.com/ajax/libs/echarts/5.6.0/echarts.min.js" integrity="sha512-XSmbX3mhrD2ix5fXPTRQb2FwK22sRMVQTpBP2ac8hX7Dh/605hA2QDegVWiAvZPiXIxOV0CbkmUjGionDpbCmw==" crossorigin="anonymous" referrerpolicy="no-referrer" defer></script>
            <script src="{{ asset('js/response-charts.js') }}" defer></script>
        @endPushOnce
    @endif
    <div class="page-intro">
        <div>
            <div class="eyebrow"><a class="back-link" href="{{ route('surveys.index') }}">← DASHBOARD</a> <span>/</span> RESPONS</div>
            <h1>{{ $survey->title }}</h1>
            <p>{{ $survey->activity }}@if ($survey->periodLabel()) · {{ $survey->periodLabel() }}@endif</p>
        </div>
        <div class="stat-row">
            <div class="stat-tile"><span>Total respons</span><strong>{{ $responses->count() }}</strong></div>
            <div class="stat-tile"><span>Respons terakhir</span><strong class="stat-small">{{ $responses->first()?->created_at->translatedFormat('d M Y, H:i') ?? '—' }}</strong></div>
            @if ($overview['average_duration'] !== '')
                <div class="stat-tile"><span>Rata-rata lama mengisi</span><strong class="stat-small">{{ $overview['average_duration'] }}</strong></div>
            @endif
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
            <div class="section-title"><span class="eyebrow">01</span><h2>Gambaran respons</h2></div>
            <div class="summary-grid overview-grid">
                <article class="summary-card summary-card-wide">
                    <header><h3>Respons masuk per {{ $overview['timeline']['period'] }}</h3><span class="muted">{{ $responses->count() }} respons</span></header>
                    @include('responses.partials.chart', ['chart' => $overview['timeline'], 'label' => 'respons masuk per '.$overview['timeline']['period']])
                </article>
                <article class="summary-card">
                    <header><h3>Jam pengiriman</h3><span class="muted">Waktu respons dikirim</span></header>
                    @include('responses.partials.chart', ['chart' => $overview['hours'], 'label' => 'jam pengiriman'])
                </article>
                <article class="summary-card">
                    <header><h3>Perangkat responden</h3><span class="muted">Dari user agent browser</span></header>
                    @include('responses.partials.chart', ['chart' => $overview['devices'], 'label' => 'perangkat responden'])
                </article>
            </div>
        </section>

        <section class="panel-section">
            <div class="section-title"><span class="eyebrow">02</span><h2>Ringkasan jawaban</h2></div>
            <div class="summary-grid">
                @foreach ($summary as $item)
                    <article @class(['summary-card', 'summary-card-map' => $item['kind'] === 'points', 'summary-card-wide' => in_array($item['chart']['type'] ?? null, ['line', 'stacked'], true)])>
                        <header>
                            <span class="mono-chip">{{ $item['question']['number'] }}</span>
                            <h3>{{ $item['question']['label'] }}</h3>
                            <span class="muted">{{ $item['answered'] }} dari {{ $responses->count() }} menjawab</span>
                        </header>

                        @if ($item['chart'])
                            @include('responses.partials.chart', ['chart' => $item['chart'], 'label' => $item['question']['label']])
                        @endif
                        @if ($item['kind'] === 'numbers')
                            <dl class="number-stats">
                                <div><dt>Rata-rata</dt><dd>{{ $item['average'] }}</dd></div>
                                <div><dt>Median</dt><dd>{{ $item['median'] }}</dd></div>
                                <div><dt>Terkecil</dt><dd>{{ $item['min'] }}</dd></div>
                                <div><dt>Terbesar</dt><dd>{{ $item['max'] }}</dd></div>
                            </dl>
                        @elseif ($item['kind'] === 'files')
                            @if ($item['files']['count'])
                                <dl class="number-stats">
                                    <div><dt>Berkas</dt><dd>{{ $item['files']['count'] }}</dd></div>
                                    <div><dt>Rata-rata</dt><dd>{{ $item['files']['average'] }}</dd></div>
                                    <div><dt>Terbesar</dt><dd>{{ $item['files']['largest'] }}</dd></div>
                                    <div><dt>Total</dt><dd>{{ $item['files']['total'] }}</dd></div>
                                </dl>
                            @else
                                <p class="muted">Berkas tidak ditemukan di penyimpanan.</p>
                            @endif
                        @elseif (in_array($item['kind'], ['choices', 'chart'], true))
                            @unless ($item['chart'])
                                <p class="muted">Belum ada jawaban.</p>
                            @endunless
                        @elseif ($item['kind'] === 'points')
                            @if ($item['points'] === [])
                                <p class="muted">Belum ada titik lokasi.</p>
                            @else
                                @pushOnce('head')
                                    @include('partials.leaflet')
                                @endPushOnce
                                <div class="points-layout">
                                    <div class="points-map" role="img" aria-label="Peta lokasi {{ $item['question']['label'] }}" data-map-points="{{ json_encode($item['points']) }}"></div>
                                    <ol class="points-list" aria-label="Daftar titik">
                                        @foreach ($item['points'] as $point)
                                            <li>
                                                <a href="{{ $point['url'] }}" class="points-item" data-point-id="{{ $point['id'] }}">
                                                    <span class="map-pin-number">{{ $point['number'] }}</span>
                                                    <span class="points-copy">
                                                        <strong>{{ $point['label'] }}</strong>
                                                        <small>{{ $point['time'] }}@if ($point['accuracy'] !== null) · ±{{ $point['accuracy'] }} m @endif</small>
                                                    </span>
                                                </a>
                                            </li>
                                        @endforeach
                                    </ol>
                                </div>
                                <p class="points-hint muted">Arahkan kursor ke titik, nama, atau baris tabel untuk menyorot lokasinya; klik untuk membuka detail respons. Lingkaran = jangkauan akurasi GPS.</p>
                            @endif
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
            <div class="section-title"><span class="eyebrow">03</span><h2>Semua respons</h2></div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">No</th>
                            <th scope="col">Waktu kirim</th>
                            <th scope="col">IP · perangkat</th>
                            @foreach ($previewQuestions as $question)
                                <th scope="col">{{ $question['number'] }} {{ $question['text'] }}</th>
                            @endforeach
                            <th scope="col"><span class="sr-only">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($responses as $response)
                            <tr data-point-id="{{ $response->id }}" data-href="{{ route('surveys.responses.show', [$survey, $response]) }}">
                                <td class="mono">{{ $responses->count() - $loop->index }}@if ($response->flags['confirmed_warnings'] ?? null)<span class="flag-dot" title="Ada peringatan konsistensi yang dikonfirmasi responden">⚠</span>@endif</td>
                                <td class="nowrap" title="{{ $response->durationLabel() ? 'Lama mengisi '.$response->durationLabel() : '' }}">{{ $response->created_at->translatedFormat('d M Y, H:i:s') }}</td>
                                <td class="cell-device" @if ($response->user_agent) title="{{ $response->user_agent }}" @endif>
                                    <span class="mono">{{ $response->ip_address ?? '—' }}</span>
                                    @if ($response->deviceLabel() !== '')<small>{{ $response->deviceLabel() }}</small>@endif
                                </td>
                                @foreach ($previewQuestions as $question)
                                    @php($fullAnswer = $response->displayAnswer($question))
                                    <td @if (mb_strlen($fullAnswer) > 40) title="{{ $fullAnswer }}" @endif>
                                        @if ($question['type'] === 'location' && $fullAnswer !== '')<span class="cell-pin" aria-hidden="true">📍</span>@endif{{ \Illuminate\Support\Str::limit($fullAnswer, 40) ?: '—' }}
                                    </td>
                                @endforeach
                                <td class="align-end"><a class="table-link" href="{{ route('surveys.responses.show', [$survey, $response]) }}">Detail →</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        <script>
            // A row opens its response; links and buttons inside it keep their own action.
            document.querySelectorAll('tr[data-href]').forEach(row => row.addEventListener('click', event => {
                if (event.target.closest('a, button, input') || window.getSelection().toString()) return;
                window.location.href = row.dataset.href;
            }));
        </script>
    @endif
@endsection
