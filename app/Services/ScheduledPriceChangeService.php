<?php

namespace App\Services;

use App\Models\FilmProduct;
use App\Models\ScheduledPriceChange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Perubahan harga terjadwal (audit ACCURATE 5, "Penyesuaian Harga Jual"): mengubah harga produk film dalam persen pada tanggal efektif.
 * Yang diubah: harga dasar (flat), harga per ukuran kendaraan, dan (bila dipilih) harga khusus per grup pelanggan. Harga yang nol atau
 * kosong dilewati. Tiap harga disimpan lewat modelnya, jadi riwayat aktivitas mencatat harga lama dan barunya.
 *
 * Booking, SPK, dan invoice yang sudah dibuat menyimpan harganya sendiri, jadi tidak ikut berubah.
 */
class ScheduledPriceChangeService
{
    public const MIN_PERCENT = -90.0;

    public const MAX_PERCENT = 300.0;

    /** Harga baru dari harga lama: persentase, lalu dibulatkan ke kelipatan terdekat (atau rupiah penuh). */
    public static function newPrice(float $old, float $percent, int $roundingStep = 0): float
    {
        $raw = $old * (1 + $percent / 100);
        $new = $roundingStep > 0 ? round($raw / $roundingStep) * $roundingStep : round($raw);

        return max(0.0, (float) $new);
    }

    /**
     * Gambaran sebelum disimpan atau diterapkan: tidak menulis apa pun.
     *
     * @param  array{percent: float|int|string, rounding_step?: int|string|null, product_ids?: ?array, include_group_prices?: bool}  $params
     * @return array{count: int, products: int, sample: list<array{product: string, label: string, old: float, new: float}>}
     */
    public function preview(array $params, int $sampleSize = 8): array
    {
        $rows = $this->changes($params);

        return [
            'count' => $rows->count(),
            'products' => $rows->pluck('product')->unique()->count(),
            'sample' => $rows->take($sampleSize)->map(fn (array $r) => [
                'product' => $r['product'], 'label' => $r['label'], 'old' => $r['old'], 'new' => $r['new'],
            ])->values()->all(),
        ];
    }

    /**
     * Terapkan satu perubahan. Mengembalikan jumlah harga yang berubah, atau null bila perubahan ini sudah tidak berstatus terjadwal
     * (sudah diterapkan atau dibatalkan, atau diproses bersamaan oleh proses lain).
     */
    public function apply(ScheduledPriceChange $change): ?int
    {
        return DB::transaction(function () use ($change) {
            $locked = ScheduledPriceChange::query()->whereKey($change->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isScheduled()) {
                return null;
            }

            $rows = $this->changes([
                'percent' => $locked->percent,
                'rounding_step' => $locked->rounding_step,
                'product_ids' => $locked->product_ids,
                'include_group_prices' => $locked->include_group_prices,
            ]);

            foreach ($rows as $row) {
                $row['model']->update([$row['column'] => $row['new']]);
            }

            $locked->update(['status' => ScheduledPriceChange::STATUS_APPLIED, 'applied_at' => now(), 'affected_prices' => $rows->count()]);

            return $rows->count();
        });
    }

    /**
     * Terapkan semua yang sudah jatuh tempo; kegagalan satu perubahan tidak menghentikan yang lain.
     *
     * @return array{applied: Collection<int, ScheduledPriceChange>, failures: array<int, string>}
     */
    public function runDue(): array
    {
        $applied = collect();
        $failures = [];

        foreach (ScheduledPriceChange::due()->orderBy('effective_date')->orderBy('id')->get() as $change) {
            try {
                if ($this->apply($change) !== null) {
                    $applied->push($change->refresh());
                }
            } catch (Throwable $e) {
                report($e);
                $failures[$change->id] = $e->getMessage();
            }
        }

        return ['applied' => $applied, 'failures' => $failures];
    }

    /**
     * @return Collection<int, array{model: Model, column: string, product: string, label: string, old: float, new: float}>
     */
    private function changes(array $params): Collection
    {
        $percent = (float) $params['percent'];
        if ($percent < self::MIN_PERCENT || $percent > self::MAX_PERCENT || abs($percent) < 0.005) {
            throw new RuntimeException('Persen harus antara ' . self::MIN_PERCENT . ' dan ' . self::MAX_PERCENT . ', dan tidak boleh nol.');
        }

        $step = (int) ($params['rounding_step'] ?? 0);
        $ids = array_values(array_filter((array) ($params['product_ids'] ?? [])));

        $products = FilmProduct::query()
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids), fn ($q) => $q->where('is_active', true))
            ->with(['prices'])
            ->when(! empty($params['include_group_prices']), fn ($q) => $q->with('groupPrices.customerGroup'))
            ->orderBy('name')
            ->get();

        $rows = collect();
        $consider = function (Model $model, string $column, FilmProduct $product, string $label) use ($rows, $percent, $step) {
            $old = (float) $model->{$column};
            if ($old <= 0) {
                return;
            }

            $new = self::newPrice($old, $percent, $step);
            if ($new <= 0 || abs($new - $old) < 0.005) {
                return;
            }

            $rows->push(['model' => $model, 'column' => $column, 'product' => $product->name, 'label' => $label, 'old' => $old, 'new' => $new]);
        };

        foreach ($products as $product) {
            $consider($product, 'base_price', $product, 'Harga dasar');

            foreach ($product->prices->sortBy('vehicle_size') as $price) {
                $consider($price, 'price', $product, 'Ukuran ' . $price->vehicle_size);
            }

            if (! empty($params['include_group_prices'])) {
                foreach ($product->groupPrices as $groupPrice) {
                    $consider($groupPrice, 'price', $product, 'Grup ' . ($groupPrice->customerGroup?->name ?? '#' . $groupPrice->customer_group_id));
                }
            }
        }

        return $rows->values();
    }
}
