<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\StoreReview;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Review internal (hybrid: sentimen + tag aspek + komentar opsional) —
 * TERPISAH dari review Google Maps. Kalau sentimen positif, mobile app
 * yang mengarahkan customer buat juga tulis review di Google (lihat
 * app/booking/[id]/chat.tsx) — endpoint ini cuma simpan versi internal.
 */
class StoreReviewController extends Controller
{
    /**
     * POST /api/customer/bookings/{id}/review
     * Requires: auth:customer
     */
    public function store(Request $request, int $bookingId)
    {
        $booking = Booking::where('id', $bookingId)
            ->where('customer_id', $request->user('customer')->id)
            ->firstOrFail();

        // Pakai current_stage, BUKAN status booking (status booking level
        // cuma berubah 'completed' lewat aksi terpisah "Tandai Selesai" di
        // staff app, sedangkan current_stage berubah 'completed' begitu
        // staff kirim update tahap "Serah Terima Unit" lewat chat — dua
        // aksi staff yang independen). Mobile app customer memunculkan
        // banner "beri review" berdasarkan current_stage juga (lihat
        // ginnva-mobile/app/booking/[id]/chat.tsx), jadi gate di sini
        // harus konsisten dengan itu supaya customer tidak melihat banner
        // tapi ditolak saat submit.
        if ($booking->current_stage !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Booking ini belum selesai, belum bisa direview.',
            ], 422);
        }

        if (StoreReview::where('booking_id', $booking->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Booking ini sudah pernah direview.',
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'sentiment'  => 'required|in:positive,neutral,negative',
            'tags'       => 'nullable|array',
            'tags.*'     => 'string|in:' . implode(',', array_keys(StoreReview::TAGS)),
            'comment'    => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Cek exists() di atas tidak cukup sendirian untuk cegah duplikat —
        // dua request submit yang hampir bersamaan (double-tap) bisa
        // dua-duanya lolos cek itu sebelum salah satu commit. Kolom
        // booking_id di tabel store_reviews sudah UNIQUE sebagai
        // pengaman terakhir; try-catch di sini cuma supaya request kedua
        // dapat respons 409 yang rapi, bukan 500 dari QueryException mentah.
        try {
            $review = StoreReview::create([
                'booking_id'  => $booking->id,
                'customer_id' => $booking->customer_id,
                'store_id'    => $booking->store_id,
                'sentiment'   => $request->sentiment,
                'tags'        => $request->tags ?? [],
                'comment'     => $request->comment,
            ]);
        } catch (QueryException $e) {
            if ((int) $e->getCode() === 23000) {
                return response()->json([
                    'success' => false,
                    'message' => 'Booking ini sudah pernah direview.',
                ], 409);
            }

            throw $e;
        }

        return response()->json([
            'success' => true,
            'message' => 'Terima kasih atas ulasan Anda.',
            'data'     => [
                'id'                  => $review->id,
                'sentiment'           => $review->sentiment,
                // Cuma sentimen positif yang diarahkan ke Google Maps —
                // biar mobile app tidak perlu duplikasi aturan ini sendiri.
                'suggest_google_review' => $review->sentiment === 'positive',
            ],
        ], 201);
    }
}
