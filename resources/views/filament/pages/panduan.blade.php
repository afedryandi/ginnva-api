<x-filament-panels::page>
    @php($guides = $this->getGuides())

    <x-filament::input.wrapper>
        <x-filament::input
            type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="Cari panduan, mis. voucher, maintenance, opname..."
        />
    </x-filament::input.wrapper>

    @forelse ($guides as $guide)
        <x-filament::section :heading="$guide['title']" :description="$guide['summary']" collapsible :collapsed="count($guides) > 1 && $this->search === ''">
            <div style="font-size: 0.875rem; line-height: 1.5rem;">
                <p style="font-weight: 600; margin-bottom: 0.25rem;">Langkah-langkah</p>
                <ol style="list-style: decimal; padding-left: 1.25rem; margin-bottom: 1rem; display: grid; gap: 0.375rem;">
                    @foreach ($guide['steps'] as $step)
                        <li>{!! \App\Filament\Pages\Panduan::format($step) !!}</li>
                    @endforeach
                </ol>

                @if (! empty($guide['tips']))
                    <p style="font-weight: 600; margin-bottom: 0.25rem;">Perlu diketahui</p>
                    <ul style="list-style: disc; padding-left: 1.25rem; display: grid; gap: 0.375rem;">
                        @foreach ($guide['tips'] as $tip)
                            <li>{!! \App\Filament\Pages\Panduan::format($tip) !!}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </x-filament::section>
    @empty
        <x-filament::section>
            <p style="font-size: 0.875rem;">Tidak ada panduan yang cocok{{ $this->search !== '' ? ' dengan "' . $this->search . '"' : '' }}. Coba kata kunci lain.</p>
        </x-filament::section>
    @endforelse
</x-filament-panels::page>
