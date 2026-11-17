<?php

namespace App\Services;

use App\Models\ConsumableItem;
use App\Models\ConsumableItemMovement;
use App\Models\RawMaterial;
use App\Models\RawMaterialMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Perhitungan Daftar Stok / Kartu Stok (Awal, Masuk, Keluar, Akhir per bahan baku dan barang habis pakai untuk satu rentang), dipakai
 * halaman panel, export, PDF, dan aplikasi mobile supaya angkanya selalu sama.
 *
 * "Akhir" direkonstruksi dari current_stock dikurangi net pergerakan SETELAH rentang; "Awal" = Akhir dikurangi net di dalam rentang
 * (sistem tidak menyimpan snapshot stok harian). Net: 'out' negatif, 'in' dan opname (bertanda) positif. Pergerakan yang dibatalkan
 * tidak dihitung (scope 'effective' pada kedua model pergerakan). Agregasi dilakukan di SQL per barang, bukan memuat semua baris.
 * Stok bersifat nasional: tidak ada pemisahan per toko.
 *
 * Masuk dan Keluar dirinci menurut asalnya (2026-11-17), supaya "Masuk" tidak dibaca sebagai pembelian saja:
 *   Masuk  = Pembelian (masuk bukan dari memo) + Pengembalian memo + Penyesuaian (opname/koreksi lebih).
 *   Keluar = Pakai (keluar, termasuk pengambilan memo) + Dibuang (Stok Terbuang) + Penyesuaian (opname/koreksi kurang).
 */
class StockCardService
{
    public const JENIS = ['all', 'raw_material', 'consumable_item'];

    public const SORTS = [
        'name' => 'Nama (A-Z)',
        'masuk_desc' => 'Masuk terbanyak',
        'keluar_desc' => 'Keluar terbanyak',
        'akhir_asc' => 'Stok terkecil',
        'akhir_desc' => 'Stok terbanyak',
    ];

    /**
     * @return array{from: Carbon, to: Carbon, jenis: string, rows: Collection, totals: array{masuk: float, keluar: float, count: int, anomalies: int}}
     */
    public function build(Carbon $from, Carbon $to, string $jenis = 'all', ?string $search = null, ?string $category = null, bool $onlyMoved = false, string $sort = 'name'): array
    {
        $rows = collect();

        if (in_array($jenis, ['all', 'raw_material'], true)) {
            $rows = $rows->merge($this->rowsFor(RawMaterial::class, RawMaterialMovement::class, 'raw_material_id', 'Bahan Baku', 'raw_material', $from, $to, $search, $category));
        }

        if (config('features.consumables') && in_array($jenis, ['all', 'consumable_item'], true)) {
            $rows = $rows->merge($this->rowsFor(ConsumableItem::class, ConsumableItemMovement::class, 'consumable_item_id', 'Barang Habis Pakai', 'consumable_item', $from, $to, $search, $category));
        }

        if ($onlyMoved) {
            $rows = $rows->where('hasMovement', true);
        }

        $rows = match ($sort) {
            'masuk_desc' => $rows->sortByDesc('masuk'),
            'keluar_desc' => $rows->sortByDesc('keluar'),
            'akhir_asc' => $rows->sortBy('akhir'),
            'akhir_desc' => $rows->sortByDesc('akhir'),
            default => $rows, // bawaan: Bahan Baku dulu lalu Barang Habis Pakai, masing-masing urut nama
        };

        $rows = $rows->values();

        return [
            'from' => $from,
            'to' => $to,
            'jenis' => $jenis,
            'rows' => $rows,
            'totals' => [
                'masuk' => round((float) $rows->sum('masuk'), 2),
                'keluar' => round((float) $rows->sum('keluar'), 2),
                'masuk_detail' => [
                    'pembelian' => round((float) $rows->sum('masuk_detail.pembelian'), 2),
                    'pengembalian' => round((float) $rows->sum('masuk_detail.pengembalian'), 2),
                    'penyesuaian' => round((float) $rows->sum('masuk_detail.penyesuaian'), 2),
                ],
                'keluar_detail' => [
                    'pakai' => round((float) $rows->sum('keluar_detail.pakai'), 2),
                    'dibuang' => round((float) $rows->sum('keluar_detail.dibuang'), 2),
                    'penyesuaian' => round((float) $rows->sum('keluar_detail.penyesuaian'), 2),
                ],
                'count' => $rows->count(),
                'anomalies' => $rows->where('anomaly', true)->count(),
            ],
        ];
    }

