{{-- Kop: logos, text lines and labels placed left, centre or right by the survey's author. --}}
<div @class(['kop', 'kop-align-'.$survey->headerAlign(), $class ?? null])>
    @foreach ($survey->headerSlots() as $slot => $items)
        <div class="kop-slot kop-{{ $slot }}">
            @foreach ($items as $item)
                @php
                    // Optional vertical nudge so items in different slots can be lined up (e.g. CAWI with RAHASIA).
                    $offset = (int) ($item['offset'] ?? 0);
                    $shift = $offset !== 0 ? 'transform: translateY('.$offset.'px);' : '';
                @endphp
                @if ($item['type'] === 'logo' && $item['path'])
                    <img class="kop-logo" src="{{ asset('storage/'.$item['path']) }}" style="height: {{ $item['height'] }}px; {{ $shift }}" alt="">
                @elseif ($item['type'] === 'text' && $item['text'] !== '')
                    <div class="kop-text is-{{ $item['style'] }}" @if ($shift) style="{{ $shift }}" @endif>{{ $item['text'] }}</div>
                @elseif ($item['type'] === 'badge' && $item['text'] !== '')
                    <span class="kop-badge is-{{ $item['style'] }}" @if ($shift) style="{{ $shift }}" @endif>{{ $item['text'] }}</span>
                @endif
            @endforeach
        </div>
    @endforeach
</div>
