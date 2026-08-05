<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingMessage;
use App\Models\CustomerGalleryPhoto;
use App\Models\StoreReview;
use Illuminate\Http\Request;

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
        // utk staff toko) tidak N+1 per pesan.
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
                'store'             => $booking->store,
                // Supaya mobile app tahu kapan tampilkan banner
                // "beri review" — sekali sudah direview, jangan tampil lagi.
                'has_review'        => StoreReview::where('booking_id', $booking->id)->exists(),
            ],
        ]);
    }

    /**
     * GET /api/customer/my-gallery
     *
     * Gabungan 2 sumber foto:
     * 1. Galeri PERSONAL per-booking — auto-terbentuk dari foto yang
     *    dikirim admin toko lewat chat booking (BookingMessage.photo_path).
     * 2. Galeri "showcase" — di-upload staff langsung dari Filament lewat
     *    CustomerGalleryPhotoResource, TIDAK terikat booking tertentu,
     *    ditandai `is_featured` supaya bisa dipilih tampil di beranda.
     * Dua-duanya beda dari galeri publik (/api/case-studies) yang isinya
     * kurasi admin untuk showcase umum, tidak terikat 1 customer.
     */
    public function gallery(Request $request)
    {
        $customerId = $request->user('customer')->id;

        $bookings = Booking::where('customer_id', $customerId)
            ->with(['store:id,name', 'messages' => function ($q) {
                // whereHas photos ATAU masih punya photo_path lama (pesan
                // sebelum tabel booking_message_photos ada) — lihat catatan
                // migration-nya.
                $q->where(fn ($qq) => $qq->whereHas('photos')->orWhereNotNull('photo_path'))
                    ->with('photos')
                    ->orderBy('created_at');
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
            // 1 pesan tahap bisa punya beberapa foto — tiap foto jadi
            // entri galeri sendiri (flatten), bukan dikelompokkan per pesan.
            'photos'         => $b->messages->flatMap(function (BookingMessage $m) {
                $stageLabel = $m->stage ? (BookingMessage::allStages()[$m->stage] ?? $m->stage) : null;
                $photoUrls = $m->photoUrls();

                return collect($photoUrls)->map(fn (string $url, int $i) => [
                    // Offset per-index supaya id tetap unik walau 1 pesan
                    // punya beberapa foto (id BookingMessage dipakai berkali-kali).
                    'id'          => $m->id * 1000 + $i,
                    'url'         => $url,
                    'stage_label' => $stageLabel,
                    'created_at'  => $m->created_at,
                ]);
            })->values(),
        ])->values();

        $showcasePhotos = CustomerGalleryPhoto::where('customer_id', $customerId)
            ->where('is_featured', true)
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->get();

        if ($showcasePhotos->isNotEmpty()) {
            $data->push([
                'booking_id'     => null,
                'booking_number' => null,
                'service_type'   => 'Galeri Pilihan',
                'store_name'     => null,
                'preferred_date' => null,
                'photos'         => $showcasePhotos->map(fn (CustomerGalleryPhoto $p) => [
                    // Offset supaya id-nya tidak bentrok dengan id BookingMessage
                    // (tabel beda, auto-increment masing-masing mulai dari 1) —
                    // dipakai beranda untuk urutkan foto terbaru lintas sumber.
                    'id'          => 1000000000 + $p->id,
                    'url'         => BookingMessage::fullImageUrl($p->image),
                    'stage_label' => $p->caption,
                    'created_at'  => $p->created_at,
                ])->values(),
            ]);
        }

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

        $message->load('senderUser.store:id,name', 'photos');

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
            'stage_label' => $m->stage ? (BookingMessage::allStages()[$m->stage] ?? $m->stage) : null,
            'photo_urls'  => $m->photoUrls(),
            'created_at'  => $m->created_at,
        ];
    }
}