    /** Kategori yang dipakai kedua jenis, untuk pilihan filter. */
    public function categories(string $jenis = 'all'): array
    {
        $categories = collect();

        if (in_array($jenis, ['all', 'raw_material'], true)) {
            $categories = $categories->merge(RawMaterial::query()->whereNotNull('category')->distinct()->pluck('category'));
        }

        if (config('features.consumables') && in_array($jenis, ['all', 'consumable_item'], true)) {
            $categories = $categories->merge(ConsumableItem::query()->whereNotNull('category')->distinct()->pluck('category'));
        }

        return $categories->unique()->sort()->mapWithKeys(fn ($c) => [$c => $c])->all();
    }

    /**
     * Mutasi satu barang dalam rentang beserta saldo berjalan (rincian kartu stok).
     *
     * @return array{name: string, unit: string, awal: float, akhir: float, lines: list<array<string, mixed>>}|null
     */
    public function detail(string $source, int $id, Carbon $from, Carbon $to): ?array
    {
        [$itemClass, $movementClass, $fk] = $source === 'raw_material'
            ? [RawMaterial::class, RawMaterialMovement::class, 'raw_material_id']
            : [ConsumableItem::class, ConsumableItemMovement::class, 'consumable_item_id'];

        $item = $itemClass::query()->find($id);

        if ($item === null) {
            return null;
        }

        $net = fn (string $type, $qty) => $type === 'out' ? -(float) $qty : (float) $qty;

        $netAfter = $movementClass::query()->where($fk, $id)->where('created_at', '>', $to)->get(['type', 'quantity'])->sum(fn ($m) => $net($m->type, $m->quantity));
        $akhir = (float) $item->current_stock - $netAfter;

        $movements = $movementClass::query()->where($fk, $id)->whereBetween('created_at', [$from, $to])
            ->with(['user:id,name', 'store:id,name'])->orderBy('created_at')->orderBy('id')->get();

        $awal = $akhir - $movements->sum(fn ($m) => $net($m->type, $m->quantity));
        $balance = $awal;
        $lines = [];

        foreach ($movements as $m) {
            $balance += $net($m->type, $m->quantity);
            $lines[] = [
                'at' => $m->created_at,
                'type' => match ($m->type) {
                    'in' => 'Masuk',
                    'out' => 'Keluar',
                    'adjustment' => 'Opname',
                    'correction' => 'Koreksi',
                    default => $m->type,
                },
                'signed' => $m->type === 'correction' ? 0.0 : $net($m->type, $m->quantity),
                'balance' => round($balance, 2),
                'by' => $m->user?->name,
                'store' => $m->store?->name,
                'source' => $m->sourceText(),
                'note' => $m->note,
            ];
        }

        return [
            'name' => $item->name,
            'unit' => $item->unit,
            'awal' => $this->clean($awal),
            'akhir' => $this->clean($akhir),
            'lines' => $lines,
        ];
    }

