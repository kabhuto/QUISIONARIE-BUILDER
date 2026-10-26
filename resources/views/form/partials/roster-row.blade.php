{{-- One row of a repeating block; the '__R__' row is the template JavaScript copies for new rows. --}}
<div class="roster-row" data-roster-row="{{ $row }}" data-row-key="{{ $rowLabel }}">
    <h3 class="roster-row-head">
        <span>Rincian <b data-row-number>{{ is_int($row) ? $row + 1 : '' }}</b></span>
        @unless ($repeatKind === 'number')
            <strong data-row-label>{{ $rowLabel }}</strong>
        @endunless
    </h3>
    <div class="cawi-block-body">
        @foreach ($block['questions'] as $question)
            @include('form.partials.question', ['question' => $question, 'numbers' => $numbers, 'row' => $row, 'rowLabel' => $rowLabel])
        @endforeach
    </div>
</div>
