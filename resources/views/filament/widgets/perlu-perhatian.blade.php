<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Perlu Perhatian
        </x-slot>

        @php($items = $this->getItems())

        @if (count($items) === 0)
            <div class="flex items-center gap-x-3 py-2 text-sm text-gray-500 dark:text-gray-400">
                <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5 text-success-500 shrink-0" />
                Semua aman, tidak ada yang perlu ditindaklanjuti hari ini.
            </div>
        @else
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($items as $item)
                    <a
                        href="{{ $item['url'] }}"
                        @class([
                            'flex items-start gap-x-3 rounded-xl border p-3 transition-colors hover:bg-gray-50 dark:hover:bg-white/5',
                            'border-danger-200 dark:border-danger-500/30' => $item['color'] === 'danger',
                            'border-warning-200 dark:border-warning-500/30' => $item['color'] === 'warning',
                            'border-info-200 dark:border-info-500/30' => $item['color'] === 'info',
                        ])
                    >
                        <x-filament::icon
                            :icon="$item['icon']"
                            @class([
                                'h-6 w-6 shrink-0',
                                'text-danger-500' => $item['color'] === 'danger',
                                'text-warning-500' => $item['color'] === 'warning',
                                'text-info-500' => $item['color'] === 'info',
                            ])
                        />
                        <div class="min-w-0">
                            <div class="flex items-center gap-x-2">
                                <span class="text-sm font-semibold text-gray-950 dark:text-white">{{ $item['label'] }}</span>
                                <x-filament::badge :color="$item['color']">
                                    {{ $item['count'] }}
                                </x-filament::badge>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $item['description'] }}</p>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
