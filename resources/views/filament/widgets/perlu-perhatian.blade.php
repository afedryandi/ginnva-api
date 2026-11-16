<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Perlu Perhatian
        </x-slot>

        @php($items = $this->getItems())

        @if (count($items) === 0)
            <div class="gd-ok">
                <x-heroicon-o-check-circle />
                Semua aman, tidak ada yang perlu ditindaklanjuti hari ini.
            </div>
        @else
            <div class="gd-attn">
                @foreach ($items as $item)
                    <a href="{{ $item['url'] }}" class="gd-attn-item gd-attn-item--{{ $item['color'] }}">
                        <span class="gd-attn-icon">
                            <x-filament::icon :icon="$item['icon']" />
                        </span>
                        <span style="min-width: 0;">
                            <span class="gd-attn-title">
                                {{ $item['label'] }}
                                <x-filament::badge :color="$item['color']">
                                    {{ $item['count'] }}
                                </x-filament::badge>
                            </span>
                            <span class="gd-attn-text" style="display: block;">{{ $item['description'] }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
