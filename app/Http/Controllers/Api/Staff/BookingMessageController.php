<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BookingMessageController extends Controller
{
    // Batas kumulatif jumlah foto progress per booking (lintas semua
    // pesan) — cukup longgar untuk instalasi multi-hari dengan banyak
    // sudut foto, tapi mencegah storage abuse dari spam pesan berulang.
    private const MAX_PHOTOS_PER_BOOKING = 200;

    /**
     * GET /api/staff/bookings/{id}/messages
     */
    public function index(Request $request, int $bookingId)
    {
        $booking = $this->authorizedBooking($request, $bookingId);

        // Nested eager-load 'senderUser.store' — dibatch jadi 1 query
        // tambahan, supaya chatDisplayLabel() (butuh nama toko utk
        // staff toko) tidak N+1 per pesan.
        $messagesQuery = $booking->messages()->with(['senderUser.store:id,name', 'photos']);
        // Polling incremental: ?after_id=N hanya mengembalikan pesan baru.
        if ($request->filled('after_id')) {
            $messagesQuery->where('booking_messages.id', '>', (int) $request->after_id);
        }
        $messages = $messagesQuery->get();

        // Pesan customer dianggap sudah dibaca staff begitu chat dibuka
        // (badge belum dibaca, 2026-10-02).
        // Dicatat PER STAFF (keputusan 2026-10-03) & hanya menulis kalau ada
        // pesan yang benar-benar belum dibaca user ini (poll 5 dtk tidak
        // lagi memicu UPDATE terus-menerus).
        $reader = $request->user('api');
        $unreadIds = $booking->messages()
            ->where('sender_type', 'customer')
            ->where('legacy_read', false)
            ->whereDoesntHave('reads', fn ($r) => $r->where('user_id', $reader->id))
            ->pluck('booking_messages.id');

        if ($unreadIds->isNotEmpty()) {
            \App\Models\BookingMessageRead::insertOrIgnore(
                $unreadIds->map(fn ($id) => ['booking_message_id' => $id, 'user_id' => $reader->id, 'read_at' => now()])->all()
            );
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'current_stage'     => $booking->current_stage,
                'secondary_stage'   => $booking->secondary_stage,
                'product_kaca_film' => $booking->product_kaca_film,
                'product_ppf'       => $booking->product_ppf,
                'stages'            => BookingMessage::allStages(),
                'product_stages'    => BookingMessage::PRODUCT_STAGES,
                'shared_stages'     => BookingMessage::SHARED_STAGES,
                'messages'          => $messages->map(fn (BookingMessage $m) => $this->transform($m)),
            ],
        ]);
    }

    /**
     * POST /api/staff/bookings/{id}/messages
     * multipart/form-data karena bisa menyertakan foto. Satu pesan bisa
     * berupa teks biasa, foto progress, atau update tahap (yang boleh
     * disertai foto juga) — sesuai keputusan: tahap tetap + boleh ada
     * beberapa foto per tahap.
     */
    public function store(Request $request, int $bookingId)
    {
        $user = $request->user('api');
        $booking = $this->authorizedBooking($request, $bookingId);

        // Installer HANYA boleh chat teks — foto & update tahap adalah
        // wewenang Store Manager/Direksi (kontrol kualitas apa yang
        // sampai ke customer).
        $allowedTypes = $user->hasRole('installer') ? ['text'] : ['text', 'photo', 'stage'];

        // Tahap yang boleh dipilih dibatasi ke produk yang BENERAN dipesan
        // booking ini (+ tahap bersama) — supaya staff tidak bisa keliru
        // update tahap PPF di booking yang cuma pesan Kaca Film, dst.
        $allowedStages = BookingMessage::SHARED_STAGES;
        if ($booking->product_kaca_film) {
            $allowedStages += BookingMessage::PRODUCT_STAGES['kaca_film'];
        }
        if ($booking->product_ppf) {
            $allowedStages += BookingMessage::PRODUCT_STAGES['ppf'];
        }

        $request->validate([
            'type'     => 'required|in:' . implode(',', $allowedTypes),
            'body'     => 'nullable|string|max:2000',
            'stage'    => 'required_if:type,stage|nullable|in:' . implode(',', array_keys($allowedStages)),
            // Boleh lebih dari 1 foto per tahap (mis. beberapa sudut mobil)
            // — required_if berlaku untuk type=photo, tapi type=stage tetap
            // boleh SERTAKAN foto (opsional), lihat validasi manual di bawah.
            'photos'   => 'required_if:type,photo|nullable|array|min:1|max:10',
            'photos.*' => 'image|max:10240',
        ], [
            'type.required'  => 'Jenis pesan wajib diisi.',
            'type.in'        => 'Anda tidak punya izin untuk jenis pesan ini.',
            'body.max'       => 'Pesan maksimal 2000 karakter.',
            'stage.required_if' => 'Tahap wajib dipilih.',
            'stage.in'       => 'Tahap yang dipilih tidak valid.',
            'photos.required_if' => 'Foto wajib dilampirkan.',
            'photos.max'     => 'Maksimal 10 foto sekaligus.',
            'photos.*.image' => 'File yang dipilih bukan gambar.',
            'photos.*.max'   => 'Ukuran tiap foto maksimal 10MB. Kompres atau pilih foto lain, lalu coba lagi.',
        ]);

        // Installer hanya boleh chat teks: foto juga wewenang Store Manager/
        // Direksi (sebelumnya lolos lewat type=text + photos[]). Pesan teks
        // tidak boleh kosong (sebelumnya tetap dibuat & memicu push).
        if ($user->hasRole('installer') && $request->hasFile('photos')) {
            abort(422, 'Installer hanya boleh mengirim pesan teks.');
        }

        if ($request->type === 'text' && blank(trim((string) $request->body)) && ! $request->hasFile('photos')) {
            abort(422, 'Pesan tidak boleh kosong.');
        }

        // Guard status booking (diperbaiki 2026-10-02, audit alur Booking) --
        // SEBELUMNYA pesan tahap/foto bisa dikirim ke booking apa pun, dan
        // customer dapat push "Update Progress" untuk booking yang batal
        // atau belum dikonfirmasi (pesan tahap juga mengubah current_stage).
        if ($booking->status === 'cancelled') {
            abort(422, 'Booking ini sudah dibatalkan, tidak bisa mengirim pesan.');
        }

        if ($booking->status === 'pending' && in_array($request->type, ['stage', 'photo'], true)) {
            abort(422, 'Konfirmasi booking dulu sebelum mengirim update tahap atau foto.');
        }

        $this->assertStageOrder($booking, $request);

        // Booking HANYA selesai lewat /complete (2026-10-03): tahap "completed"
        // dibuat otomatis oleh BookingObserver saat status jadi completed.
        // Dikirim manual lewat chat, ia mengisi current_stage tanpa mengubah
        // status -> customer dapat push "Selesai" padahal booking belum.
        if ($request->type === 'stage' && $request->stage === 'completed') {
            abort(422, 'Gunakan tombol "Selesaikan Booking" untuk menyelesaikan booking.');
        }

        if ($booking->status === 'completed' && $request->type === 'stage' && $request->stage !== 'completed') {
            abort(422, 'Booking ini sudah selesai, tahap tidak bisa diubah lagi.');
        }

        // "Quality Check" cuma boleh ditandai kalau track produk yang
        // BENERAN dipesan booking ini sudah sama-sama sampai tahap
        // terakhirnya sendiri — SEBELUMNYA tidak divalidasi sama sekali
        // di sini (cuma dicegah dari sisi UI mobile, yang sendirinya
        // ternyata juga tidak mengecek ini sampai audit chat & foto
        // tahap booking 2026-09-07 menemukannya). Tanpa ini, panggilan
        // API langsung (atau bug UI lain di masa depan) bisa menandai QC
        // — dan berujung "Selesai" — padahal salah satu produk (mis. PPF
        // di booking Kaca Film+PPF) belum benar-benar selesai dikerjakan.
        if ($request->type === 'stage' && $request->stage === 'qc' && ($qcBlocker = $booking->qualityCheckBlocker())) {
            abort(422, $qcBlocker);
        }

        // Total ukuran satu kiriman dibatasi (< post_max_size server): 10 foto x
        // 10MB bisa melewati batas request dan gagal tanpa pesan jelas.
        $totalBytes = collect($request->file('photos', []))->sum(fn ($f) => $f->getSize());
        if ($totalBytes > 50 * 1024 * 1024) {
            abort(422, 'Total ukuran foto terlalu besar (maks 50MB per kiriman). Kirim lebih sedikit foto sekaligus.');
        }

        // Batas kumulatif foto per booking — SEBELUMNYA cuma dibatasi per
        // pesan (max 10), jadi pesan berulang-ulang tetap bisa menumpuk
        // ratusan foto tak terbatas per booking (storage abuse). Lihat
        // audit modul Booking 2026-08-27.
        $newPhotoCount = $request->hasFile('photos') ? count($request->file('photos')) : 0;
        if ($newPhotoCount > 0) {
            $existingCount = BookingMessage::photoCountForBooking($booking->id);
            if ($existingCount + $newPhotoCount > self::MAX_PHOTOS_PER_BOOKING) {
                abort(422, 'Booking ini sudah mencapai batas maksimal ' . self::MAX_PHOTOS_PER_BOOKING . ' foto progress. Hapus/kurangi foto lama lewat Filament kalau memang perlu menambah.');
            }
        }

        // File disimpan DULU, pesan + foto dibuat atomik, dan push (observer,
        // afterCommit) baru terkirim setelah foto terlampir. Kalau gagal, file
        // yang sudah tersimpan dihapus -- tidak ada pesan kosong/dobel/yatim.
        $paths = [];
        foreach ($request->file('photos', []) as $file) {
            $paths[] = $file->store('booking-messages', 'public');
        }

        try {
            $message = DB::transaction(function () use ($booking, $user, $request, $paths) {
                // Cek urutan tahap diulang di bawah lock baris booking: dua ketukan
                // tahap yang sama bersamaan tidak boleh sama-sama lolos.
                $lockedBooking = Booking::whereKey($booking->id)->lockForUpdate()->first();
                $this->assertStageOrder($lockedBooking, $request);

                $message = $booking->messages()->create([
                    'sender_type'    => 'admin',
                    'sender_user_id' => $user->id,
                    'type'           => $request->type,
                    'body'           => $request->body,
                    'stage'          => $request->stage,
                ]);

                foreach ($paths as $path) {
                    $message->photos()->create(['path' => $path]);
                }

                return $message;
            });
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($paths);

            throw $e;
        }

        $message->load('senderUser.store:id,name', 'photos');

        return response()->json(['success' => true, 'data' => $this->transform($message)], 201);
    }

    /**
     * Urutan tahap MAJU saja (keputusan 2026-10-06): tahap yang sudah lewat/sama
     * dengan tahap sekarang di track-nya ditolak. Salah tandai dikoreksi Store
     * Manager lewat /stage-correction.
     */
    private function assertStageOrder(Booking $booking, Request $request): void
    {
        if ($request->type !== 'stage' || ! $request->stage) {
            return;
        }

        $bothProducts = $booking->product_kaca_film && $booking->product_ppf;
        $column = Booking::stageColumnFor($bothProducts, $request->stage);
        $sequence = $booking->stageSequenceFor($column);
        $newIdx = array_search($request->stage, $sequence, true);
        $currentIdx = $booking->{$column} ? array_search($booking->{$column}, $sequence, true) : false;

        if ($newIdx === false) {
            abort(422, 'Tahap tidak sesuai dengan produk booking ini.');
        }

        if ($currentIdx !== false && $newIdx <= $currentIdx) {
            abort(422, 'Tahap ini sudah ditandai atau sudah terlewati. Minta Store Manager mengoreksi kalau salah tandai.');
        }
    }

    private function authorizedBooking(Request $request, int $bookingId): Booking
    {
        $user = $request->user('api');
        $booking = Booking::findOrFail($bookingId);

        if ($user->hasRole('partner')) {
            abort(403, 'Partner tidak punya akses ke booking toko.');
        }

        // Konsisten dengan modul lain & filter notifikasi: staff toko wajib
        // punya akses menu Booking (installer yang ditugaskan tetap boleh).
        abort_unless($user->hasBookingAccess(), 403, 'Anda tidak punya akses menu Booking.');

        if ($user->hasRole('installer')) {
            if (! $booking->installers()->where('user_id', $user->id)->exists()) {
                abort(403, 'Booking ini tidak ditugaskan ke Anda.');
            }
        } elseif (! $user->isFullAccess() && $booking->store_id !== $user->store_id) {
            abort(403, 'Anda tidak punya akses ke booking toko lain.');
        }

        return $booking;
    }

    private function transform(BookingMessage $m): array
    {
        return [
            'id'          => $m->id,
            'sender_type' => $m->sender_type,
            'sender_name' => $m->sender_type === 'admin' ? ($m->senderUser?->chatDisplayLabel() ?? 'Tim Ginnva') : 'Customer',
            'type'        => $m->type,
            'body'        => $m->body,
            'stage'       => $m->stage,
            'stage_label' => $m->stage ? (BookingMessage::allStages()[$m->stage] ?? $m->stage) : null,
            'photo_urls'  => $m->photoUrls(),
            'created_at'  => $m->created_at,
        ];
    }
}
