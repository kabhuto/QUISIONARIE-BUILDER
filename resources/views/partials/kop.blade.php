{{-- Kop: logos, text lines and labels placed left, centre or right by the survey's author. --}}
<div @class(['kop', $class ?? null])>
    @foreach ($survey->headerSlots() as $slot => $items)
        <div class="kop-slot kop-{{ $slot }}">
            @foreach ($items as $item)
                @if ($item['type'] === 'logo' && $item['path'])
                    <img class="kop-logo" src="{{ asset('storage/'.$item['path']) }}" style="height: {{ $item['height'] }}px" alt="">
                @elseif ($item['type'] === 'text' && $item['text'] !== '')
                    <div class="kop-text is-{{ $item['style'] }}">{{ $item['text'] }}</div>
                @elseif ($item['type'] === 'badge' && $item['text'] !== '')
                    <span class="kop-badge is-{{ $item['style'] }}">{{ $item['text'] }}</span>
                @endif
            @endforeach
        </div>
    @endforeach
</div>
