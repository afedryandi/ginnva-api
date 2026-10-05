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
     * Customer tidak boleh menerima kolom internal: konfigurasi absensi/gaji
     * toko dan kolom akuntansi booking (2026-10-05). Daftar-hitam hanya di
     * jalur customer supaya API staff tidak berubah.
     */
    private function sanitize(Booking $booking): Booking
    {
        $booking->loadMissing('store');
        $booking->store?->makeHidden([
            'late_deduction_amount', 'late_tolerance_minutes', 'attendance_radius_meters',
            'install_capacity_per_day', 'detailing_slot_count', 'instalasi_qc_slot_count',
            'google_place_id',
        ]);

        return $booking->makeHidden([
            'journal_entry_id', 'partner_id', 'amount_received', 'dpp_amount', 'ppn_amount',
        ]);
    }

    /**
     * GET /api/customer/bookings
     * Daftar booking milik customer yang login (我的预约).
     */
    public function index(Request $request)
    {
        $query = $request->user('customer')
            ->bookings()
            ->with(['store', 'pendingRescheduleRequest', 'latestDecidedRescheduleRequest', 'pendingCancellationRequest', 'latestDecidedCancellationRequest'])
            // Pesan staff yang belum dibaca customer (badge kartu booking).
            ->withCount(['messages as unread_count' => fn ($q) => $q->where('sender_type', 'admin')->whereNull('read_by_customer_at')])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        // Segmen & paginasi (2026-10-06): ?segment=active (pending/confirmed) atau
        // history (selesai/batal) + ?page=. Tanpa parameter = daftar penuh
        // (kompatibel dengan versi app lama).
        if ($request->filled('segment')) {
            $request->segment === 'history'
                ? $query->whereIn('status', ['completed', 'cancelled'])
                : $query->whereIn('status', ['pending', 'confirmed']);
        }

        if ($request->filled('page') || $request->filled('segment')) {
            $page = $query->paginate(15);

            return response()->json([
                'success' => true,
                'data' => collect($page->items())->each(fn (Booking $b) => $this->sanitize($b))->values(),
                'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get()->each(fn (Booking $b) => $this->sanitize($b)),
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
            // Hanya toko aktif (2026-10-05).
            'store_id'          => 'required|exists:stores,id,is_active,1',
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

        // Pencegahan booking ganda (keputusan 2026-10-05): maksimal 3 booking
        // pending aktif per customer, dan tidak boleh duplikat persis (toko,
        // tanggal, layanan sama) dengan booking pending/confirmed miliknya.
        $customerId = $request->user('customer')->id;

        if (Booking::where('customer_id', $customerId)->where('status', 'pending')->count() >= 3) {
            return response()->json([
                'success' => false,
                'message' => 'Anda sudah punya 3 booking yang menunggu konfirmasi. Tunggu toko mengonfirmasi atau batalkan salah satunya dulu.',
            ], 422);
        }

        $duplicate = Booking::where('customer_id', $customerId)
            ->where('store_id', $request->store_id)
            ->whereDate('preferred_date', $request->preferred_date)
            ->where('service_type', $request->service_type)
            ->whereIn('status', ['pending', 'confirmed'])
            ->first();

        if ($duplicate) {
            return response()->json([
                'success' => false,
                'message' => "Anda sudah punya booking yang sama (#{$duplicate->booking_number}) di toko dan tanggal ini.",
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
            'data' => $this->sanitize($booking),
        ], 201);
    }

    /**
     * POST /api/customer/bookings/{id}/cancellation-request
     * Customer mengajukan pembatalan booking yang SUDAH dikonfirmasi (booking
     * pending dibatalkan langsung lewat cancel()). Alasan wajib; staff yang
     * memutuskan (keputusan 2026-10-05). Satu pengajuan menunggu per booking.
     */
    public function requestCancellation(Request $request, int $id)
    {
        $customer = $request->user('customer');
        $booking = $customer->bookings()->where('id', $id)->first();

        if (! $booking) {
            abort(404);
        }

        $request->validate(['reason' => 'required|string|max:500'], ['reason.required' => 'Alasan pembatalan wajib diisi.']);

        if ($booking->status !== 'confirmed') {
            abort(422, $booking->status === 'pending'
                ? 'Booking yang belum dikonfirmasi bisa langsung dibatalkan.'
                : 'Booking ini sudah selesai atau dibatalkan.');
        }

        if ($booking->preferred_date && $booking->preferred_date->lt(today())) {
            abort(422, 'Tanggal booking sudah lewat. Hubungi toko untuk tindak lanjut.');
        }

        $req = DB::transaction(function () use ($booking, $request, $customer) {
            $locked = Booking::where('id', $booking->id)->lockForUpdate()->first();

            if ($locked->status !== 'confirmed') {
                abort(422, 'Status booking sudah berubah, muat ulang halaman.');
            }

            if ($locked->cancellationRequests()->where('status', 'pending')->exists()) {
                abort(422, 'Sudah ada pengajuan pembatalan yang menunggu keputusan toko.');
            }

            return $locked->cancellationRequests()->create([
                'customer_id' => $customer->id,
                'reason'      => $request->reason,
                'status'      => 'pending',
            ]);
        });

        $storeId = $booking->store_id;
        $number = $booking->booking_number;
        $bookingId = $booking->id;
        try {
            app(\App\Services\PushNotificationService::class)->sendToStoreStaff(
                $storeId,
                'Pengajuan Pembatalan Booking',
                "Booking #{$number}: customer mengajukan pembatalan. Alasan: {$request->reason}",
                ['type' => 'booking_cancellation_request', 'booking_id' => $bookingId, 'route' => "/staff/bookings/{$bookingId}"],
                \App\Filament\Resources\BookingResource::class,
            );
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan pembatalan dikirim. Toko akan memberi keputusan.',
            'data'    => $req,
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

        if ($booking->preferred_date && $booking->preferred_date->lt(today())) {
            abort(422, 'Tanggal booking sudah lewat. Hubungi toko untuk tindak lanjut.');
        }

        if ($booking->hasWorkStarted()) {
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

            // Status dicek ulang di dalam lock (booking bisa saja baru dibatalkan/
            // diselesaikan sejak pengecekan di atas).
            if (! in_array($locked->status, ['pending', 'confirmed'], true) || $locked->hasWorkStarted()) {
                abort(422, 'Status booking sudah berubah, muat ulang halaman.');
            }

            if ($locked->rescheduleRequests()->where('status', 'pending')->exists()) {
                abort(422, 'Sudah ada pengajuan jadwal ulang yang menunggu keputusan toko.');
            }

            // Pembatalan lebih "kuat": selama pengajuan batal menunggu, jadwal
            // ulang tidak diterima supaya dua pengajuan tidak bertabrakan.
            if ($locked->cancellationRequests()->where('status', 'pending')->exists()) {
                abort(422, 'Pengajuan pembatalan booking ini sedang menunggu keputusan toko.');
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
                'data'    => $this->sanitize($locked->fresh()),
                'message' => 'Booking dibatalkan.',
            ]);
        });
    }
}
