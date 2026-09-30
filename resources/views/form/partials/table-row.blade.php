@php
    $isFixed = $table['mode'] === 'fixed';
    $prefix = 'answers['.$question['id'].']['.$row.']';
@endphp
<tr data-table-row>
    <th scope="row" class="cawi-row-head">{{ $isFixed ? $table['rows'][$row] : (is_int($row) ? $row + 1 : '') }}</th>
    @foreach ($table['columns'] as $column)
        @php
            $value = $values[$column['id']] ?? '';
        @endphp
        <td>
            @switch($column['type'])
                @case('number')
                    <input class="cawi-cell" type="number" step="any" inputmode="decimal" name="{{ $prefix }}[{{ $column['id'] }}]" value="{{ $value }}" aria-label="{{ $column['label'] }}">
                    @break
                @case('date')
                    <input class="cawi-cell" type="date" name="{{ $prefix }}[{{ $column['id'] }}]" value="{{ $value }}" aria-label="{{ $column['label'] }}">
                    @break
                @case('choice')
                    <select class="cawi-cell" name="{{ $prefix }}[{{ $column['id'] }}]" aria-label="{{ $column['label'] }}">
                        <option value=""></option>
                        @foreach ($column['options'] as $option)
                            <option value="{{ $option }}" @selected($value === $option)>{{ $loop->iteration }}. {{ $option }}</option>
                        @endforeach
                    </select>
                    @break
                @default
                    <input class="cawi-cell" type="text" maxlength="255" name="{{ $prefix }}[{{ $column['id'] }}]" value="{{ $value }}" aria-label="{{ $column['label'] }}">
            @endswitch
        </td>
    @endforeach
    @unless ($isFixed)
        <td class="cawi-row-remove"><button type="button" data-remove-row aria-label="Hapus baris">×</button></td>
    @endunless
</tr>
