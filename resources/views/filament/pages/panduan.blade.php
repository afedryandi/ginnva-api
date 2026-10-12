<x-filament-panels::page>
    @php
        $guides = $this->getGuides();
        $haystacks = array_map(fn (array $guide) => \App\Filament\Pages\Panduan::searchText($guide), $guides);
    @endphp

    {{-- Pencarian murni di browser (Alpine): tidak ada permintaan ke server saat mengetik. --}}
    <div
        x-data="{
            q: '',
            items: @js($haystacks),
            match(index) {
                const s = this.q.trim().toLowerCase();
                return s === '' || this.items[index].includes(s);
            },
            get none() {
                const s = this.q.trim().toLowerCase();
                return s !== '' && ! this.items.some((h) => h.includes(s));
            },
        }"
        style="display: grid; gap: 1rem;"
    >
        <x-filament::input.wrapper>
            <x-filament::input
                type="search"
                x-model="q"
                placeholder="Cari panduan, mis. voucher, maintenance, opname..."
            />
        </x-filament::input.wrapper>

        @if (\App\Support\AdminTour::enabled())
            <p style="font-size: 0.875rem;">
                Baru memakai panel ini? <a href="{{ filament()->getUrl() }}?tur=1" style="text-decoration: underline; font-weight: 600;">Ulangi tur pengenalan</a>
            </p>
        @endif

        @foreach ($guides as $index => $guide)
            <div x-show="match({{ $index }})" data-guide="{{ $guide['id'] }}">
                <x-filament::section :heading="$guide['title']" :description="$guide['summary']">
                    <details x-bind:open="q.trim() !== ''" style="font-size: 0.875rem; line-height: 1.5rem;">
                        <summary style="cursor: pointer; font-weight: 600; margin-bottom: 0.5rem;">Lihat langkah-langkah</summary>

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
                    </details>
                </x-filament::section>
            </div>
        @endforeach

        <div x-show="none" x-cloak>
            <x-filament::section>
                <p style="font-size: 0.875rem;">Tidak ada panduan yang cocok dengan pencarian Anda. Coba kata kunci lain.</p>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
