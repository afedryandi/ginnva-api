<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingMessage;
use Illuminate\Http\Request;

class BookingMessageController extends Controller
{
    /**
     * GET /api/staff/bookings/{id}/messages
     */
    public function index(Request $request, int $bookingId)
    {
        $booking = $this->authorizedBooking($request, $bookingId);

        // Nested eager-load 'senderUser.store' — dibatch jadi 1 query
        // tambahan, supaya chatDisplayLabel() (butuh nama toko utk
        // staff toko) tidak N+1 per pesan.
        $messages = $booking->messages()->with(['senderUser.store:id,name', 'photos'])->get();

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

        $message = $booking->messages()->create([
            'sender_type'    => 'admin',
            'sender_user_id' => $user->id,
            'type'           => $request->type,
            'body'           => $request->body,
            'stage'          => $request->stage,
        ]);

        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $file) {
                $message->photos()->create([
                    'path' => $file->store('booking-messages', 'public'),
                ]);
            }
        }

        $message->load('senderUser.store:id,name', 'photos');

        return response()->json(['success' => true, 'data' => $this->transform($message)], 201);
    }

    private function authorizedBooking(Request $request, int $bookingId): Booking
    {
        $user = $request->user('api');
        $booking = Booking::findOrFail($bookingId);

        if ($user->hasRole('partner')) {
            abort(403, 'Partner tidak punya akses ke booking toko.');
        }

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
