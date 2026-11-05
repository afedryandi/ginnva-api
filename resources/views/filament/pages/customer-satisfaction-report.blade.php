<x-filament-panels::page>
    {{-- Panel memakai CSS Filament yang sudah dikompilasi, jadi class Tailwind yang tidak dipakai bawaan Filament tidak berefek. Gaya di bawah
         menggantikannya untuk halaman ini. --}}
    @once
        <style>
            .sf-num { font-variant-numeric: tabular-nums; }
            .sf-r { text-align: right; }
            .sf-good { color: #15803d; } .dark .sf-good { color: #4ade80; }
            .sf-danger { color: #b91c1c; } .dark .sf-danger { color: #f87171; }
            .sf-warn { color: #b45309; } .dark .sf-warn { color: #fbbf24; }
            .sf-muted { color: #6b7280; } .dark .sf-muted { color: #9ca3af; }
            .sf-link { color: #ED1651; } .sf-link:hover { text-decoration: underline; }
            .sf-chips { display: flex; flex-wrap: wrap; gap: .5rem; }
            .sf-chip { display: inline-flex; align-items: center; gap: .375rem; border-radius: 9999px; padding: .25rem .75rem; font-size: .75rem; font-weight: 500; background: rgba(107, 114, 128, .12); }
            .sf-chip b { border-radius: 9999px; padding: 0 .375rem; background: rgba(107, 114, 128, .25); }
            .sf-badge { display: inline-block; border-radius: 9999px; padding: .125rem .5rem; font-size: .75rem; font-weight: 600; }
            .sf-badge-positive { background: rgba(21, 128, 61, .12); color: #15803d; } .dark .sf-badge-positive { background: rgba(74, 222, 128, .15); color: #4ade80; }
            .sf-badge-negative { background: rgba(185, 28, 28, .12); color: #b91c1c; } .dark .sf-badge-negative { background: rgba(248, 113, 113, .15); color: #f87171; }
            .sf-badge-neutral { background: rgba(107, 114, 128, .15); }
            .sf-tag { display: inline-block; border-radius: .25rem; padding: .125rem .375rem; font-size: .75rem; background: rgba(107, 114, 128, .12); }
            .sf-table th, .sf-table td { padding: .5rem .75rem; vertical-align: top; white-space: nowrap; }
            .sf-table th:first-child, .sf-table td:first-child { padding-left: 0; }
            .sf-table tfoot td { font-weight: 700; border-top: 2px solid rgba(107, 114, 128, .5); }
            .sf-review { border: 1px solid rgba(107, 114, 128, .25); border-radius: .5rem; padding: .75rem; }
            .sf-review-head { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin-bottom: .375rem; font-size: .75rem; }
            .sf-review-tags { display: flex; flex-wrap: wrap; gap: .25rem; margin-bottom: .375rem; }
        </style>
    @endonce

    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $filterTargets = 'data.from, data.to, data.store_id, data.preset, data.sentiment, data.follow_up';
        $pct = fn ($n) => number_format($n, 1) . '%';
        $signed = fn ($n) => ($n > 0 ? '+' : '') . number_format($n, 0);
        $hours = fn ($h) => $h === null ? '—' : ($h < 48 ? number_format($h, 0) . ' jam' : number_format($h / 24, 1) . ' hari');
    @endphp

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}" class="space-y-6">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 16px">
            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Total Review</div>
                <div class="mt-1 text-2xl font-bold sf-num">{{ number_format($result['total'], 0, ',', '.') }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    @if ($result['responseRate'] !== null)
                        Tingkat respons {{ $pct($result['responseRate']) }} dari {{ number_format($result['completedBookings'], 0, ',', '.') }} booking selesai.
                    @else
                        Belum ada booking selesai pada rentang ini.
                    @endif
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Positif</div>
                <div class="mt-1 text-2xl font-bold sf-num sf-good">{{ number_format($result['positive'], 0, ',', '.') }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ number_format($result['positiveRate'], 1) }}% dari total</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Netral</div>
                <div class="mt-1 text-2xl font-bold sf-num sf-muted">{{ number_format($result['neutral'], 0, ',', '.') }}</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Negatif</div>
                <div class="mt-1 text-2xl font-bold sf-num sf-danger">{{ number_format($result['negative'], 0, ',', '.') }}</div>
                @if ($result['unfollowedNegativeCount'] > 0)
                    <div class="mt-1 text-xs sf-warn">{{ $result['unfollowedNegativeCount'] }} belum ditindaklanjuti</div>
                @endif
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Skor Bersih</div>
                <div class="mt-1 text-2xl font-bold sf-num {{ $result['netScore'] >= 0 ? 'sf-good' : 'sf-danger' }}">{{ $signed($result['netScore']) }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">(Positif − Negatif) ÷ Total, skala −100 sampai +100.</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Lama Tindak Lanjut</div>
                <div class="mt-1 text-2xl font-bold sf-num">{{ $hours($result['avgFollowUpHours']) }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Rata-rata dari ulasan negatif masuk sampai ditandai ditindaklanjuti.</div>
            </x-filament::section>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px">
            <x-filament::section>
                <x-slot name="heading">Aspek yang Disukai</x-slot>
                @if ($result['tagGroups']['positive']->isEmpty())
                    <p class="text-sm text-gray-500 dark:text-gray-400">Belum ada tag positif pada rentang ini.</p>
                @else
                    <div class="sf-chips">
                        @foreach ($result['tagGroups']['positive'] as $tag => $count)
                            <span class="sf-chip">{{ \App\Models\StoreReview::tagLabel((string) $tag) }} <b>{{ $count }}</b></span>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Aspek yang Perlu Diperbaiki</x-slot>
                @if ($result['tagGroups']['negative']->isEmpty())
                    <p class="text-sm text-gray-500 dark:text-gray-400">Belum ada tag keluhan pada rentang ini.</p>
                @else
                    <div class="sf-chips">
                        @foreach ($result['tagGroups']['negative'] as $tag => $count)
                            <span class="sf-chip">{{ \App\Models\StoreReview::tagLabel((string) $tag) }} <b>{{ $count }}</b></span>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>
        </div>

        <x-filament::section>
            <x-slot name="heading">Tren {{ $result['trendMonthly'] ? 'per Bulan' : 'per Minggu' }}</x-slot>

            @if ($result['trend']->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">Belum ada review pada rentang ini.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm sf-table">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th>{{ $result['trendMonthly'] ? 'Bulan' : 'Minggu' }}</th>
                                <th class="sf-r">Total</th>
                                <th class="sf-r">Positif</th>
                                <th class="sf-r">Negatif</th>
                                <th class="sf-r">Skor Bersih</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($result['trend'] as $row)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="font-medium">{{ $row['label'] }}</td>
                                    <td class="sf-r sf-num">{{ $row['total'] }}</td>
                                    <td class="sf-r sf-num sf-good">{{ $row['positive'] }}</td>
                                    <td class="sf-r sf-num sf-danger">{{ $row['negative'] }}</td>
                                    <td class="sf-r sf-num {{ $row['net'] >= 0 ? 'sf-good' : 'sf-danger' }}">{{ $signed($row['net']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Per Toko</x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm sf-table">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th>Toko</th>
                            <th class="sf-r">Total Review</th>
                            <th class="sf-r">Positif</th>
                            <th class="sf-r">Netral</th>
                            <th class="sf-r">Negatif</th>
                            <th class="sf-r">% Positif</th>
                            <th class="sf-r">Skor Bersih</th>
                            <th class="sf-r">Belum Ditindaklanjuti</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($result['byStore'] as $storeName => $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="font-medium">{{ $storeName }}</td>
                                <td class="sf-r sf-num">{{ $row['total'] }}</td>
                                <td class="sf-r sf-num sf-good">{{ $row['positive'] }}</td>
                                <td class="sf-r sf-num">{{ $row['neutral'] }}</td>
                                <td class="sf-r sf-num sf-danger">{{ $row['negative'] }}</td>
                                <td class="sf-r sf-num">{{ $pct($row['positiveRate']) }}</td>
                                <td class="sf-r sf-num {{ $row['net'] >= 0 ? 'sf-good' : 'sf-danger' }}">{{ $signed($row['net']) }}</td>
                                <td class="sf-r sf-num {{ $row['unfollowed'] > 0 ? 'sf-warn' : '' }}">{{ $row['unfollowed'] > 0 ? $row['unfollowed'] : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-gray-500 dark:text-gray-400" style="padding: 1rem 0">Belum ada review pada rentang ini.</td></tr>
                        @endforelse
                    </tbody>
                    @if (count($result['byStore']) > 1)
                        <tfoot>
                            <tr>
                                <td>Total</td>
                                <td class="sf-r sf-num">{{ $result['total'] }}</td>
                                <td class="sf-r sf-num">{{ $result['positive'] }}</td>
                                <td class="sf-r sf-num">{{ $result['neutral'] }}</td>
                                <td class="sf-r sf-num">{{ $result['negative'] }}</td>
                                <td class="sf-r sf-num">{{ $pct($result['positiveRate']) }}</td>
                                <td class="sf-r sf-num">{{ $signed($result['netScore']) }}</td>
                                <td class="sf-r sf-num">{{ $result['unfollowedNegativeCount'] > 0 ? $result['unfollowedNegativeCount'] : '—' }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Review Pelanggan</x-slot>
            @if ($result['displayReviewsTruncated'])
                <x-slot name="description">Menampilkan 100 review terbaru dari {{ $result['listTotal'] }} yang cocok dengan saringan ({{ $result['total'] }} total pada rentang ini). Export Excel/PDF tetap berisi semuanya.</x-slot>
            @elseif ($result['listTotal'] !== $result['total'])
                <x-slot name="description">{{ $result['listTotal'] }} dari {{ $result['total'] }} review cocok dengan saringan daftar.</x-slot>
            @endif

            @php
                $sentimentLabel = fn (string $s) => match ($s) {
                    'positive' => 'Positif',
                    'negative' => 'Negatif',
                    default => 'Netral',
                };
            @endphp

            <div class="space-y-3">
                @forelse ($result['displayReviews'] as $review)
                    <div class="sf-review">
                        <div class="sf-review-head">
                            <span class="sf-badge sf-badge-{{ in_array($review->sentiment, ['positive', 'negative'], true) ? $review->sentiment : 'neutral' }}">{{ $sentimentLabel($review->sentiment) }}</span>
                            <span class="font-medium">{{ $review->customer?->name ?? 'Pelanggan' }}</span>
                            <span class="sf-muted">— {{ $review->store?->name ?? '—' }}</span>
                            <span class="sf-muted">{{ $review->created_at->format('d M Y') }}</span>
                            @if ($review->booking)
                                <span class="sf-muted">· {{ $review->booking->booking_number }}@if ($review->booking->salesProductLabel()) ({{ $review->booking->salesProductLabel() }})@endif</span>
                            @endif
                            @if ($review->sentiment === 'negative' && ! $review->followed_up_at)
                                <a href="{{ $this->reviewUrl($review->id) }}" class="sf-link font-medium" style="margin-left: auto">Tindaklanjuti →</a>
                            @else
                                <a href="{{ $this->reviewUrl($review->id) }}" class="sf-muted" style="margin-left: auto">Lihat detail →</a>
                            @endif
                        </div>
                        @if (! empty($review->tags))
                            <div class="sf-review-tags">
                                @foreach ($review->tags as $tag)
                                    <span class="sf-tag">{{ \App\Models\StoreReview::tagLabel((string) $tag) }}</span>
                                @endforeach
                            </div>
                        @endif
                        <p class="text-sm text-gray-700 dark:text-gray-300">{{ $review->comment ?: '(Tanpa komentar)' }}</p>
                    </div>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400">Belum ada review pada rentang ini.</p>
                @endforelse
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
