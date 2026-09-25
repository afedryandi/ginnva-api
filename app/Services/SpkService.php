<?php

namespace App\Services;

use App\Models\Spk;
use App\Models\ScrollCodeUsage;
use Illuminate\Database\QueryException;
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
     *
     * f28 SENGAJA tidak dicek di sini: alur nyata di lapangan (createFromBooking(),
     * form Filament CreateSpk, dan endpoint mobile POST .../spks) selalu bikin SPK
     * BARU pada saat checked-in -- checked_out_at belum pernah terisi di titik ini,
     * "SPK ditandai selesai" baru terjadi belakangan lewat update(). Kalaupun ada
     * yang nekat mengirim checked_out_at langsung saat create, tidak ada kerugian
     * traceability nyata (SPK itu sendiri baru lahir, belum ada histori apa pun
     * untuk diaudit) -- jadi gate cukup di update() saja.
     */
    /**
     * BUG DIPERBAIKI 2026-09-25 (audit SPK): SEBELUMNYA pengecekan "booking
     * ini sudah punya SPK" cuma ada di createFromBooking(), sedangkan
     * Filament CreateSpk & endpoint mobile SpkController::store() sama-sama
     * memanggil create() ini LANGSUNG (bukan lewat createFromBooking()),
     * jadi lolos tanpa dicek sama sekali di 2 jalur nyata yang paling
     * sering dipakai. Constraint unik di DB (booking_id) tetap mencegah
     * data ganda tersimpan, TAPI pelanggarannya keluar sebagai
     * QueryException mentah (raw 500) — bukan pesan ramah — karena
     * pemanggil di Filament/mobile cuma menangkap RuntimeException.
     * Sekarang dicek DI SINI (satu-satunya jalur create SPK yang sebenarnya
     * dipakai semua pemanggil) supaya otomatis berlaku di mana pun, PLUS
     * transaction dibungkus try/catch QueryException sebagai jaring
     * pengaman terakhir untuk race condition murni (dua request nyaris
     * bersamaan lolos dari exists() check yang sama-sama masih true,
     * mis. double-tap tombol simpan saat koneksi lambat).
     */
    public function create(array $data, array $checklistItems, ?int $createdBy, ?array $damageMarks = null): Spk
    {
        if (! empty($data['booking_id']) && Spk::where('booking_id', $data['booking_id'])->exists()) {
            throw new RuntimeException('Booking ini sudah punya SPK.');
        }

        try {
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
        } catch (QueryException $e) {
            // 23000 = pelanggaran integrity constraint (unique/foreign key)
            // di MySQL/SQLite — kemungkinan besar booking_id bentrok (race
            // dua request) atau spk_number bentrok (lihat
            // Spk::generateNumberForStore(), masih pakai count()+1, bukan
            // pola do-while(...exists()) seperti Booking/Quotation — gap
            // terpisah, belum diperbaiki di sini). Pesan generik karena
            // tidak bisa dipastikan kolom mana yang bentrok tanpa parsing
            // teks error driver DB yang rapuh.
            if ((string) $e->getCode() === '23000') {
                throw new RuntimeException('SPK tidak bisa disimpan — booking ini kemungkinan sudah punya SPK (dibuat staff lain barusan). Muat ulang halaman lalu cek lagi.');
            }

            throw $e;
        }
    }

    /**
     * @param  array<int, array{category:string, label:string, is_checked:bool}>  $checklistItems
     * @param  ?array<int, array{x_percent:float, y_percent:float, code:string, note:?string}>  $damageMarks  null = tidak disentuh (lihat catatan di syncDamageMarks)
     */
    /**
     * @throws RuntimeException lihat assertBatchTrackingSatisfied() -- SPK
     *         belum boleh ditandai selesai kalau produk booking-nya wajib
     *         lacak roll (f28) tapi belum ada pemakaian roll tercatat.
     */
    public function update(Spk $spk, array $data, array $checklistItems, ?array $damageMarks = null): Spk
    {
        // Dicek DI LUAR transaction (murni SELECT, tidak ada write yang
        // perlu di-rollback kalau gagal) supaya pesan errornya keluar
        // secepat mungkin sebelum SPK & checklist-nya disentuh sama sekali.
        $this->assertBatchTrackingSatisfied($spk, $data);

        return DB::transaction(function () use ($spk, $data, $checklistItems, $damageMarks) {
            $spk->update($data);

            $this->syncChecklistItems($spk, $checklistItems);
            $this->syncDamageMarks($spk, $damageMarks);

            return $spk->fresh(['checklistItems', 'damageMarks']);
        });
    }

    /**
     * f28 "Toggle Batch Number PER PRODUK" -- traceability gap: staff bisa
     * menandai SPK selesai (checked_out_at) tanpa pernah mencatat roll mana
     * yang dipakai (ScrollCode::recordUsage() sepenuhnya independen dari
     * siklus SPK), jadi kalau ada recall roll cacat, booking yang lupa
     * dicatat tidak akan muncul di ScrollCode::usages()/warranties().
     *
     * Cek ini SENGAJA cuma jalan pas transisi checked_out_at null -> terisi
     * (momen "SPK ditandai selesai" yang sebenarnya) -- bukan tiap kali SPK
     * yang SUDAH selesai diedit ulang (mis. staff perbaiki typo catatan),
     * supaya tidak memblokir edit yang tidak relevan sama sekali dengan
     * pertanyaan "roll sudah dicatat atau belum".
     *
     * Sengaja ditaruh di SpkService (bukan diulang di EditSpk.php DAN
     * SpkController.php) karena keduanya funnel ke method update() yang
     * sama ini -- taruh sekali di sini otomatis berlaku utk kedua jalur.
     */
    private function assertBatchTrackingSatisfied(Spk $spk, array $data): void
    {
        $isCompleting = $spk->checked_out_at === null && ! empty($data['checked_out_at'] ?? null);

        if (! $isCompleting) {
            return;
        }

        $booking = $spk->booking;

        if (! $booking) {
            // SPK tanpa booking (seharusnya tidak mungkin lewat jalur resmi
            // createFromBooking(), tapi booking_id memang nullable di
            // fillable) -- tidak ada produk yang bisa dicek, biarkan lolos.
            return;
        }

        $filmProducts = collect([$booking->filmProduct])
            ->merge($booking->filmProducts->pluck('filmProduct'))
            ->filter();

        $requiresBatchTracking = $filmProducts->contains(fn ($product) => (bool) $product->tracks_batch);

        if (! $requiresBatchTracking) {
            return;
        }

        $hasRecordedUsage = ScrollCodeUsage::where('booking_id', $booking->id)->exists();

        if (! $hasRecordedUsage) {
            throw new RuntimeException('Pilih dulu roll yang dipakai (Catat Pemakaian di menu Kode Gulungan/Inventaris) sebelum SPK ini bisa ditandai selesai — produk pada booking ini wajib lacak roll.');
        }
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

        // Pengecekan "sudah punya SPK" SEKARANG ditegakkan di create()
        // (dipanggil di akhir method ini) — satu sumber kebenaran untuk
        // SEMUA jalur create SPK, bukan cuma jalur ini. Lihat catatan
        // lengkap di SpkService::create().

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
