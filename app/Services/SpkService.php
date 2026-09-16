<?php

namespace App\Services;

use App\Models\Spk;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Satu-satunya jalur resmi create/update SPK + checklist item-nya
 * (2026-09-16, digitalisasi form kertas Surat Perintah Kerja). V1
 * murni dokumen isi + cetak PDF -- lihat migrasi create_spks_table.
 */
class SpkService
{
    /**
     * @param  array{store_id:int, booking_id:int, customer_name:string, phone_number:?string, address:?string, vehicle_plate:?string, vehicle_vin:?string, vehicle_brand:?string, vehicle_year:?string, vehicle_type:?string, vehicle_km:?int, fuel_level:?string, battery_note:?string, checked_in_at:?string, checked_out_at:?string, notes:?string}  $data
     * @param  array<int, array{category:string, label:string, is_checked:bool}>  $checklistItems
     * @param  ?array<int, array{x_percent:float, y_percent:float, code:string, note:?string}>  $damageMarks  null = tidak disentuh (lihat catatan di syncDamageMarks)
     */
    public function create(array $data, array $checklistItems, ?int $createdBy, ?array $damageMarks = null): Spk
    {
        return DB::transaction(function () use ($data, $checklistItems, $createdBy, $damageMarks) {
            $spkNumber = Spk::generateNumberForStore($data['store_id']);

            $spk = Spk::create(array_merge($data, [
                'spk_number' => $spkNumber,
                'created_by' => $createdBy,
            ]));

            $this->syncChecklistItems($spk, $checklistItems);
            $this->syncDamageMarks($spk, $damageMarks);

            return $spk->fresh(['checklistItems', 'damageMarks']);
        });
    }

    /**
     * @param  array<int, array{category:string, label:string, is_checked:bool}>  $checklistItems
     * @param  ?array<int, array{x_percent:float, y_percent:float, code:string, note:?string}>  $damageMarks  null = tidak disentuh (lihat catatan di syncDamageMarks)
     */
    public function update(Spk $spk, array $data, array $checklistItems, ?array $damageMarks = null): Spk
    {
        return DB::transaction(function () use ($spk, $data, $checklistItems, $damageMarks) {
            $spk->update($data);

            $this->syncChecklistItems($spk, $checklistItems);
            $this->syncDamageMarks($spk, $damageMarks);

            return $spk->fresh(['checklistItems', 'damageMarks']);
        });
    }

    /**
     * Bikin 1 SPK dari sebuah Booking yang sudah 'confirmed', dengan
     * checklist bawaan (Spk::DEFAULT_CHECKLIST) sekaligus otomatis
     * dicentang sesuai flag produk booking-nya (product_ppf,
     * product_kaca_film) supaya staff tidak perlu ketik ulang data
     * yang sudah ada.
     *
     * @throws RuntimeException kalau booking belum confirmed, atau
     *         booking ini sudah punya SPK (1 booking = 1 SPK, sama
     *         aturan dengan MaterialMemo::booking_id unique).
     */
    public function createFromBooking(\App\Models\Booking $booking, ?int $createdBy): Spk
    {
        if ($booking->status !== 'confirmed') {
            throw new RuntimeException('SPK cuma bisa dibuat dari booking yang sudah dikonfirmasi.');
        }

        if (Spk::where('booking_id', $booking->id)->exists()) {
            throw new RuntimeException('Booking ini sudah punya SPK.');
        }

        $checklist = [];
        foreach (Spk::DEFAULT_CHECKLIST as $category => $labels) {
            foreach ($labels as $label) {
                $checklist[] = [
                    'category' => $category,
                    'label' => $label,
                    'is_checked' => $category === 'pekerjaan' && (
                        ($label === 'PPF' && $booking->product_ppf)
                        || ($label === 'Kaca Film' && $booking->product_kaca_film)
                    ),
                ];
            }
        }

        return $this->create([
            'store_id' => $booking->store_id,
            'booking_id' => $booking->id,
            'customer_name' => $booking->customer_name ?? $booking->customer?->name ?? '',
            'phone_number' => $booking->phone_number ?? $booking->customer?->phone_number,
            'checked_in_at' => now(),
        ], $checklist, $createdBy);
    }

    /**
     * Simpan titik kerusakan SAJA -- dipakai halaman "Kondisi Kendaraan"
     * tersendiri di mobile app (diminta user 2026-09-16: inspeksi
     * kendaraan dipisah jadi halaman baru, bukan menumpuk di form utama
     * SPK). Field lain (checklist, data kendaraan, dst.) tidak disentuh
     * sama sekali.
     *
     * @param  array<int, array{x_percent:float, y_percent:float, code:string, note:?string}>  $damageMarks
     */
    public function updateDamageMarksOnly(Spk $spk, array $damageMarks): Spk
    {
        $this->syncDamageMarks($spk, $damageMarks);

        return $spk->fresh('damageMarks');
    }

    /**
     * @param  array<int, array{category:string, label:string, is_checked:bool}>  $checklistItems
     */
    private function syncChecklistItems(Spk $spk, array $checklistItems): void
    {
        $spk->checklistItems()->delete();

        foreach ($checklistItems as $index => $item) {
            $spk->checklistItems()->create([
                'category' => $item['category'],
                'label' => $item['label'],
                'is_checked' => (bool) ($item['is_checked'] ?? false),
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * Titik kerusakan diagram kondisi kendaraan (Fase 2, diisi dari
     * mobile app) -- $damageMarks SENGAJA nullable, BEDA dari
     * checklistItems yang selalu di-replace penuh: Filament (EditSpk)
     * belum punya UI buat damage marks sama sekali, jadi kalau
     * parameter ini di-default array kosong biasa, tiap kali admin edit
     * SPK lewat Filament (field lain saja) titik-titik yang sudah
     * diinput staff dari lapangan bakal ikut terhapus tanpa sengaja.
     * null = jangan sentuh data yang sudah ada; array (termasuk kosong)
     * = replace penuh, ini yang dipakai mobile app.
     *
     * @param  ?array<int, array{x_percent:float, y_percent:float, code:string, note:?string}>  $damageMarks
     */
    private function syncDamageMarks(Spk $spk, ?array $damageMarks): void
    {
        if ($damageMarks === null) {
            return;
        }

        $spk->damageMarks()->delete();

        foreach ($damageMarks as $mark) {
            $spk->damageMarks()->create([
                'x_percent' => $mark['x_percent'],
                'y_percent' => $mark['y_percent'],
                'code' => $mark['code'],
                'note' => $mark['note'] ?? null,
            ]);
        }
    }
}
