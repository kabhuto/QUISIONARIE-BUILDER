{{-- One chart from App\Support\SurveySummary, drawn by js/response-charts.js, with its numbers as a table. --}}
@php
    $total = collect($chart['series'])->flatMap(fn (array $series): array => $series['data'])->sum();
    $isPie = $chart['type'] === 'pie';
@endphp
<div @class(['chart', 'chart-'.$chart['type']]) role="img" aria-label="Grafik {{ $label }}" data-chart="{{ json_encode($chart) }}"></div>
<details class="chart-table">
    <summary>Lihat angka</summary>
    <table class="data-table">
        <thead>
            <tr>
                <th scope="col">Kategori</th>
                @foreach ($chart['series'] as $series)
                    <th scope="col" class="align-end">{{ count($chart['series']) > 1 ? $series['name'] : 'Jumlah '.$chart['unit'] }}</th>
                @endforeach
                @if ($isPie)<th scope="col" class="align-end">Persen</th>@endif
            </tr>
        </thead>
        <tbody>
            @foreach ($chart['categories'] as $index => $category)
                <tr>
                    <td>{{ $category }}</td>
                    @foreach ($chart['series'] as $series)
                        <td class="align-end mono">{{ $series['data'][$index] }}</td>
                    @endforeach
                    @if ($isPie)<td class="align-end mono">{{ $total ? round($chart['series'][0]['data'][$index] / $total * 100) : 0 }}%</td>@endif
                </tr>
            @endforeach
        </tbody>
    </table>
</details>
