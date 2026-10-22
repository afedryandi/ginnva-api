<x-filament-panels::page>
    @php
        $guides = $this->getGuides();
        $haystacks = array_map(fn (array $guide) => \App\Filament\Pages\Panduan::searchText($guide), $guides);
        // Indeks panduan per kelompok: judul kelompok hanya tampil bila ada panduan di dalamnya yang cocok dengan pencarian.
        $groupIndexes = [];
        $categories = [];
        foreach ($guides as $i => $g) {
            $groupIndexes[$g['category'] ?? 'lainnya'][] = $i;
            $categories[$i] = $g['category'] ?? 'lainnya';
        }
        $firstGroup = array_key_first($groupIndexes);
    @endphp

    {{-- Pencarian murni di browser (Alpine): tidak ada permintaan ke server saat mengetik. --}}
    <div
        x-data="{
            q: '',
            items: @js($haystacks),
            groups: @js($groupIndexes),
            cats: @js($categories),
            // Kelompok yang dibuka: bawaan hanya kelompok pertama; saat mencari, semua kelompok yang cocok terbuka.
            first: @js($firstGroup),
            opened: {},
            isOpen(key) {
                return this.q.trim() !== '' || (this.opened[key] ?? key === this.first);
            },
            toggle(key) {
                this.opened[key] = ! this.isOpen(key);
            },
            show(i) {
                return this.match(i) && this.isOpen(this.cats[i]);
            },
            groupVisible(key) {
                return (this.groups[key] || []).some((i) => this.match(i));
            },
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
            @if ($index === 0 || ($guides[$index - 1]['category'] ?? null) !== ($guide['category'] ?? null))
                <h2 data-guide-group="{{ $guide['category'] ?? 'lainnya' }}" x-show="groupVisible('{{ $guide['category'] ?? 'lainnya' }}')" style="margin-top: 0.5rem;">
                    <button
                        type="button"
                        x-on:click="toggle('{{ $guide['category'] ?? 'lainnya' }}')"
                        x-bind:aria-expanded="isOpen('{{ $guide['category'] ?? 'lainnya' }}') ? 'true' : 'false'"
                        style="display: flex; width: 100%; align-items: center; justify-content: space-between; gap: 0.5rem; font-size: 1rem; font-weight: 700; text-align: left; cursor: pointer;"
                    >
                        <span>{{ \App\Filament\Pages\Panduan::categoryLabel($guide) }} ({{ count($groupIndexes[$guide['category'] ?? 'lainnya']) }})</span>
                        <span aria-hidden="true" x-text="isOpen('{{ $guide['category'] ?? 'lainnya' }}') ? '−' : '+'"></span>
                    </button>
                </h2>
            @endif
            <div x-show="show({{ $index }})" data-guide="{{ $guide['id'] }}">
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

                        @if (! empty($guide['note']))
                            <div data-guide-note style="margin-top: 1rem; padding: 0.75rem 1rem; border-left: 3px solid currentColor;">
                                <p style="font-weight: 600; margin-bottom: 0.25rem;">Catatan perusahaan</p>
                                <p>{!! nl2br(\App\Filament\Pages\Panduan::format($guide['note'])) !!}</p>
                            </div>
                        @endif

                        <p data-guide-footer style="margin-top: 1rem; font-size: 0.75rem; opacity: 0.7;">
                            Versi {{ $guide['version'] }} · ditinjau {{ \Illuminate\Support\Carbon::parse($guide['reviewed_at'])->translatedFormat('d M Y') }}
                            @if (\App\Support\GuideCatalog::isOverdue($guide))
                                · <strong data-guide-overdue>Perlu ditinjau ulang</strong>
                            @endif
                        </p>
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
