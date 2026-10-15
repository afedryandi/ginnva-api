<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingIntakePhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Foto penerimaan kendaraan (Fase 3C pengerjaan ulang booking, 2026-10-19; keputusan pemilik: 4 sudut baku + foto kerusakan
 * tambahan bila ada). Bukti kondisi awal sebelum pengerjaan.
 *
 *  GET    /api/staff/bookings/{id}/intake-photos        status tiap sudut + foto kerusakan + apakah sudah lengkap
 *  POST   /api/staff/bookings/{id}/intake-photos        unggah satu foto (angle=depan|belakang|kiri|kanan|kerusakan); sudut baku menggantikan foto lamanya
 *  DELETE /api/staff/bookings/{id}/intake-photos/{photoId}  hapus satu foto kerusakan
 */
class BookingIntakeController extends Controller
{
    public function index(Request $request, int $id)
    {
        $booking = $this->authorizeManage($request->user('api'), Booking::findOrFail($id));

        return response()->json(['success' => true, 'data' => $this->payload($booking)]);
    }

    public function store(Request $request, int $id)
    {
        $user = $request->user('api');
        $booking = $this->authorizeManage($user, Booking::findOrFail($id));

        $request->validate([
            'angle' => 'required|in:' . implode(',', array_merge(array_keys(BookingIntakePhoto::ANGLES), [BookingIntakePhoto::DAMAGE])),
            'photo' => 'required|image|max:10240',
            'note' => 'nullable|string|max:255',
        ], [
            'angle.in' => 'Sudut foto tidak valid.',
            'photo.required' => 'Foto wajib dilampirkan.',
            'photo.image' => 'File yang dipilih bukan gambar.',
            'photo.max' => 'Ukuran foto maksimal 10MB. Kompres atau pilih foto lain, lalu coba lagi.',
        ]);

        if ($booking->status !== 'confirmed') {
            abort(422, 'Foto penerimaan hanya untuk booking yang sudah dikonfirmasi dan belum selesai.');
        }

        $angle = $request->input('angle');

        if ($angle === BookingIntakePhoto::DAMAGE && blank($request->input('note'))) {
            abort(422, 'Foto kerusakan wajib diberi keterangan (bagian mana yang rusak).');
        }

        $path = $request->file('photo')->store('booking-intake', 'public');

        try {
            DB::transaction(function () use ($booking, $user, $angle, $path, $request) {
                $locked = Booking::whereKey($booking->id)->lockForUpdate()->first();

                if ($angle === BookingIntakePhoto::DAMAGE) {
                    if ($locked->intakePhotos()->where('angle', BookingIntakePhoto::DAMAGE)->count() >= BookingIntakePhoto::MAX_DAMAGE_PHOTOS) {
                        abort(422, 'Maksimal ' . BookingIntakePhoto::MAX_DAMAGE_PHOTOS . ' foto kerusakan per booking.');
                    }
                } else {
                    // Sudut baku: satu foto per sudut; foto baru menggantikan yang lama.
                    $old = $locked->intakePhotos()->where('angle', $angle)->get();
                    foreach ($old as $photo) {
                        Storage::disk('public')->delete($photo->path);
                        $photo->delete();
                    }
                }

                $locked->intakePhotos()->create([
                    'angle' => $angle,
                    'path' => $path,
                    'note' => $request->input('note'),
                    'taken_by_user_id' => $user->id,
                ]);
            });
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($path);

            throw $e;
        }

        return response()->json(['success' => true, 'data' => $this->payload($booking->fresh())], 201);
    }

    public function destroy(Request $request, int $id, int $photoId)
    {
        $booking = $this->authorizeManage($request->user('api'), Booking::findOrFail($id));

        $photo = $booking->intakePhotos()->whereKey($photoId)->firstOrFail();

        if ($photo->angle !== BookingIntakePhoto::DAMAGE) {
            abort(422, 'Foto sudut baku tidak dihapus; ambil ulang untuk menggantinya.');
        }

        if ($booking->status !== 'confirmed') {
            abort(422, 'Foto penerimaan hanya bisa diubah pada booking yang belum selesai.');
        }

        Storage::disk('public')->delete($photo->path);
        $photo->delete();

        return response()->json(['success' => true, 'data' => $this->payload($booking->fresh())]);
    }

    private function payload(Booking $booking): array
    {
        $photos = $booking->intakePhotos()->orderBy('id')->get();

        $angles = collect(BookingIntakePhoto::ANGLES)->map(function (string $label, string $key) use ($photos) {
            $photo = $photos->firstWhere('angle', $key);

            return ['key' => $key, 'label' => $label, 'photo_url' => $photo?->url(), 'taken_at' => $photo?->created_at?->toIso8601String()];
        })->values();

        return [
            'angles' => $angles,
            'damages' => $photos->where('angle', BookingIntakePhoto::DAMAGE)->map(fn (BookingIntakePhoto $p) => [
                'id' => $p->id, 'photo_url' => $p->url(), 'note' => $p->note,
            ])->values(),
            'complete' => $angles->every(fn (array $a) => $a['photo_url'] !== null),
            'can_edit' => $booking->status === 'confirmed',
        ];
    }

    private function authorizeManage($user, Booking $booking): Booking
    {
        if ($user->hasRole('installer') || $user->hasRole('partner')) {
            abort(403, 'Anda tidak bisa mengelola foto penerimaan.');
        }

        abort_unless($user->hasBookingAccess(), 403, 'Anda tidak punya akses menu Booking.');

        if (! $user->isFullAccess() && $booking->store_id !== $user->store_id) {
            abort(403, 'Anda tidak punya akses ke booking toko lain.');
        }

        return $booking;
    }
}
