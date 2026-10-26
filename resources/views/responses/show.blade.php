@extends('layouts.admin')

@section('title', 'Respons #'.$position.' · '.$survey->title)

@section('actions')
    <a class="button button-ghost" href="{{ route('surveys.responses.index', $survey) }}">← Semua respons</a>
    <a class="button button-primary" href="{{ route('surveys.responses.print', [$survey, $response]) }}" target="_blank" rel="noopener">Cetak respons</a>
@endsection

@section('content')
    <div class="page-intro">
        <div>
            <div class="eyebrow"><a class="back-link" href="{{ route('surveys.responses.index', $survey) }}">← RESPONS</a> <span>/</span> DETAIL</div>
            <h1>Respons #{{ $position }}</h1>
            <p>{{ $survey->title }} · dikirim {{ $response->created_at->translatedFormat('d M Y, H:i') }}</p>
        </div>
    </div>

    @php
        $confirmed = collect($response->flags['confirmed_warnings'] ?? [])->groupBy('question_id');
    @endphp

    @if ($confirmed->isNotEmpty())
        <div class="flag-summary" role="note">
            <strong>⚠ {{ $confirmed->flatten(1)->count() }} peringatan konsistensi dikonfirmasi responden</strong>
            <p>Responden menyatakan jawaban berikut sudah benar meski tampak tidak konsisten. Periksa kembali sebelum data diolah.</p>
        </div>
    @endif

    @php
        $questionIndex = collect($survey->questions())->keyBy('id')->all();
    @endphp

    <div class="detail-sheet">
        @foreach ($survey->blocks as $blockIndex => $block)
            <section class="detail-block">
                <header class="detail-block-head"><span>BLOK {{ \App\Models\Survey::roman($blockIndex + 1) }}.</span> {{ $block['title'] }}</header>
                @if (\App\Support\SurveyLogic::repeatSource($block) !== null)
                    @php
                        $rosterQuestions = array_values(array_filter($block['questions'], fn (array $question): bool => $question['type'] !== 'info'));
                        $rosterRows = \App\Support\SurveyLogic::rosterRows($block, $response->answers ?? []);
                    @endphp
                    @if ($rosterRows === [])
                        <p class="detail-roster-empty">Tidak ada rincian (pertanyaan {{ $numbers[$block['repeat']['source']] ?? '' }} tidak diisi).</p>
                    @else
                        <div class="detail-roster-wrap">
                            <table class="data-table detail-table">
                                <thead>
                                    <tr>
                                        <th>Rincian</th>
                                        @foreach ($rosterQuestions as $question)
                                            <th><span class="mono">{{ $numbers[$question['id']] ?? '' }}</span> {{ str_replace(\App\Models\Survey::ROW_TOKEN, '…', $questionIndex[$question['id']]['text'] ?? $question['label']) }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rosterRows as $row => $rowLabel)
                                        <tr>
                                            <th>{{ $row + 1 }}. {{ $rowLabel }}</th>
                                            @foreach ($rosterQuestions as $question)
                                                @php
                                                    $values = $response->answerTo($question['id']);
                                                    $others = (array) ($response->answers[$question['id'].\App\Models\Survey::OTHER_SUFFIX] ?? []);
                                                @endphp
                                                <td>{{ \App\Models\SurveyResponse::displayValue($question, is_array($values) ? ($values[$row] ?? null) : null, (string) ($others[$row] ?? '')) }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    @foreach ($rosterQuestions as $question)
                        @foreach ($confirmed->get($question['id'], []) as $warning)
                            <p class="detail-flag">⚠ Peringatan dikonfirmasi (rincian {{ ($warning['row'] ?? 0) + 1 }}): {{ $warning['message'] }}</p>
                        @endforeach
                    @endforeach
                @else
                <dl>
                    @foreach ($block['questions'] as $question)
                        @continue($question['type'] === 'info')
                        @php
                            $answer = $response->answerTo($question['id']);
                        @endphp
                        <div @class(['detail-row', 'is-wide' => $question['type'] === 'table'])>
                            <dt><span class="mono">{{ $numbers[$question['id']] ?? '' }}</span> {{ \App\Support\Piping::resolved($question['label'], fn (string $id): string => isset($questionIndex[$id]) ? $response->displayAnswer($questionIndex[$id]) : '') }}</dt>
                            @if ($answer === null)
                                <dd class="is-empty">Tidak diisi / tidak tampil</dd>
                            @elseif ($question['type'] === 'table')
                                <dd>
                                    <table class="data-table detail-table">
                                        <thead>
                                            <tr>
                                                <th>{{ $question['table']['mode'] === 'fixed' ? 'Uraian' : 'No' }}</th>
                                                @foreach ($question['table']['columns'] as $column)
                                                    <th>{{ $column['label'] }}</th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($answer as $rowIndex => $row)
                                                <tr>
                                                    <td>{{ $question['table']['mode'] === 'fixed' ? ($question['table']['rows'][$rowIndex] ?? '') : $rowIndex + 1 }}</td>
                                                    @foreach ($question['table']['columns'] as $column)
                                                        <td>{{ $row[$column['id']] ?? '' }}</td>
                                                    @endforeach
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </dd>
                            @elseif ($question['type'] === 'signature')
                                <dd>
                                    @if (\App\Models\Survey::isSignatureImage($answer))
                                        <img class="detail-signature" src="{{ $answer }}" alt="Tanda tangan responden">
                                    @else
                                        {{ $response->displayAnswer($question) }}
                                    @endif
                                </dd>
                            @elseif ($question['type'] === 'matrix')
                                <dd>
                                    @foreach ($question['matrix']['rows'] as $rowIndex => $row)
                                        <div><small class="muted">{{ $row }}:</small> {{ ($answer[$rowIndex] ?? '') ?: '—' }}</div>
                                    @endforeach
                                </dd>
                            @elseif ($question['type'] === 'region')
                                <dd>
                                    @foreach (\App\Models\Survey::regionFields($question) as $field)
                                        <div><small class="muted">{{ $field['label'] }}:</small> {{ $answer[$field['key']] ?? '—' }}</div>
                                    @endforeach
                                </dd>
                            @elseif ($question['type'] === 'file' && \App\Models\Survey::isUploadPath($answer))
                                <dd>
                                    @if (in_array(strtolower(pathinfo($answer, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true))
                                        <a href="{{ asset('storage/'.$answer) }}" target="_blank" rel="noopener"><img class="detail-photo" src="{{ asset('storage/'.$answer) }}" alt="{{ $question['text'] ?? $question['label'] }}"></a>
                                    @else
                                        <a href="{{ asset('storage/'.$answer) }}" target="_blank" rel="noopener">Buka berkas ({{ strtoupper(pathinfo($answer, PATHINFO_EXTENSION)) }})</a>
                                    @endif
                                    @if (\App\Support\UploadCompressor::isSignedPath($answer))
                                        <div><small class="muted">Bertanda tangan elektronik — disimpan asli, tidak dikompres.</small></div>
                                    @endif
                                </dd>
                            @elseif ($question['type'] === 'location')
                                <dd>
                                    {{ $answer }}
                                    @if (is_numeric($response->answers[$question['id'].\App\Models\Survey::ACCURACY_SUFFIX] ?? null))
                                        <small class="muted">(akurasi ±{{ $response->answers[$question['id'].\App\Models\Survey::ACCURACY_SUFFIX] }} m)</small>
                                    @endif
                                    · <a href="https://www.openstreetmap.org/?mlat={{ urlencode(trim(explode(',', $answer)[0])) }}&mlon={{ urlencode(trim(explode(',', $answer)[1] ?? '')) }}#map=17/{{ urlencode(trim(explode(',', $answer)[0])) }}/{{ urlencode(trim(explode(',', $answer)[1] ?? '')) }}" target="_blank" rel="noopener">Lihat di peta</a>
                                    @if (preg_match('/^(-?\d{1,2}(?:\.\d+)?),\s*(-?\d{1,3}(?:\.\d+)?)$/', (string) $answer, $point))
                                        <div class="detail-map" role="img" aria-label="Peta {{ $question['text'] ?? $question['label'] }}" data-map-point="{{ $point[1] }},{{ $point[2] }}"></div>
                                        @pushOnce('head')
                                            @include('partials.leaflet')
                                        @endPushOnce
                                    @endif
                                </dd>
                            @else
                                <dd>{{ $response->displayAnswer($question) }}</dd>
                            @endif
                            @foreach ($confirmed->get($question['id'], []) as $warning)
                                <p class="detail-flag">⚠ Peringatan dikonfirmasi: {{ $warning['message'] }}</p>
                            @endforeach
                        </div>
                    @endforeach
                </dl>
                @endif
            </section>
        @endforeach
    </div>
@endsection
