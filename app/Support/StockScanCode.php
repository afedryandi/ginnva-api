<?php

namespace App\Support;

use App\Models\ConsumableItem;
use App\Models\RawMaterial;
use App\Services\QrCodeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

/**
 * Isi QR pada label rak/kemasan Bahan Baku dan Barang Habis Pakai, dan pembacaannya kembali saat staf gudang scan di mobile.
 * QR memuat Kode Barang bila bahan itu punya (kode internal yang sudah dipakai di gudang); bahan tanpa kode memakai penanda
 * RMID:{id} / CIID:{id} supaya tetap bisa dilabeli tanpa mengubah datanya.
 */
class StockScanCode
{
    public const TYPE_MATERIAL = 'raw_material';
    public const TYPE_CONSUMABLE = 'consumable_item';

    public static function for(RawMaterial|ConsumableItem $item): string
    {
        $code = trim((string) $item->code);

        if ($code !== '') {
            return $code;
        }

        return ($item instanceof RawMaterial ? 'RMID:' : 'CIID:') . $item->getKey();
    }

    /**
     * @return array{type: string, item: RawMaterial|ConsumableItem}|null
     */
    public static function resolve(string $scanned): ?array
    {
        $scanned = trim($scanned);

        if ($scanned === '' || mb_strlen($scanned) > 100) {
            return null;
        }

        if (preg_match('/^(RMID|CIID):(\d+)$/', $scanned, $m)) {
            $item = $m[1] === 'RMID' ? RawMaterial::find((int) $m[2]) : ConsumableItem::find((int) $m[2]);

            return $item ? ['type' => $m[1] === 'RMID' ? self::TYPE_MATERIAL : self::TYPE_CONSUMABLE, 'item' => $item] : null;
        }

        // Kode dicocokkan persis (tanpa beda huruf besar/kecil); bahan lebih dulu, lalu barang habis pakai.
        if ($item = RawMaterial::query()->where('code', $scanned)->first()) {
            return ['type' => self::TYPE_MATERIAL, 'item' => $item];
        }

        if ($item = ConsumableItem::query()->where('code', $scanned)->first()) {
            return ['type' => self::TYPE_CONSUMABLE, 'item' => $item];
        }

        return null;
    }

    /** PDF label QR (2 kolom, A4) untuk dicetak dan ditempel di rak atau kemasan. */
    public static function labelsPdf(Collection $items, string $title)
    {
        $rows = $items->map(fn (RawMaterial|ConsumableItem $item) => [
            'name' => $item->name,
            'meta' => trim(implode(' · ', array_filter([$item->category, $item->unit]))),
            'code' => $item->code ? (string) $item->code : self::for($item),
            'qr' => QrCodeService::generateDataUri(self::for($item), 260),
        ]);

        $pdf = Pdf::loadView('pdf.stock_qr_batch', ['items' => $rows, 'title' => $title])->setPaper('a4', 'portrait');

        return response()->streamDownload(fn () => print($pdf->output()), 'Label-' . str_replace(' ', '-', $title) . '-' . now()->format('Ymd-His') . '.pdf');
    }
}