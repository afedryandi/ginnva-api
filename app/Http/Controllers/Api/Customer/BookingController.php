<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class BookingController extends Controller
{
    /**
     * GET /api/customer/bookings
     * Daftar booking milik customer yang login (我的预约).
     */
    public function index(Request $request)
    {
        $bookings = $request->user('customer')
            ->bookings()
            ->with(['store', 'pendingRescheduleRequest', 'latestDecidedRescheduleRequest'])
            // Pesan staff yang belum dibaca customer (badge kartu booking).
            ->withCount(['messages as unread_count' => fn ($q) => $q->where('sender_type', 'admin')->whereNull('read_by_customer_at')])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $bookings,
        ]);
    }

    /**
     * POST /api/customer/bookings
     * Buat booking baru — wajib login (customer_id diambil dari token,
     * tidak dari input, supaya tidak bisa booking atas nama orang lain).
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'store_id'          => 'required|exists:stores,id',
            'service_type'      => 'required|string|max:255',
            'product_kaca_film' => 'sometimes|boolean',
            'product_ppf'       => 'sometimes|boolean',
            'preferred_date'    => 'required|date|after_or_equal:today',
            'preferred_time'    => 'nullable|string|max:50',
            // 'Lainnya' wajib dijelaskan (diperbaiki 2026-10-02) -- tanpa
            // catatan staff tidak tahu jasa apa yang diminta.
            'notes'             => 'required_if:service_type,Lainnya|nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Mobile app sudah nonaktifkan tanggal di hari toko tutup/diblokir
        // (lihat jam operasional & /api/stores/{id}/blocked-dates), tapi
        // itu cuma UI — ditegakkan lagi di sini supaya hit langsung ke
        // endpoint ini (atau race condition tanggal baru diblokir setelah
        // picker dibuka, atau versi app lama) tidak bisa lolos booking.
        // isClosedOn() cek DUA sumber sekaligus: libur rutin mingguan
        // (opening_hours) dan tanggal yang di-block manual (BlockedDate).
        $store = Store::find($request->store_id);

        if ($store?->isClosedOn(Carbon::parse($request->preferred_date))) {
            return response()->json([
                'success' => false,
                'message' => 'Tanggal yang dipilih sedang tidak tersedia untuk toko ini. Silakan pilih tanggal lain.',
                'errors'  => ['preferred_date' => ['Toko tutup/tanggal diblokir pada hari ini.']],
            ], 422);
        }

        // Tanggal yang kapasitasnya sudah penuh juga ditolak di server
        // (keputusan 2026-10-02) -- pemilih tanggal di app sudah
        // menonaktifkannya (/stores/{id}/full-dates), ini penjaga untuk
        // hit langsung/data basi. Hanya hari mulai yang dicek; pengecekan
        // seluruh durasi tetap saat staff mengonfirmasi.
        $preferred = Carbon::parse($request->preferred_date);
        if (Booking::confirmedOverlapCount((int) $request->store_id, $preferred) >= Booking::capacityForDate((int) $request->store_id, $preferred)) {
            return response()->json([
                'success' => false,
                'message' => 'Kapasitas toko pada tanggal itu sudah penuh. Silakan pilih tanggal lain.',
                'errors'  => ['preferred_date' => ['Tanggal penuh.']],
            ], 422);
        }

        $booking = Booking::create([
            'customer_id'       => $request->user('customer')->id,
            'store_id'          => $request->store_id,
            'service_type'      => $request->service_type,
            'product_kaca_film' => $request->boolean('product_kaca_film'),
            'product_ppf'       => $request->boolean('product_ppf'),
            'preferred_date'    => $request->preferred_date,
            'preferred_time'    => $request->preferred_time,
            'notes'             => $request->notes,
            'source'            => 'app',
            'status'            => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Booking berhasil diajukan. Toko akan menghubungi Anda untuk konfirmasi.',
            'data' => $booking,
        ], 201);
    }

    /**
     * POST /api/customer/bookings/{id}/reschedule-request
     * Customer mengajukan ganti tanggal (booking pending/confirmed miliknya);
     * staff yang memutuskan (keputusan 2026-10-02). Satu pengajuan menunggu
     * per booking. Tanggal penuh/toko tutup ditolak di sini juga supaya
     * pengajuan tidak sia-sia.
     */
    public function requestReschedule(Request $request, int $id)
    {
        $customer = $request->user('customer');
        $booking = $customer->bookings()->where('id', $id)->first();

        if (! $booking) {
            abort(404);
        }

        $request->validate([
            'requested_date' => 'required|date|after_or_equal:today',
            'reason'         => 'nullable|string|max:500',
        ]);

        if (! in_array($booking->status, ['pending', 'confirmed'], true)) {
            abort(422, 'Booking ini sudah selesai atau dibatalkan, jadwalnya tidak bisa diubah.');
        }

        if ($booking->current_stage) {
            abort(422, 'Pengerjaan booking ini sudah dimulai, jadwalnya tidak bisa diubah lagi.');
        }

        $date = Carbon::parse($request->requested_date);

        if ($booking->preferred_date?->toDateString() === $date->toDateString()) {
            abort(422, 'Tanggal yang diajukan sama dengan jadwal sekarang.');
        }

        if ($booking->store?->isClosedOn($date)) {
            abort(422, 'Toko tutup/libur atau tanggal diblokir pada tanggal itu. Pilih tanggal lain.');
        }

        if (Booking::confirmedOverlapCount((int) $booking->store_id, $date, $booking->id) >= Booking::capacityForDate((int) $booking->store_id, $date)) {
            abort(422, 'Kapasitas toko pada tanggal itu sudah penuh. Pilih tanggal lain.');
        }

        $req = DB::transaction(function () use ($booking, $request, $customer) {
            $locked = Booking::where('id', $booking->id)->lockForUpdate()->first();

            if ($locked->rescheduleRequests()->where('status', 'pending')->exists()) {
                abort(422, 'Sudah ada pengajuan jadwal ulang yang menunggu keputusan toko.');
            }

            return $locked->rescheduleRequests()->create([
                'customer_id'    => $customer->id,
                'requested_date' => $request->requested_date,
                'reason'         => $request->input('reason'),
                'status'         => 'pending',
            ]);
        });

        try {
            app(\App\Services\PushNotificationService::class)->sendToStoreStaff(
                $booking->store_id,
                'Pengajuan Jadwal Ulang',
                "Booking #{$booking->booking_number}: customer mengajukan jadwal ulang ke {$date->format('d M Y')}.",
                ['type' => 'booking_reschedule_request', 'booking_id' => $booking->id, 'route' => "/staff/bookings/{$booking->id}"],
                \App\Filament\Resources\BookingResource::class,
            );
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan jadwal ulang dikirim. Toko akan memberi keputusan.',
            'data'    => $req,
        ], 201);
    }

    /**
     * POST /api/customer/bookings/{id}/cancel
     * Batalkan booking milik sendiri — SEBELUMNYA tidak ada sama sekali,
     * customer terpaksa hubungi toko manual untuk batal. Lihat audit
     * modul Booking 2026-08-27.
     *
     * SENGAJA dibatasi ke status 'pending' saja — booking yang sudah
     * 'confirmed' berarti toko sudah mengalokasikan slot kapasitas
     * instalasi untuk tanggal itu (lihat Booking::fullDatesInRange()),
     * jadi pembatalan sepihak dari customer di titik ini bisa
     * menyebabkan toko kehilangan info kenapa slot tiba-tiba kosong.
     * Untuk booking yang sudah confirmed, customer tetap diarahkan
     * menghubungi toko langsung (staff yang cancel lewat Filament/app).
     */
    public function cancel(Request $request, int $id)
    {
        $customer = $request->user('customer');
        $booking = $customer->bookings()->where('id', $id)->first();

        if (! $booking) {
            abort(404);
        }

        $request->validate(['reason' => 'nullable|string|max:500']);

        return DB::transaction(function () use ($booking, $request) {
            $locked = Booking::where('id', $booking->id)->lockForUpdate()->first();

            if ($locked->status !== 'pending') {
                abort(422, "Booking berstatus \"{$locked->status}\" tidak bisa dibatalkan sendiri. Silakan hubungi toko langsung.");
            }

            $locked->cancelWith('customer', $locked->customer_id, $request->input('reason'));

            // Staff toko diberi tahu (diperbaiki 2026-10-02) -- sebelumnya
            // hanya customer yang menerima notifikasi perubahan status,
            // jadi staff tidak tahu slot yang kembali kosong.
            // Setelah commit (diperbaiki 2026-10-02) -- kalau refund DP di bawah
            // gagal dan transaksi rollback, staff tidak boleh sudah dapat push.
            $storeId = $locked->store_id;
            $bookingNumber = $locked->booking_number;
            $bookingId = $locked->id;
            DB::afterCommit(function () use ($storeId, $bookingNumber, $bookingId) {
                try {
                    app(\App\Services\PushNotificationService::class)->sendToStoreStaff(
                        $storeId,
                        'Booking Dibatalkan Customer',
                        "Booking #{$bookingNumber} dibatalkan oleh customer.",
                        ['type' => 'booking_cancelled_by_customer', 'booking_id' => $bookingId, 'route' => "/staff/bookings/{$bookingId}"],
                        \App\Filament\Resources\BookingResource::class,
                    );
                } catch (\Throwable $e) {
                    report($e);
                }
            });

            // Keputusan atasan 2026-09-19 (Topik 2, "Keputusan-PPN-DP-
            // Produk-Stok-Ginnva.docx"): DP dikembalikan PENUH kalau
            // booking dibatalkan -- no-op kalau tidak punya DP sama sekali.
            // userId null (bukan user Filament/staff) -- customer guard
            // terpisah, journal_entries.created_by nullable.
            app(\App\Services\DownPaymentService::class)
                ->refundAllOnCancellation($locked->id, null);

            return response()->json([
                'success' => true,
                'data'    => $locked->fresh(),
                'message' => 'Booking dibatalkan.',
            ]);
        });
    }
}
