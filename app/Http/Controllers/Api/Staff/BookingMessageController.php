<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
        // regional_admin) tidak N+1 per pesan.
        $messages = $booking->messages()->with('senderUser.store:id,name')->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'current_stage' => $booking->current_stage,
                'stages'        => BookingMessage::STAGES,
                'messages'      => $messages->map(fn (BookingMessage $m) => $this->transform($m)),
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

        $request->validate([
            'type'  => 'required|in:' . implode(',', $allowedTypes),
            'body'  => 'nullable|string|max:2000',
            'stage' => 'required_if:type,stage|nullable|in:' . implode(',', array_keys(BookingMessage::STAGES)),
            'photo' => 'required_if:type,photo|nullable|image|max:5120',
        ]);

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('booking-messages', 'public');
        }

        $message = $booking->messages()->create([
            'sender_type'    => 'admin',
            'sender_user_id' => $user->id,
            'type'           => $request->type,
            'body'           => $request->body,
            'stage'          => $request->stage,
            'photo_path'     => $photoPath,
        ]);

        $message->load('senderUser.store:id,name');

        return response()->json(['success' => true, 'data' => $this->transform($message)], 201);
    }

    private function authorizedBooking(Request $request, int $bookingId): Booking
    {
        $user = $request->user('api');
        $booking = Booking::findOrFail($bookingId);

        if ($user->hasRole('installer')) {
            if ($booking->installer_user_id !== $user->id) {
                abort(403, 'Booking ini tidak ditugaskan ke Anda.');
            }
        } elseif (! $user->hasRole('super_admin') && $booking->store_id !== $user->store_id) {
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
