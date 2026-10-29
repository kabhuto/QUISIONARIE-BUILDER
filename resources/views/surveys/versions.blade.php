@extends('layouts.admin')

@section('title', 'Versi · '.$survey->title)

@section('content')
    <div class="page-intro">
        <div>
            <div class="eyebrow"><a class="back-link" href="{{ route('surveys.edit', $survey) }}">← KUESIONER</a> <span>/</span> VERSI INSTRUMEN</div>
            <h1>Riwayat versi.</h1>
            <p>Versi baru tercatat setiap kali kuesioner dipublikasikan, atau diubah saat sedang dipublikasikan. Setiap respons menyimpan nomor versinya, jadi jawaban lama tetap terhubung dengan pertanyaan yang dulu ditanyakan.</p>
        </div>
    </div>

    @if ($counts->has(''))
        <p class="muted">{{ $counts[''] }} respons dikirim sebelum pencatatan versi dimulai.</p>
    @endif

    <ol class="version-list">
        @forelse ($versions as $version)
            <li class="version-card" id="versi-{{ $version->number }}">
                <header>
                    <span class="version-number">Versi {{ $version->number }}</span>
                    @if ($loop->first)<span class="version-tag">Terbaru</span>@endif
                    <span class="muted">{{ $version->created_at->translatedFormat('d M Y, H:i') }} · {{ (int) ($counts[$version->number] ?? 0) }} respons · {{ collect($version->blocks)->sum(fn ($block) => count($block['questions'] ?? [])) }} pertanyaan</span>
                </header>
                @if ($version->changes === null)
                    <p class="muted">Versi pertama yang dipublikasikan.</p>
                @elseif ($version->changes === [])
                    <p class="muted">Judul atau tata letak diubah; isi pertanyaan sama.</p>
                @else
                    <ul class="version-changes">
                        @foreach ($version->changes as $change)
                            <li class="is-{{ $change['kind'] }}">
                                <b>{{ ['added' => 'Ditambah', 'removed' => 'Dihapus', 'changed' => 'Diubah'][$change['kind']] }}</b>
                                <span>{{ \Illuminate\Support\Str::limit(strip_tags($change['question']), 120) }}</span>
                                <small>{{ $change['detail'] }}</small>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </li>
        @empty
            <li class="muted">Belum ada versi. Versi pertama tercatat saat kuesioner dipublikasikan.</li>
        @endforelse
    </ol>
@endsection
