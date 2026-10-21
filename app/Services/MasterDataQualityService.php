<?php

namespace App\Services;

use App\Models\FilmProduct;
use App\Models\Store;
use App\Models\Vehicle;
use App\Models\VehicleGlassArea;
use Illuminate\Support\Facades\DB;

/**
 * Kelengkapan master data (Master Data Fase 5, 2026-10-21): hitungan sederhana tentang apa yang masih kurang atau meragukan di master
 * data, supaya kekurangan terlihat sebelum menyebabkan harga atau booking error. Murni agregat baca-saja; tidak mengubah apa pun.
 *
 * Tiap pemeriksaan: kunci, bagian, label, jumlah yang bermasalah, total yang diperiksa, keparahan ('warn' = perlu ditindak, 'info' = baik
 * dilengkapi), dan catatan. Jumlah 0 berarti beres.
 */
class MasterDataQualityService
{
    /**
     * @return list<array{key: string, section: string, label: string, count: int, total: int, severity: string, note: string}>
     */
    public function checks(): array
    {
        $checks = [];
        $add = function (string $key, string $section, string $label, int $count, int $total, string $severity, string $note) use (&$checks) {
            $checks[] = compact('key', 'section', 'label', 'count', 'total', 'severity', 'note');
        };

        // ---- Kendaraan
        $activeVehicles = Vehicle::active();
        $activeWithModel = (clone $activeVehicles)->whereNotNull('model');

        $add('vehicle_no_model', 'Kendaraan', 'Kendaraan aktif tanpa tipe/model (hanya merek)', (clone $activeVehicles)->whereNull('model')->count(), (clone $activeVehicles)->count(), 'info',
            'Entri merek-saja tidak muncul di pilihan aplikasi sampai tipenya dilengkapi.');

        $add('vehicle_no_body', 'Kendaraan', 'Kendaraan aktif tanpa tipe bodi', (clone $activeWithModel)->whereNull('body_type')->count(), (clone $activeWithModel)->count(), 'info',
            'Tipe bodi membantu memeriksa kategori ukuran. Boleh dilengkapi bertahap.');

        $areaKeys = VehicleGlassArea::query()->get(['brand_key', 'model_key'])->mapWithKeys(fn ($a) => [$a->brand_key . '|' . $a->model_key => true]);
        $withoutArea = (clone $activeWithModel)->get(['brand', 'model'])
            ->filter(fn (Vehicle $v) => ! $areaKeys->has(VehicleGlassArea::keyOf((string) $v->brand) . '|' . VehicleGlassArea::keyOf((string) $v->model)))
            ->map(fn (Vehicle $v) => VehicleGlassArea::keyOf((string) $v->brand) . '|' . VehicleGlassArea::keyOf((string) $v->model))
            ->unique()->count();
        $modelsTotal = (clone $activeWithModel)->get(['brand', 'model'])->map(fn (Vehicle $v) => VehicleGlassArea::keyOf((string) $v->brand) . '|' . VehicleGlassArea::keyOf((string) $v->model))->unique()->count();

        $add('vehicle_no_area', 'Kendaraan', 'Merek+model aktif tanpa luas kaca', $withoutArea, $modelsTotal, 'info',
            'Luas kaca dipakai untuk kebutuhan bahan kaca film. Impor dari Google Sheet atau isi manual di menu Luas Kaca.');

        $conflicting = DB::table('vehicles')->where('is_active', true)->whereNotNull('model')->whereNull('variant')
            ->select('brand', 'model')->groupBy('brand', 'model')->havingRaw('COUNT(DISTINCT size_category) > 1')->get()->count();
        $add('vehicle_size_conflict', 'Kendaraan', 'Merek+model dengan lebih dari satu ukuran', $conflicting, $modelsTotal, 'warn',
            'Harga menjadi ambigu. Pilih satu ukuran yang benar dan nonaktifkan yang lain.');

        // ---- Luas kaca
        $areaTotal = VehicleGlassArea::count();
        $orphanAreas = VehicleGlassArea::query()->get(['id', 'brand_key', 'model_key'])
            ->filter(fn (VehicleGlassArea $a) => ! Vehicle::query()
                ->whereRaw('LOWER(TRIM(brand)) = ?', [$a->brand_key])
                ->whereRaw('LOWER(TRIM(model)) = ?', [$a->model_key])
                ->exists())
            ->count();
        $add('area_no_vehicle', 'Luas Kaca', 'Luas kaca tanpa kendaraan di katalog', $orphanAreas, $areaTotal, 'info',
            'Nama merek/tipe belum sama dengan menu Kendaraan. Samakan penulisannya atau tambahkan kendaraannya.');

        // ---- Toko
        $activeStores = Store::query()->where('is_active', true);
        $add('store_no_code', 'Toko', 'Toko aktif tanpa kode toko', (clone $activeStores)->whereNull('code')->count(), (clone $activeStores)->count(), 'info',
            'Kode singkat membedakan toko di laporan dan dokumen.');
        $add('store_no_coordinates', 'Toko', 'Toko aktif tanpa koordinat', (clone $activeStores)->where(fn ($q) => $q->whereNull('latitude')->orWhereNull('longitude'))->count(), (clone $activeStores)->count(), 'warn',
            'Koordinat dibutuhkan untuk absen berbasis lokasi.');
        $add('store_no_hours', 'Toko', 'Toko aktif tanpa jam buka', (clone $activeStores)->get(['id', 'opening_hours'])->filter(fn (Store $s) => empty($s->opening_hours))->count(), (clone $activeStores)->count(), 'warn',
            'Tanpa jam buka, kapasitas dan jadwal bergantung pada nilai bawaan.');
        $add('store_no_manager', 'Toko', 'Toko aktif tanpa penanggung jawab', (clone $activeStores)->whereNull('manager_user_id')->count(), (clone $activeStores)->count(), 'info',
            'Opsional, tetapi membantu mengetahui siapa yang dihubungi.');

        // ---- Produk
        $products = FilmProduct::query()->where('is_active', true)->with(['prices'])->get();
        $unpriced = $products->filter(fn (FilmProduct $p) => ! PriceCalculator::isPriced($p))->count();
        $add('product_unpriced', 'Produk', 'Produk film aktif tanpa harga', $unpriced, $products->count(), 'warn',
            'Produk tanpa harga tampil "Belum diisi" di kalkulator dan penawaran.');

        return $checks;
    }

    /** Ringkasan: jumlah pemeriksaan yang perlu ditindak (warn dengan count > 0). */
    public function attentionCount(?array $checks = null): int
    {
        return collect($checks ?? $this->checks())->filter(fn (array $c) => $c['severity'] === 'warn' && $c['count'] > 0)->count();
    }
}
