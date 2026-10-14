<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingQcResult;
use App\Models\BookingServiceType;
use App\Models\QcChecklistItem;
use App\Services\PushNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Quality Check dengan daftar periksa (Fase 3A pengerjaan ulang booking, 2026-10-17; keputusan pemilik: pemeriksa adalah
 * Leader/Store Manager (atau akses penuh) dan BUKAN pengerja unit itu).
 *
 *  GET  /api/staff/bookings/{id}/qc  daftar periksa per layanan + hasil terbaru + apakah akun ini boleh memeriksa
 *  POST /api/staff/bookings/{id}/qc  kirim hasil; lulus bila semua butir wajib lulus -> bookings.ready_at terisi + push ke pelanggan
 *
 * Tidak mengubah status booking atau tahap: menyelesaikan booking tetap lewat /complete (yang, bila config
 * booking.qc_required menyala, mewajibkan hasil terbaru lulus).
 */
class BookingQcController extends Controller
{
    public function show(Request $request, int $id)
    {
        $booking = Booking::findOrFail($id);
        $this->authorizeView($request->user('api'), $booking);

        $latest = $booking->qcResults()->with(['inspector:id,name', 'items'])->first();

        return response()->json([
            'success' => true,
            'data' => [
                'services' => $this->checklistFor($booking),
                'latest' => $latest ? [
                    'passed' => $latest->passed,
                    'inspector' => $latest->inspector?->name,
                    'notes' => $latest->notes,
                    'created_at' => $latest->created_at?->toIso8601String(),
                    'failed_items' => $latest->items->where('passed', false)->map(fn ($i) => ['label' => $i->label, 'note' => $i->note])->values(),
                ] : null,
                'ready_at' => $booking->ready_at?->toIso8601String(),
                'can_inspect' => $this->inspectBlocker($request->user('api'), $booking) === null,
                'cannot_inspect_reason' => $this->inspectBlocker($request->user('api'), $booking),
                'required' => (bool) config('booking.qc_required'),
            ],
        ]);
    }

    public function store(Request $request, int $id)
    {
        $user = $request->user('api');
        $booking = Booking::findOrFail($id);
        $this->authorizeView($user, $booking);

        $request->validate([
            'results' => 'required|array|min:1|max:100',
            'results.*.item_id' => 'required|integer',
            'results.*.passed' => 'required|boolean',
            'results.*.note' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ], [
            'results.required' => 'Hasil pemeriksaan wajib diisi.',
        ]);

        return DB::transaction(function () use ($request, $user, $booking) {
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->first();

            if ($reason = $this->inspectBlocker($user, $locked)) {
                abort(403, $reason);
            }

            // Butir yang berlaku saat ini untuk layanan booking ini; semua butir AKTIF harus dijawab.
            $items = QcChecklistItem::query()
                ->whereIn('service_key', $locked->serviceKeys())
                ->where('is_active', true)
                ->get()
                ->keyBy('id');

            if ($items->isEmpty()) {
                abort(422, 'Belum ada daftar periksa QC untuk layanan booking ini. Minta admin mengisinya di menu Daftar Periksa QC.');
            }

            $answers = collect($request->input('results'))->keyBy('item_id');

            $unknown = $answers->keys()->diff($items->keys());
            if ($unknown->isNotEmpty()) {
                abort(422, 'Ada butir periksa yang tidak berlaku untuk booking ini. Muat ulang daftar periksa lalu coba lagi.');
            }

            $missing = $items->keys()->diff($answers->keys());
            if ($missing->isNotEmpty()) {
                abort(422, 'Semua butir periksa harus dijawab (lulus atau tidak lulus). Masih ada ' . $missing->count() . ' butir yang kosong.');
            }

            $passed = $items->every(fn (QcChecklistItem $item) => ! $item->is_required || (bool) $answers[$item->id]['passed']);

            // Butir yang tidak lulus wajib punya catatan: pengerja perlu tahu apa yang diperbaiki.
            foreach ($items as $item) {
                $answer = $answers[$item->id];
                if (! $answer['passed'] && blank($answer['note'] ?? null)) {
                    abort(422, "Butir \"{$item->label}\" tidak lulus: isi catatan perbaikannya.");
                }
            }

            $result = BookingQcResult::create([
                'booking_id' => $locked->id,
                'inspector_user_id' => $user->id,
                'passed' => $passed,
                'notes' => $request->input('notes'),
            ]);

            foreach ($items as $item) {
                $answer = $answers[$item->id];
                $result->items()->create([
                    'service_key' => $item->service_key,
                    'label' => $item->label,
                    'is_required' => $item->is_required,
                    'passed' => (bool) $answer['passed'],
                    'note' => $answer['note'] ?? null,
                ]);
            }

            // Siap diambil: terisi saat QC lulus, dikosongkan bila pemeriksaan terbaru gagal.
            $locked->forceFill(['ready_at' => $passed ? now() : null])->saveQuietly();

            if ($passed) {
                $customerId = $locked->customer_id;
                $bookingNumber = $locked->booking_number;

                DB::afterCommit(function () use ($customerId, $bookingNumber, $locked) {
                    try {
                        app(PushNotificationService::class)->sendToCustomer(
                            (int) $customerId,
                            'Kendaraan Siap Diambil',
                            "Kendaraan Anda (booking {$bookingNumber}) sudah lolos pemeriksaan akhir dan siap diambil.",
                            ['type' => 'booking_ready', 'booking_id' => $locked->id],
                        );
                    } catch (\Throwable $e) {
                        report($e);
                    }
                });
            }

            return response()->json([
                'success' => true,
                'message' => $passed ? 'Quality Check lulus. Pelanggan diberi tahu kendaraan siap diambil.' : 'Quality Check tidak lulus. Perbaiki butir yang ditandai lalu periksa ulang.',
                'data' => ['passed' => $passed, 'ready_at' => $locked->fresh()->ready_at?->toIso8601String()],
            ], 201);
        });
    }

