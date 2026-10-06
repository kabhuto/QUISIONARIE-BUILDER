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

    <div class="detail-sheet">
        @foreach ($survey->blocks as $blockIndex => $block)
            <section class="detail-block">
                <header class="detail-block-head"><span>BLOK {{ \App\Models\Survey::roman($blockIndex + 1) }}.</span> {{ $block['title'] }}</header>
                <dl>
                    @foreach ($block['questions'] as $question)
                        @continue($question['type'] === 'info')
                        @php
                            $answer = $response->answerTo($question['id']);
                        @endphp
                        <div @class(['detail-row', 'is-wide' => $question['type'] === 'table'])>
                            <dt><span class="mono">{{ $numbers[$question['id']] ?? '' }}</span> {{ $question['label'] }}</dt>
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
                            @elseif ($question['type'] === 'region')
                                <dd>
                                    @foreach (\App\Models\Survey::regionFields($question) as $field)
                                        <div><small class="muted">{{ $field['label'] }}:</small> {{ $answer[$field['key']] ?? '—' }}</div>
                                    @endforeach
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
            </section>
        @endforeach
    </div>
@endsection