    private function rowsFor(string $itemClass, string $movementClass, string $fk, string $jenis, string $source, Carbon $from, Carbon $to, ?string $search, ?string $category): Collection
    {
        $net = "CASE WHEN type = 'out' THEN -quantity ELSE quantity END";
        $in = "CASE WHEN type = 'in' THEN quantity WHEN type = 'adjustment' AND quantity > 0 THEN quantity ELSE 0 END";
        $out = "CASE WHEN type = 'out' THEN quantity WHEN type = 'adjustment' AND quantity < 0 THEN -quantity ELSE 0 END";

        $notMemo = "(source_type IS NULL OR source_type <> 'memo')";
        $parts = [
            'masuk_beli' => "CASE WHEN type = 'in' AND {$notMemo} THEN quantity ELSE 0 END",
            'masuk_kembali' => "CASE WHEN type = 'in' AND source_type = 'memo' THEN quantity ELSE 0 END",
            'masuk_adj' => "CASE WHEN type = 'adjustment' AND quantity > 0 THEN quantity ELSE 0 END",
            'keluar_pakai' => "CASE WHEN type = 'out' THEN quantity ELSE 0 END",
            'keluar_buang' => "CASE WHEN type = 'adjustment' AND quantity < 0 AND source_type = 'stock_write_off' THEN -quantity ELSE 0 END",
            'keluar_adj' => "CASE WHEN type = 'adjustment' AND quantity < 0 AND (source_type IS NULL OR source_type <> 'stock_write_off') THEN -quantity ELSE 0 END",
        ];
        $partSelect = '';
        $partBindings = [];
        foreach ($parts as $alias => $sql) {
            $partSelect .= "SUM(CASE WHEN created_at <= ? THEN ({$sql}) ELSE 0 END) as {$alias}, ";
            $partBindings[] = $to;
        }

        $aggregates = $movementClass::query()
            ->selectRaw(
                "{$fk} as item_id, " . $partSelect
                . "SUM(CASE WHEN created_at > ? THEN ({$net}) ELSE 0 END) as net_after, "
                . "SUM(CASE WHEN created_at <= ? THEN ({$net}) ELSE 0 END) as net_during, "
                . "SUM(CASE WHEN created_at <= ? THEN ({$in}) ELSE 0 END) as masuk, "
                . "SUM(CASE WHEN created_at <= ? THEN ({$out}) ELSE 0 END) as keluar, "
                . 'SUM(CASE WHEN created_at <= ? THEN 1 ELSE 0 END) as moved',
                [...$partBindings, $to, $to, $to, $to, $to]
            )
            ->where('created_at', '>=', $from)
            ->groupBy($fk)
            ->get()
            ->keyBy('item_id');

        return $itemClass::query()
            ->when($search !== null && trim($search) !== '', fn (Builder $q) => $q->where(fn ($w) => $w->where('name', 'like', '%' . trim($search) . '%')->orWhere('code', 'like', '%' . trim($search) . '%')))
            ->when($category !== null && $category !== '', fn (Builder $q) => $q->where('category', $category))
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'category', 'unit', 'current_stock', 'reorder_point', 'is_active'])
            ->map(function ($item) use ($aggregates, $jenis, $source) {
                $a = $aggregates->get($item->id);
                $akhirRaw = (float) $item->current_stock - (float) ($a->net_after ?? 0);
                $awalRaw = $akhirRaw - (float) ($a->net_during ?? 0);
                $moved = (int) ($a->moved ?? 0) > 0;

                // Barang nonaktif hanya tampil bila masih berstok atau bergerak di periode.
                if (! $item->is_active && (float) $item->current_stock <= 0 && ! $moved) {
                    return null;
                }

                return [
                    'id' => $item->id,
                    'source' => $source,
                    'name' => $item->name,
                    'code' => $item->code,
                    'category' => $item->category,
                    'jenis' => $jenis,
                    'unit' => $item->unit,
                    'awal' => $this->clean($awalRaw),
                    'masuk' => round((float) ($a->masuk ?? 0), 2),
                    'keluar' => round((float) ($a->keluar ?? 0), 2),
                    'masuk_detail' => [
                        'pembelian' => round((float) ($a->masuk_beli ?? 0), 2),
                        'pengembalian' => round((float) ($a->masuk_kembali ?? 0), 2),
                        'penyesuaian' => round((float) ($a->masuk_adj ?? 0), 2),
                    ],
                    'keluar_detail' => [
                        'pakai' => round((float) ($a->keluar_pakai ?? 0), 2),
                        'dibuang' => round((float) ($a->keluar_buang ?? 0), 2),
                        'penyesuaian' => round((float) ($a->keluar_adj ?? 0), 2),
                    ],
                    'akhir' => $this->clean($akhirRaw),
                    'hasMovement' => $moved,
                    'inactive' => ! $item->is_active,
                    'low' => $item->reorder_point !== null && (float) $item->current_stock <= (float) $item->reorder_point,
                    'reorder_point' => $item->reorder_point !== null ? (float) $item->reorder_point : null,
                    // Rekonstruksi negatif = stok dan riwayat tidak sinkron (cek Rekonsiliasi di menu Riwayat).
                    'anomaly' => $awalRaw < -0.005 || $akhirRaw < -0.005,
                ];
            })
            ->filter()
            ->values();
    }

    /** Teks rincian satu kolom, mis. "Pembelian 10 · Pengembalian 2"; bagian bernilai 0 dihilangkan. */
    public static function breakdownText(array $detail, array $labels, callable $format): string
    {
        $parts = [];

        foreach ($labels as $key => $label) {
            if (($detail[$key] ?? 0) > 0) {
                $parts[] = $label . ' ' . $format($detail[$key]);
            }
        }

        return implode(' · ', $parts);
    }

    public const MASUK_LABELS = ['pembelian' => 'Pembelian', 'pengembalian' => 'Pengembalian memo', 'penyesuaian' => 'Penyesuaian'];

    public const KELUAR_LABELS = ['pakai' => 'Pakai', 'dibuang' => 'Dibuang', 'penyesuaian' => 'Penyesuaian'];

    private function clean(float $value): float
    {
        $rounded = round($value, 2);

        return abs($rounded) < 0.005 ? 0.0 : $rounded;
    }
}