    /** @return array<int, array{key: string, label: string, items: array<int, array{id: int, label: string, is_required: bool}>}> */
    private function checklistFor(Booking $booking): array
    {
        $items = QcChecklistItem::query()
            ->whereIn('service_key', $booking->serviceKeys())
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('service_key');

        return collect($booking->serviceKeys())
            ->filter(fn (string $key) => $items->has($key))
            ->map(fn (string $key) => [
                'key' => $key,
                'label' => BookingServiceType::labelFor($key),
                'items' => $items[$key]->map(fn (QcChecklistItem $i) => ['id' => $i->id, 'label' => $i->label, 'is_required' => $i->is_required])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /** Siapa pun staf toko yang punya akses booking boleh MELIHAT; yang boleh MEMERIKSA dicek di inspectBlocker(). */
    private function authorizeView($user, Booking $booking): void
    {
        abort_if($user->hasRole('partner'), 403, 'Anda tidak punya akses ke Quality Check.');

        if (! $user->isFullAccess() && $booking->store_id !== $user->store_id) {
            abort(403, 'Anda tidak punya akses ke booking toko lain.');
        }

        // Installer hanya boleh melihat booking yang ditugaskan kepadanya.
        if ($user->hasRole('installer') && ! $booking->installers()->whereKey($user->id)->exists()) {
            abort(403, 'Booking ini tidak ditugaskan kepada Anda.');
        }
    }

    /** Alasan akun ini TIDAK boleh memeriksa booking ini, atau null kalau boleh. */
    private function inspectBlocker($user, Booking $booking): ?string
    {
        if (! ($user->isFullAccess() || $user->hasAnyRole(['store_manager', 'installer_leader']))) {
            return 'Quality Check hanya dilakukan Leader, Store Manager, atau akses penuh.';
        }

        if ($booking->status !== 'confirmed') {
            return 'Quality Check hanya untuk booking yang sudah dikonfirmasi dan belum selesai.';
        }

        if ($booking->installers()->whereKey($user->id)->exists()) {
            return 'Anda mengerjakan unit ini, jadi tidak bisa memeriksanya sendiri. Minta Leader atau Store Manager lain.';
        }

        // Boleh diisi sebelum atau sesudah tahap "Quality Check" ditandai di chat (setelah ditandai, tahap produk tidak lagi di titik akhirnya).
        if ($booking->current_stage !== 'qc' && ($blocker = $booking->qualityCheckBlocker())) {
            return $blocker;
        }

        return null;
    }
}
