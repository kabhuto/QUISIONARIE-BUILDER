{{-- A repeating block on paper: one line per row, one column per question (like a listing). --}}
@php
    $rosterQuestions = array_values(array_filter(
        $block['questions'],
        fn (array $question): bool => $question['type'] !== 'info' && ! ($question['hidden'] && ! in_array('show', $question['logic']['actions'] ?? [], true)),
    ));
    $rosterInfo = array_values(array_filter($block['questions'], fn (array $question): bool => $question['type'] === 'info'));
    $rosterRows = $response
        ? \App\Support\SurveyLogic::rosterRows($block, $answers ?? [])
        : array_fill(0, (int) ($block['repeat']['print_rows'] ?? 3), '');
    $rosterSource = $block['repeat']['source'];
    $columnText = fn (array $question): string => str_replace(\App\Models\Survey::ROW_TOKEN, '…', \App\Support\Piping::text($question['label'], $numbers));
@endphp
<div class="sheet-roster-note">
    Diisi untuk setiap rincian pada pertanyaan {{ $numbers[$rosterSource] ?? '' }}@if (! $response) (tambah lembar bila kurang)@endif.
    @foreach ($rosterInfo as $info)
        <br><b>{{ $info['label'] }}</b>@if ($info['description'] !== '') — {{ $info['description'] }}@endif
    @endforeach
</div>
<table class="sheet-subtable sheet-roster">
    <thead>
        <tr>
            <th>No</th>
            <th>Rincian</th>
            @foreach ($rosterQuestions as $question)
                <th>
                    <b>{{ $numbers[$question['id']] ?? '' }}</b> {{ $columnText($question) }}
                    @if (in_array($question['type'], \App\Models\Survey::CHOICE_TYPES, true) && $question['options'] !== [])
                        <small>@foreach ($question['options'] as $option){{ $loop->iteration }}. {{ $option }}@unless ($loop->last)<br>@endunless @endforeach @if (\App\Models\Survey::hasOtherOption($question))<br>{{ count($question['options']) + 1 }}. {{ \App\Models\Survey::OTHER_VALUE }}@endif</small>
                    @elseif ($question['type'] === 'calc')
                        <small>= {{ \App\Support\Piping::text($question['formula'] ?? '', $numbers) }}</small>
                    @endif
                </th>
            @endforeach
        </tr>
        <tr class="sheet-col-numbers">
            <td>(1)</td>
            <td>(2)</td>
            @foreach ($rosterQuestions as $question)
                <td>({{ $loop->iteration + 2 }})</td>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($rosterRows as $row => $rowLabel)
            <tr>
                <td>{{ $row + 1 }}</td>
                <th>{{ $rowLabel }}</th>
                @foreach ($rosterQuestions as $question)
                    @php
                        $cell = is_array($answers[$question['id']] ?? null) ? ($answers[$question['id']][$row] ?? null) : null;
                        $others = (array) ($answers[$question['id'].\App\Models\Survey::OTHER_SUFFIX] ?? []);
                    @endphp
                    <td>{{ \App\Models\SurveyResponse::displayValue($question, $cell, (string) ($others[$row] ?? '')) }}</td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>
