<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingHandover;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Serah terima unit (Fase 3B pengerjaan ulang booking, 2026-10-18): petunjuk perawatan sudah disampaikan, tanda tangan BASAH
 * (di kertas) sudah diterima untuk PPF/kaca film, foto akhir opsional.
 *
 *  GET  /api/staff/bookings/{id}/handover  catatan serah terima (bila ada) + apa yang wajib untuk booking ini
 *  POST /api/staff/bookings/{id}/handover  catat serah terima (multipart bila ada foto); sekali per booking
 *
 * Tidak mengubah status booking: menyelesaikan booking tetap lewat /complete (yang, bila config booking.handover_required
 * menyala, mewajibkan catatan ini).
 */
class BookingHandoverController extends Controller
{
    public function show(Request $request, int $id)
    {
        $booking = $this->authorizeManage($request->user('api'), Booking::findOrFail($id));
        $handover = $booking->handover()->with('recorder:id,name')->first();

        return response()->json([
            'success' => true,
            'data' => [
                'handover' => $handover ? [
                    'care_instructions_given' => $handover->care_instructions_given,
                    'wet_signature_required' => $handover->wet_signature_required,
                    'wet_signature_received' => $handover->wet_signature_received,
                    'final_photo_url' => $handover->final_photo_path ? Storage::disk('public')->url($handover->final_photo_path) : null,
                    'notes' => $handover->notes,
                    'photo_consent' => (bool) $booking->photoConsent?->isActive(),
                    'recorded_by' => $handover->recorder?->name,
                    'handed_over_at' => $handover->handed_over_at?->toIso8601String(),
                ] : null,
                'wet_signature_required' => $booking->requiresWetSignature(),
                'can_record' => $this->recordBlocker($booking) === null,
                'cannot_record_reason' => $this->recordBlocker($booking),
                'required' => (bool) config('booking.handover_required'),
            ],
        ]);
    }

    public function store(Request $request, int $id)
    {
        $user = $request->user('api');
        $booking = $this->authorizeManage($user, Booking::findOrFail($id));

        $request->validate([
            'care_instructions_given' => 'required|boolean',
            'wet_signature_received' => 'required|boolean',
            'notes' => 'nullable|string|max:1000',
            'photo_consent' => 'nullable|boolean',
            'photo' => 'nullable|image|max:10240',
        ], [
            'photo.image' => 'File yang dipilih bukan gambar.',
            'photo.max' => 'Ukuran foto maksimal 10MB. Kompres atau pilih foto lain, lalu coba lagi.',
        ]);

        if (! $request->boolean('care_instructions_given')) {
            abort(422, 'Sampaikan petunjuk perawatan kepada pelanggan dulu, lalu centang.');
        }

        $signatureRequired = $booking->requiresWetSignature();

        if ($signatureRequired && ! $request->boolean('wet_signature_received')) {
            abort(422, 'Untuk PPF dan kaca film, tanda tangan basah serah terima wajib diterima dulu.');
        }

        $path = $request->hasFile('photo') ? $request->file('photo')->store('booking-handovers', 'public') : null;

        try {
            return DB::transaction(function () use ($request, $user, $booking, $signatureRequired, $path) {
                $locked = Booking::whereKey($booking->id)->lockForUpdate()->first();

                if ($reason = $this->recordBlocker($locked)) {
                    abort(422, $reason);
                }

                $handover = BookingHandover::create([
                    'booking_id' => $locked->id,
                    'recorded_by_user_id' => $user->id,
                    'care_instructions_given' => true,
                    'wet_signature_required' => $signatureRequired,
                    'wet_signature_received' => $request->boolean('wet_signature_received'),
                    'final_photo_path' => $path,
                    'notes' => $request->input('notes'),
                    'handed_over_at' => now(),
                ]);

                // Izin foto pekerjaan untuk pemasaran: opt-in, bawaan TIDAK ada izin (Marketing/Konten Fase 3).
                if ($request->boolean('photo_consent')) {
                    \App\Models\MarketingConsent::firstOrCreate(
                        ['booking_id' => $locked->id, 'scope' => \App\Models\MarketingConsent::SCOPE_JOB_PHOTOS],
                        ['customer_id' => $locked->customer_id, 'granted_at' => now(), 'recorded_by_user_id' => $user->id],
                    );
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Serah terima dicatat. Booking siap diselesaikan.',
                    'data' => ['id' => $handover->id, 'handed_over_at' => $handover->handed_over_at->toIso8601String()],
                ], 201);
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }

            throw $e;
        }
    }

    private function authorizeManage($user, Booking $booking): Booking
    {
        if ($user->hasRole('installer') || $user->hasRole('partner')) {
            abort(403, 'Anda tidak bisa mencatat serah terima.');
        }

        abort_unless($user->hasBookingAccess(), 403, 'Anda tidak punya akses menu Booking.');

        if (! $user->isFullAccess() && $booking->store_id !== $user->store_id) {
            abort(403, 'Anda tidak punya akses ke booking toko lain.');
        }

        return $booking;
    }

    /** Alasan serah terima BELUM bisa dicatat untuk booking ini, atau null kalau bisa. */
    private function recordBlocker(Booking $booking): ?string
    {
        if ($booking->handover()->exists()) {
            return 'Serah terima booking ini sudah dicatat.';
        }

        if ($booking->status !== 'confirmed') {
            return 'Serah terima hanya untuk booking yang sudah dikonfirmasi dan belum selesai.';
        }

        // Bila QC diwajibkan, kendaraan harus sudah lulus QC (siap diambil) sebelum diserahkan.
        if (config('booking.qc_required') && ! $booking->ready_at) {
            return 'Kendaraan belum lulus Quality Check, jadi belum bisa diserahkan.';
        }

        return null;
    }
}
