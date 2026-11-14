<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $shown = $result['rows']->take($this->limit);
        $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',') ?: '0';
        $filterTargets = 'data.from, data.to, data.jenis, data.preset, data.search, data.category, data.sort, data.only_moved';
        $detail = $this->getDetail();
    @endphp

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}" class="space-y-6">
    <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-400">
        Awal dan Akhir dihitung mundur dari stok sekarang dan riwayat pergerakan. Masuk = pencatatan masuk + penyesuaian positif; Keluar = pemakaian + penyesuaian negatif + stok terbuang. Awal + Masuk − Keluar = Akhir. Stok masih nasional (belum per toko).
    </div>

    @if ($result['totals']['anomalies'] > 0)
        <div class="rounded-lg border border-warning-300 bg-warning-50 px-4 py-3 text-sm text-warning-800 dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-warning-300">
            ⚠ {{ $result['totals']['anomalies'] }} barang punya Awal atau Akhir negatif: stok dan riwayatnya tidak sinkron. Angka ditampilkan apa adanya (merah); jalankan <strong>Cek Rekonsiliasi</strong> di menu Riwayat Bahan Baku / Barang Habis Pakai.
        </div>
    @endif

    <x-filament::section>
        <x-slot name="heading">Kartu Stok — {{ $result['from']->translatedFormat('d M Y') }} s/d {{ $result['to']->translatedFormat('d M Y') }}</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="whitespace-nowrap py-2 px-3">SKU</th>
                        <th class="whitespace-nowrap py-2 px-3">Nama</th>
                        <th class="whitespace-nowrap py-2 px-3">Jenis</th>
                        <th class="whitespace-nowrap py-2 px-3">Kategori</th>
                        <th class="whitespace-nowrap py-2 px-3 text-right">Awal</th>
                        <th class="whitespace-nowrap py-2 px-3 text-right">Masuk</th>
                        <th class="whitespace-nowrap py-2 px-3 text-right">Keluar</th>
                        <th class="whitespace-nowrap py-2 px-3 text-right">Akhir</th>
                        <th class="whitespace-nowrap py-2 px-3">Satuan</th>
                        <th class="whitespace-nowrap py-2 px-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($shown as $row)
                        @php $url = $this->itemUrl($row); @endphp
                        <tr class="border-b border-gray-100 dark:border-white/5 {{ $row['hasMovement'] ? '' : 'text-gray-400 dark:text-gray-500' }}">
                            <td class="whitespace-nowrap py-2 px-3 font-mono text-xs">{{ $row['code'] ?: '—' }}</td>
                            <td class="whitespace-nowrap py-2 px-3 font-medium">
                                @if ($url)
                                    <a href="{{ $url }}" class="hover:underline" title="Lihat item ini">{{ $row['name'] }}</a>
                                @else
                                    {{ $row['name'] }}
                                @endif
                                @if ($row['inactive'])
                                    <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600 dark:bg-white/10 dark:text-gray-300">Nonaktif</span>
                                @endif
                                @if ($row['low'])
                                    <span class="ml-1 rounded bg-danger-50 px-1.5 py-0.5 text-xs text-danger-700 dark:bg-danger-500/10 dark:text-danger-400" title="Stok sekarang di bawah atau sama dengan batas minimum {{ $num($row['reorder_point']) }} {{ $row['unit'] }}">Menipis</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap py-2 px-3">{{ $row['jenis'] }}</td>
                            <td class="whitespace-nowrap py-2 px-3 text-gray-500 dark:text-gray-400">{{ $row['category'] ?: '—' }}</td>
                            <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums {{ $row['awal'] < 0 ? 'font-semibold text-danger-600 dark:text-danger-400' : '' }}">{{ $num($row['awal']) }}</td>
                            <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums text-success-600 dark:text-success-400">{{ $row['masuk'] > 0 ? '+'.$num($row['masuk']) : '0' }}</td>
                            <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums text-danger-600 dark:text-danger-400">{{ $row['keluar'] > 0 ? '−'.$num($row['keluar']) : '0' }}</td>
                            <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums font-semibold {{ $row['akhir'] < 0 ? 'text-danger-600 dark:text-danger-400' : '' }}">{{ $num($row['akhir']) }}</td>
                            <td class="whitespace-nowrap py-2 px-3 text-gray-500 dark:text-gray-400">{{ $row['unit'] }}</td>
                            <td class="whitespace-nowrap py-2 px-3 text-right">
                                <button type="button" wire:click="openDetail('{{ $row['source'] }}', {{ $row['id'] }})" class="text-xs text-primary-600 hover:underline dark:text-primary-400">Rincian</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="py-4 text-center text-gray-500 dark:text-gray-400">Tidak ada bahan pada jenis ini.</td></tr>
                    @endforelse
                </tbody>
                @if ($result['rows']->isNotEmpty())
                    <tfoot>
                        <tr class="border-t-2 border-gray-300 font-semibold dark:border-white/20">
                            <td colspan="5" class="py-2 px-3">Total {{ $result['totals']['count'] }} barang (jumlah lintas satuan, hanya sebagai penanda)</td>
                            <td class="py-2 px-3 text-right tabular-nums text-success-600 dark:text-success-400">+{{ $num($result['totals']['masuk']) }}</td>
                            <td class="py-2 px-3 text-right tabular-nums text-danger-600 dark:text-danger-400">−{{ $num($result['totals']['keluar']) }}</td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        @if ($result['rows']->count() > $shown->count())
            <div class="mt-3 text-center">
                <x-filament::button color="gray" size="sm" wire:click="showMore">
                    Tampilkan lebih banyak ({{ $shown->count() }} dari {{ $result['rows']->count() }})
                </x-filament::button>
            </div>
        @endif
    </x-filament::section>

    @if ($detail)
        <x-filament::section>
            <x-slot name="heading">Rincian — {{ $detail['name'] }}</x-slot>
            <x-slot name="headerEnd">
                <x-filament::button color="gray" size="xs" wire:click="closeDetail">Tutup</x-filament::button>
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="whitespace-nowrap py-2 px-3">Waktu</th>
                            <th class="whitespace-nowrap py-2 px-3">Jenis</th>
                            <th class="whitespace-nowrap py-2 px-3 text-right">Jumlah</th>
                            <th class="whitespace-nowrap py-2 px-3 text-right">Saldo</th>
                            <th class="whitespace-nowrap py-2 px-3">Dicatat oleh</th>
                            <th class="whitespace-nowrap py-2 px-3">Sumber / Catatan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b border-gray-100 dark:border-white/5 text-gray-500 dark:text-gray-400">
                            <td class="py-2 px-3" colspan="3">Saldo awal periode</td>
                            <td class="py-2 px-3 text-right tabular-nums">{{ $num($detail['awal']) }} {{ $detail['unit'] }}</td>
                            <td colspan="2"></td>
                        </tr>
                        @forelse ($detail['lines'] as $line)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="whitespace-nowrap py-2 px-3">{{ $line['at']->format('d M Y, H:i') }}</td>
                                <td class="whitespace-nowrap py-2 px-3">{{ $line['type'] }}</td>
                                <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums {{ $line['signed'] > 0 ? 'text-success-600 dark:text-success-400' : ($line['signed'] < 0 ? 'text-danger-600 dark:text-danger-400' : '') }}">{{ $line['signed'] > 0 ? '+' : ($line['signed'] < 0 ? '−' : '') }}{{ $num(abs($line['signed'])) }}</td>
                                <td class="whitespace-nowrap py-2 px-3 text-right tabular-nums">{{ $num($line['balance']) }}</td>
                                <td class="whitespace-nowrap py-2 px-3">{{ $line['by'] ?? '—' }}{{ $line['store'] ? ' · '.$line['store'] : '' }}</td>
                                <td class="py-2 px-3 text-gray-600 dark:text-gray-300">{{ $line['source'] ? $line['source'].($line['note'] ? ' — ' : '') : '' }}{{ $line['note'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-4 text-center text-gray-500 dark:text-gray-400">Tidak ada pergerakan pada periode ini.</td></tr>
                        @endforelse
                        <tr class="font-semibold">
                            <td class="py-2 px-3" colspan="3">Saldo akhir periode</td>
                            <td class="py-2 px-3 text-right tabular-nums">{{ $num($detail['akhir']) }} {{ $detail['unit'] }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
    </div>
</x-filament-panels::page>
