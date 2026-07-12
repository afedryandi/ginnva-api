<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BookingMessageController extends Controller
{
    /**
     * GET /api/customer/bookings/{id}/messages
     * Dipakai untuk polling — mobile app refetch berkala (bukan
     * real-time/websocket, sesuai keputusan awal untuk versi pertama).
     */
    public function index(Request $request, int $bookingId)
    {
        $booking = Booking::where('id', $bookingId)
            ->where('customer_id', $request->user('customer')->id)
            ->with('store:id,name,google_place_id')
            ->firstOrFail();

        // Nested eager-load supaya chatDisplayLabel() (butuh nama toko
        // utk regional_admin) tidak N+1 per pesan.
        $messages = $booking->messages()->with('senderUser.store:id,name')->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'current_stage'   => $booking->current_stage,
                'stages'          => BookingMessage::STAGES,
                'messages'        => $messages->map(fn (BookingMessage $m) => $this->transform($m)),
                'store'           => $booking->store,
            ],
        ]);
    }

    /**
     * GET /api/customer/my-gallery
     *
     * Galeri pemasangan PERSONAL — beda dari galeri publik (/api/case-studies)
     * yang isinya kurasi admin untuk showcase umum. Ini murni foto yang
     * benar-benar dikirim admin toko ke booking milik customer yang login,
     * dikelompokkan per booking (booking tidak menyimpan data kendaraan
     * spesifik, jadi konteksnya pakai jenis layanan + toko + tanggal).
     */
    public function gallery(Request $request)
    {
        $bookings = Booking::where('customer_id', $request->user('customer')->id)
            ->with(['store:id,name', 'messages' => function ($q) {
                $q->whereNotNull('photo_path')->orderBy('created_at');
            }])
            ->orderByDesc('preferred_date')
            ->get()
            ->filter(fn (Booking $b) => $b->messages->isNotEmpty())
            ->values();

        $data = $bookings->map(fn (Booking $b) => [
            'booking_id'     => $b->id,
            'booking_number' => $b->booking_number,
            'service_type'   => $b->service_type,
            'store_name'     => $b->store?->name,
            'preferred_date' => $b->preferred_date,
            'photos'         => $b->messages->map(fn (BookingMessage $m) => [
                'id'          => $m->id,
                'url'         => $this->fullImageUrl($m->photo_path),
                'stage_label' => $m->stage ? (BookingMessage::STAGES[$m->stage] ?? $m->stage) : null,
                'created_at'  => $m->created_at,
            ])->values(),
        ]);

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * POST /api/customer/bookings/{id}/messages
     * Customer hanya bisa kirim pesan teks — foto & update tahap cuma
     * dari sisi admin toko, dikirim lewat mobile app juga (lihat
     * Api\Staff\BookingMessageController), bukan Filament.
     */
    public function store(Request $request, int $bookingId)
    {
        $request->validate([
            'body' => 'required|string|max:2000',
        ]);

        $booking = Booking::where('id', $bookingId)
            ->where('customer_id', $request->user('customer')->id)
            ->firstOrFail();

        $message = $booking->messages()->create([
            'sender_type'        => 'customer',
            'sender_customer_id' => $request->user('customer')->id,
            'type'               => 'text',
            'body'               => $request->body,
        ]);

        $message->load('senderUser.store:id,name');

        return response()->json([
            'success' => true,
            'data'    => $this->transform($message),
        ], 201);
    }

    private function transform(BookingMessage $m): array
    {
        return [
            'id'          => $m->id,
            'sender_type' => $m->sender_type,
            'sender_name' => $m->sender_type === 'admin' ? ($m->senderUser?->chatDisplayLabel() ?? 'Tim Ginnva') : null,
            'type'        => $m->type,
            'body'        => $m->body,
            'stage'       => $m->stage,
            'stage_label' => $m->stage ? (BookingMessage::STAGES[$m->stage] ?? $m->stage) : null,
            'photo_url'   => $this->fullImageUrl($m->photo_path),
            'created_at'  => $m->created_at,
        ];
    }

    private function fullImageUrl(?string $path): ?string
    {
        if (! $path) return null;

        $relative = Storage::url($path);

        if (str_starts_with($relative, 'http://') || str_starts_with($relative, 'https://')) {
            return $relative;
        }

        return rtrim(config('app.url'), '/') . '/' . ltrim($relative, '/');
    }
}
