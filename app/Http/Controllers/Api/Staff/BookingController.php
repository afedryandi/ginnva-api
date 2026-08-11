<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Mail\BookingWatcherAssignedMail;
use App\Models\Booking;
use App\Models\User;
use App\Services\ServiceReminderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class BookingController extends Controller
{
    /**
     * GET /api/staff/bookings
     * Scoping per role:
     * - super_admin/direksi          : semua booking, semua toko.
     * - store_manager (& role divisi
     *   lain yang punya store_id)     : booking toko sendiri saja — sama
     *                                   persis scoping BookingResource di
     *                                   Filament, supaya datanya konsisten.
     * - installer (Tim Instalasi)    : HANYA booking yang dirinya termasuk
     *                                   salah satu installer yang di-assign
     *                                   (bisa lebih dari 1 installer per
     *                                   booking), bukan seluruh booking toko.
     */
    public function index(Request $request)
    {
        $user = $request->user('api');

        if ($user->hasRole('partner')) {
            abort(403, 'Partner tidak punya akses ke booking toko.');
        }

        $query = Booking::query()->with(['customer:id,name,phone_number', 'store:id,name'])
            ->orderByDesc('preferred_date');

        if ($user->hasRole('installer')) {
            $query->whereHas('installers', fn ($q) => $q->where('users.id', $user->id));
        } elseif (! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        // 'progress' = gabungan pending+confirmed (belum selesai), supaya
        // mobile app bisa kasih filter sederhana "Progress" vs "Selesai"
        // tanpa perlu tahu detail status internalnya satu-satu.
        if ($request->status === 'progress') {
            $query->whereIn('status', ['pending', 'confirmed']);
        } elseif ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $bookings = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $bookings->items(),
            'meta'    => [
                'current_page' => $bookings->currentPage(),
                'last_page'    => $bookings->lastPage(),
                'total'        => $bookings->total(),
            ],
        ]);
    }

    public function show(Request $request, int $id)
    {
        $user = $request->user('api');

        if ($user->hasRole('partner')) {
            abort(403, 'Partner tidak punya akses ke booking toko.');
        }

        $booking = Booking::with([
            'customer:id,name,phone_number',
            // install_capacity_per_day diikutkan supaya mobile app bisa
            // pre-fill default kapasitas saat approve (lihat modal
            // "Konfirmasi Booking" — nilainya tetap bisa diedit staff).
            'store:id,name,install_capacity_per_day',
            'installers:id,name',
            'watchers:id,name',
        ])->findOrFail($id);

        if ($user->hasRole('installer')) {
            if (! $booking->installers->contains('id', $user->id)) {
                abort(403, 'Booking ini tidak ditugaskan ke Anda.');
            }
        } elseif (! $user->isFullAccess() && $booking->store_id !== $user->store_id) {
            abort(403, 'Anda tidak punya akses ke booking toko lain.');
        }

        return response()->json(['success' => true, 'data' => $booking]);
    }

    /**
     * GET /api/staff/bookings/{id}/capacity-preview?duration_days=3
     *
     * Daftar tanggal (TERMASUK hari libur, ditandai closed=true) untuk
     * rentang lama pengerjaan tertentu — hari libur cuma ditampilkan
     * sebagai konteks (kenapa ada lompatan tanggal), tidak ikut divalidasi
     * kapasitas. Tanggal kerja disertai berapa slot sudah terpakai
     * (booking 'confirmed' lain) dan kapasitas default toko — dipakai
     * mobile app buat render 1 input kapasitas PER TANGGAL sebelum
     * approve (tim instalasi bisa beda jumlah tiap hari, mis. 1 tim masih
     * ngerjain mobil dari hari sebelumnya).
     */
    public function capacityPreview(Request $request, int $id)
    {
        $user = $request->user('api');
        $booking = $this->authorizeManage($user, Booking::findOrFail($id));

        $request->validate([
            'duration_days' => 'sometimes|integer|min:1|max:14',
        ]);

        $durationDays = $request->filled('duration_days')
            ? (int) $request->duration_days
            : ($booking->duration_days ?? $booking->effective_duration_days);

        $defaultCapacity = $booking->store?->install_capacity_per_day ?: 3;

        $walk = Booking::calendarWalkWithClosedDays($booking->store_id, $booking->preferred_date->copy(), $durationDays);

        $dates = collect($walk['dates'])->map(function (array $row) use ($booking, $defaultCapacity) {
            if ($row['closed']) {
                return ['date' => $row['date'], 'closed' => true];
            }

            return [
                'date'             => $row['date'],
                'closed'           => false,
                'used'             => Booking::confirmedOverlapCount($booking->store_id, \Illuminate\Support\Carbon::parse($row['date']), $booking->id),
                'default_capacity' => $defaultCapacity,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data'    => $dates,
            'meta'    => ['complete' => $walk['complete']],
        ]);
    }

    /**
     * POST /api/staff/bookings/{id}/confirm
     *
     * Approve booking dari 'pending' ke 'confirmed' langsung dari mobile
     * app — sebelumnya cuma bisa lewat Filament (BookingResource), store
     * manager sekarang bisa approve on the spot dari HP. Dicek kapasitas
     * slot instalasi toko dulu (Booking::fullDatesInRange()) — SAMA PERSIS
     * seperti pengecekan di Filament (CreateBooking/EditBooking) — supaya
     * jalur mobile ini tidak jadi celah buat lolos over-booking tim
     * instalasi. Installer & partner tidak boleh approve, sama seperti
     * assignment (lihat authorizeManage()).
     */
    public function confirm(Request $request, int $id)
    {
        $user = $request->user('api');
        $booking = $this->authorizeManage($user, Booking::findOrFail($id));

        $request->validate([
            // Staff boleh sesuaikan lama pengerjaan saat approve (mis. tahu
            // dari konsultasi customer ternyata butuh lebih/kurang dari
            // default) — sama seperti field "Lama Pengerjaan" di Filament.
            'duration_days'   => 'sometimes|integer|min:1|max:14',
            // Kapasitas PER TANGGAL (tim instalasi bisa beda-beda jumlahnya
            // tiap hari) — bukan 1 angka global lagi. Staff input manual
            // tiap approve (sama seperti Filament), TIDAK pernah jadi
            // setting tetap tersimpan.
            'capacities'            => 'required|array|min:1',
            'capacities.*.date'     => 'required|date',
            'capacities.*.capacity' => 'required|integer|min:1',
        ]);

        return DB::transaction(function () use ($booking, $request) {
            // Lock row booking-nya — dua staff dobel-tap "Konfirmasi"
            // nyaris bersamaan (mis. sinyal lemah, tap ulang karena
            // dikira gagal) tidak boleh dua-duanya lolos cek status
            // 'pending' sebelum salah satu commit.
            $locked = Booking::where('id', $booking->id)->lockForUpdate()->first();

            if ($locked->status !== 'pending') {
                abort(422, "Booking ini sudah berstatus \"{$locked->status}\", tidak bisa dikonfirmasi lagi.");
            }

            $durationDays = $request->filled('duration_days')
                ? (int) $request->duration_days
                : $locked->duration_days;

            $capacityByDate = collect($request->capacities)
                ->mapWithKeys(fn (array $row) => [\Illuminate\Support\Carbon::parse($row['date'])->toDateString() => (int) $row['capacity']])
                ->all();

            $fullDates = Booking::fullDatesInRange(
                $locked->store_id,
                $locked->preferred_date->copy(),
                $durationDays,
                $capacityByDate,
                excludeBookingId: $locked->id,
            );

            if (! empty($fullDates)) {
                abort(422, 'Kapasitas instalasi toko sudah penuh di tanggal: ' . implode(', ', $fullDates) . '. Pilih tanggal lain, atau selesaikan/batalkan booking lain yang bentrok dulu.');
            }

            $locked->update([
                'status'        => 'confirmed',
                'duration_days' => $durationDays,
            ]);

            return response()->json([
                'success' => true,
                'data'    => $locked->fresh(),
                'message' => 'Booking dikonfirmasi.',
            ]);
        });
    }

    /**
     * POST /api/staff/bookings/{id}/cancel
     *
     * Batalkan booking dari mobile app — sebelumnya cuma bisa lewat
     * Filament, staff toko harus buka admin panel dari browser buat
     * urusan operasional yang sebenarnya sering terjadi (customer minta
     * reschedule total, salah input). Bisa dari status 'pending' maupun
     * 'confirmed' — booking 'confirmed' yang dibatalkan otomatis melepas
     * slot kapasitasnya (Booking::confirmedOverlapCount() cuma hitung
     * status 'confirmed', jadi begitu status berubah slot langsung
     * kebuka lagi tanpa perlu proses tambahan). Installer & partner
     * tidak boleh, sama seperti assignment (lihat authorizeManage()).
     */
    public function cancel(Request $request, int $id)
    {
        $user = $request->user('api');
        $booking = $this->authorizeManage($user, Booking::findOrFail($id));

        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        return DB::transaction(function () use ($booking, $request) {
            $locked = Booking::where('id', $booking->id)->lockForUpdate()->first();

            if (in_array($locked->status, ['completed', 'cancelled'], true)) {
                abort(422, "Booking ini sudah berstatus \"{$locked->status}\", tidak bisa dibatalkan.");
            }

            $notes = $locked->notes;
            if ($request->filled('reason')) {
                $notes = trim(($notes ? $notes . "\n\n" : '') . "Dibatalkan: {$request->reason}");
            }

            $locked->update([
                'status' => 'cancelled',
                'notes'  => $notes,
            ]);

            return response()->json([
                'success' => true,
                'data'    => $locked->fresh(),
                'message' => 'Booking dibatalkan.',
            ]);
        });
    }

    /**
     * POST /api/staff/bookings/{id}/complete
     *
     * Ditandai staff toko saat customer selesai bayar di toko — MURNI
     * penanda status, tidak ada input apa pun lagi (tidak nominal
     * transaksi, kode referral, maupun voucher). Nominal transaksi & kode
     * referral partner sekarang diisi bareng lewat aksi "Proses Referral"
     * di Filament (BookingResource) saat memang dibutuhkan, dan voucher
     * fisik di-assign staff lewat VoucherResource — keduanya lepas total
     * dari penyelesaian booking di mobile app.
     */
    public function complete(Request $request, int $id)
    {
        $user = $request->user('api');

        if ($user->hasRole('partner') || $user->hasRole('installer')) {
            abort(403, 'Anda tidak punya akses untuk menyelesaikan booking.');
        }

        $booking = Booking::findOrFail($id);

        if (! $user->isFullAccess() && $booking->store_id !== $user->store_id) {
            abort(403, 'Anda tidak punya akses ke booking toko lain.');
        }

        $booking->update([
            'status'             => 'completed',
            'current_stage'      => 'completed',
        ]);

        return response()->json([
            'success' => true,
            'data'    => $booking->fresh(),
            'message' => 'Booking selesai.',
        ]);
    }

    /**
     * POST /api/staff/bookings/{id}/maintenance-reminder
     *
     * Trigger manual — store manager/direksi kirim pengingat
     * maintenance/servis berkala ke customer (WhatsApp+Push+Email) kapan
     * saja, biasanya begitu instalasi PPF/Kaca Film selesai, tanpa perlu
     * menunggu tanggal `next_service_reminder_at` terjadwal. Installer &
     * partner tidak boleh mengirim — sama seperti aturan complete().
     */
    public function sendMaintenanceReminder(Request $request, int $id, ServiceReminderService $reminders)
    {
        $user = $request->user('api');

        if ($user->hasRole('partner') || $user->hasRole('installer')) {
            abort(403, 'Anda tidak punya akses untuk mengirim pengingat maintenance.');
        }

        $booking = Booking::with(['customer', 'store'])->findOrFail($id);

        if (! $user->isFullAccess() && $booking->store_id !== $user->store_id) {
            abort(403, 'Anda tidak punya akses ke booking toko lain.');
        }

        if (! $booking->customer_id && ! $booking->phone_number) {
            abort(422, 'Booking ini tidak punya kontak customer untuk dikirimi pengingat.');
        }

        $results = $reminders->sendFor($booking, force: true);

        $sentChannels = array_keys(array_filter($results));

        return response()->json([
            'success' => true,
            'data'    => ['channels_sent' => $sentChannels],
            'message' => $sentChannels
                ? 'Pengingat maintenance terkirim lewat: ' . implode(', ', $sentChannels) . '.'
                : 'Pengingat gagal terkirim di semua kanal — periksa kontak customer & konfigurasi WhatsApp.',
        ]);
    }

    /**
     * GET /api/staff/bookings/{id}/assignable-staff
     * Daftar installer toko terkait (buat assign teknisi) + semua direksi
     * perusahaan (buat assign pemantau chat) — dipakai untuk isi picker di
     * mobile app.
     */
    public function assignableStaff(Request $request, int $id)
    {
        $user = $request->user('api');
        $booking = $this->authorizeManage($user, Booking::findOrFail($id));

        $installers = User::where('store_id', $booking->store_id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'installer'))
            ->orderBy('name')
            ->get(['id', 'name']);

        $direksi = User::whereHas('roles', fn ($q) => $q->where('name', 'direksi'))
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'data'    => [
                'installers' => $installers,
                'direksi'    => $direksi,
            ],
        ]);
    }

    /**
     * PUT /api/staff/bookings/{id}/installers
     * Sync (bukan append) daftar installer booking ini — boleh lebih dari 1
     * (mis. tim instalasi berdua untuk mobil besar).
     */
    public function assignInstallers(Request $request, int $id)
    {
        $user = $request->user('api');
        $booking = $this->authorizeManage($user, Booking::findOrFail($id));

        $request->validate([
            'installer_user_ids'   => 'present|array',
            'installer_user_ids.*' => 'integer|exists:users,id',
        ]);

        // Dedupe dulu — kalau tidak, ID yang sama terkirim 2x (mis. widget
        // multi-select di mobile yang tidak sengaja kirim duplikat) bikin
        // count($installerIds) lebih besar dari hasil whereIn() (yang
        // otomatis unik), jadi validasi di bawah gagal 422 padahal
        // payload-nya sebenarnya valid.
        $installerIds = array_values(array_unique($request->installer_user_ids));

        $validCount = User::whereIn('id', $installerIds)
            ->where('store_id', $booking->store_id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'installer'))
            ->count();

        if ($validCount !== count($installerIds)) {
            abort(422, 'Semua installer harus berasal dari toko ini.');
        }

        $existingIds = $booking->installers()->pluck('users.id')->all();

        $booking->installers()->sync($installerIds);

        // Pivot many-to-many tidak tertangkap LogsActivity — dicatat manual,
        // sama seperti assignWatchers().
        $existingSorted = collect($existingIds)->sort()->values()->all();
        $newSorted = collect($installerIds)->sort()->values()->all();
        if ($existingSorted !== $newSorted) {
            activity('booking')
                ->causedBy($user)
                ->performedOn($booking)
                ->withProperties([
                    'old'        => ['installer_ids' => $existingIds],
                    'attributes' => ['installer_ids' => $installerIds],
                ])
                ->log("Installer booking #{$booking->booking_number} diubah");
        }

        return response()->json(['success' => true, 'data' => $booking->fresh(['installers'])]);
    }

    /**
     * PUT /api/staff/bookings/{id}/watchers
     * Sync (bukan append) daftar direksi pemantau booking ini. Direksi yang
     * BARU ditambahkan dikirimi email pemberitahuan.
     */
    public function assignWatchers(Request $request, int $id)
    {
        $user = $request->user('api');
        $booking = $this->authorizeManage($user, Booking::findOrFail($id));

        $request->validate([
            'watcher_user_ids'   => 'present|array',
            'watcher_user_ids.*' => 'integer|exists:users,id',
        ]);

        $watcherIds = $request->watcher_user_ids;

        $validCount = User::whereIn('id', $watcherIds)
            ->whereHas('roles', fn ($q) => $q->where('name', 'direksi'))
            ->count();

        if ($validCount !== count($watcherIds)) {
            abort(422, 'Semua pemantau harus berasal dari akun Direksi.');
        }

        $existingIds = $booking->watchers()->pluck('users.id')->all();
        $newIds = array_diff($watcherIds, $existingIds);

        $booking->watchers()->sync($watcherIds);

        // Pivot many-to-many tidak tertangkap LogsActivity (yang cuma
        // melacak kolom langsung di tabel booking) — dicatat manual.
        // Dibandingkan dengan sort() dulu supaya urutan beda tidak dianggap
        // perubahan kalau isinya sama persis.
        $existingSorted = collect($existingIds)->sort()->values()->all();
        $newSorted = collect($watcherIds)->sort()->values()->all();
        if ($existingSorted !== $newSorted) {
            activity('booking')
                ->causedBy($user)
                ->performedOn($booking)
                ->withProperties([
                    'old'        => ['watcher_ids' => $existingIds],
                    'attributes' => ['watcher_ids' => $watcherIds],
                ])
                ->log("Pemantau (direksi) booking #{$booking->booking_number} diubah");
        }

        if (! empty($newIds)) {
            $newWatchers = User::whereIn('id', $newIds)->get(['id', 'name', 'email']);
            foreach ($newWatchers as $watcher) {
                if (! $watcher->email) continue;

                // Assignment-nya SUDAH tersimpan di atas (sync + activity
                // log) — kegagalan kirim email (mis. domain email watcher
                // tidak valid) tidak boleh bikin seluruh request gagal
                // (500) padahal assignment-nya sendiri berhasil.
                try {
                    Mail::to($watcher->email)->send(new BookingWatcherAssignedMail($booking, $user->name));
                } catch (\Exception $e) {
                    Log::error('Gagal mengirim email pemberitahuan watcher booking', [
                        'booking_id' => $booking->id,
                        'watcher_id' => $watcher->id,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }
        }

        return response()->json(['success' => true, 'data' => $booking->fresh(['watchers'])]);
    }

    private function authorizeManage($user, Booking $booking): Booking
    {
        if ($user->hasRole('installer') || $user->hasRole('partner')) {
            abort(403, 'Anda tidak bisa mengatur assignment booking.');
        }

        if (! $user->isFullAccess() && $booking->store_id !== $user->store_id) {
            abort(403, 'Anda tidak punya akses ke booking toko lain.');
        }

        return $booking;
    }
}
