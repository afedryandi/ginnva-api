<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $filterTargets = 'data.from, data.to';
    @endphp

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}" class="space-y-6">
    <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-400">
        Rating 5-bintang ala Majoo tidak ditampilkan — Ginnva cuma mencatat 3 tingkat sentiment (Positif/Netral/Negatif),
        bukan skala 1-5. Sentiment yang tersedia ditampilkan apa adanya di bawah.
    </div>

    {{-- style inline (bukan class grid-cols-*) -- panel Filament tidak
         compile Tailwind project ini, lihat catatan di SalesResource. --}}
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:1rem;">
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Review</div>
            <div class="mt-1 text-2xl font-bold tabular-nums">{{ number_format($result['total'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Positif</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-success-600 dark:text-success-400">{{ number_format($result['positive'], 0, ',', '.') }}</div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ number_format($result['positiveRate'], 1) }}% dari total</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Netral</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-gray-500 dark:text-gray-400">{{ number_format($result['neutral'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Negatif</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-danger-600 dark:text-danger-400">{{ number_format($result['negative'], 0, ',', '.') }}</div>
            @if ($result['unfollowedNegativeCount'] > 0)
                <div class="mt-1 text-xs text-warning-600 dark:text-warning-400">{{ $result['unfollowedNegativeCount'] }} belum ditindaklanjuti</div>
            @endif
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">Tag Aspek Paling Sering Disebut</x-slot>

        @if ($result['tagCounts']->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Belum ada tag pada rentang ini.</p>
        @else
            <div class="flex flex-wrap gap-2">
                @foreach ($result['tagCounts'] as $tag => $count)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700 dark:bg-white/5 dark:text-gray-300">
                        {{ $tag }}
                        <span class="rounded-full bg-gray-300 px-1.5 text-gray-700 dark:bg-white/10 dark:text-gray-300">{{ $count }}</span>
                    </span>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Per Toko</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="whitespace-nowrap py-2 px-3">Toko</th>
                        <th class="whitespace-nowrap py-2 px-3 text-right">Total Review</th>
                        <th class="whitespace-nowrap py-2 px-3 text-right">Positif</th>
                        <th class="whitespace-nowrap py-2 px-3 text-right">Negatif</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result['byStore'] as $storeName => $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="whitespace-nowrap py-2 px-3 font-medium">{{ $storeName }}</td>
                            <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums">{{ $row['total'] }}</td>
                            <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums text-success-600 dark:text-success-400">{{ $row['positive'] }}</td>
                            <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums text-danger-600 dark:text-danger-400">{{ $row['negative'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-4 text-center text-gray-500 dark:text-gray-400">Belum ada review pada rentang ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Ulasan Pelanggan</x-slot>

        @php
            $sentimentColor = fn (string $s) => match ($s) {
                'positive' => 'bg-success-100 text-success-700 dark:bg-success-500/10 dark:text-success-400',
                'negative' => 'bg-danger-100 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400',
                default => 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400',
            };
            $sentimentLabel = fn (string $s) => match ($s) {
                'positive' => 'Positif',
                'negative' => 'Negatif',
                default => 'Netral',
            };
        @endphp

        <div class="space-y-3">
            @forelse ($result['reviews'] as $review)
                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <div class="mb-1.5 flex flex-wrap items-center gap-2">
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $sentimentColor($review->sentiment) }}">
                            {{ $sentimentLabel($review->sentiment) }}
                        </span>
                        <span class="text-xs font-medium">{{ $review->customer?->name ?? 'Pelanggan' }}</span>
                        <span class="text-xs text-gray-400 dark:text-gray-500">— {{ $review->store?->name ?? '—' }}</span>
                        <span class="text-xs text-gray-400 dark:text-gray-500">{{ $review->created_at->format('d M Y') }}</span>
                    </div>
                    @if (! empty($review->tags))
                        <div class="mb-1.5 flex flex-wrap gap-1">
                            @foreach ($review->tags as $tag)
                                <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-400">{{ $tag }}</span>
                            @endforeach
                        </div>
                    @endif
                    <p class="text-sm text-gray-700 dark:text-gray-300">{{ $review->comment ?: '(Tanpa komentar)' }}</p>
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">Belum ada ulasan pada rentang ini.</p>
            @endforelse
        </div>
    </x-filament::section>
    </div>
</x-filament-panels::page>
