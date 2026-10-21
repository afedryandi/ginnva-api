<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Kotak masuk notifikasi staf di aplikasi mobile (Notifikasi Fase 2, 2026-10-21). Membaca tabel notifications Laravel yang sama dengan
 * lonceng panel; baris dibuat oleh StaffNotifier (dan oleh notifikasi panel Filament lama). Setiap pengguna hanya melihat miliknya.
 *
 *  GET  /api/staff/inbox               daftar (terbaru dulu), ?unread=1 hanya belum dibaca, ?page=
 *  GET  /api/staff/inbox/unread-count  jumlah belum dibaca (untuk badge)
 *  POST /api/staff/inbox/{id}/read     tandai satu dibaca
 *  POST /api/staff/inbox/read-all      tandai semua dibaca
 *
 * Tidak dibatasi akses menu: notifikasi sudah disaring menurut penerimanya saat dikirim.
 */
class InboxController extends Controller
{
    private const PER_PAGE = 30;

    public function index(Request $request)
    {
        $user = $request->user('api');

        $query = $request->boolean('unread') ? $user->unreadNotifications() : $user->notifications();
        $page = $query->latest('created_at')->latest('id')->simplePaginate(self::PER_PAGE);

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (DatabaseNotification $n) => $this->transform($n))->values(),
            'has_more' => $page->hasMorePages(),
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    public function unreadCount(Request $request)
    {
        return response()->json(['success' => true, 'data' => ['unread_count' => $request->user('api')->unreadNotifications()->count()]]);
    }

    public function markRead(Request $request, string $id)
    {
        $notification = $request->user('api')->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();

        return response()->json(['success' => true, 'data' => $this->transform($notification->fresh()), 'unread_count' => $request->user('api')->unreadNotifications()->count()]);
    }

    public function markAllRead(Request $request)
    {
        $request->user('api')->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['success' => true, 'unread_count' => 0]);
    }

    /** @return array<string, mixed> */
    private function transform(DatabaseNotification $n): array
    {
        $data = (array) $n->data;
        $view = (array) ($data['viewData'] ?? []);
        $payload = (array) ($view['payload'] ?? []);

        return [
            'id' => $n->id,
            'title' => (string) ($data['title'] ?? ''),
            'body' => (string) ($data['body'] ?? ''),
            'color' => (string) ($data['color'] ?? 'info'),
            // route/params dari muatan push; aplikasi memvalidasinya dengan daftar rute yang diizinkan sebelum membuka.
            'route' => $payload['route'] ?? null,
            'params' => $payload['params'] ?? null,
            'type' => $payload['type'] ?? null,
            'source' => ($view['source'] ?? null) === 'staff_inbox' ? 'staff_inbox' : 'panel',
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
