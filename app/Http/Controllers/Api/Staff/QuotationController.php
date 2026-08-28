<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use Illuminate\Http\Request;

/**
 * Visibilitas mobile untuk staff kelola lead Quotation — SEBELUMNYA tidak
 * ada sama sekali (staff cuma dapat push notif "Lead Baru" tanpa deep
 * link, harus pindah ke Filament untuk lihat detail), lihat audit modul
 * Quotation 2026-08-27. Dibatasi hasQuotationAccess() (beda dari
 * AttendanceController/PayrollController yang sengaja tidak dibatasi —
 * lead penjualan itu data sensitif per-toko, bukan kewajiban dasar semua
 * staff seperti absen).
 */
class QuotationController extends Controller
{
    /**
     * GET /api/staff/quotations
     * Scoping SAMA PERSIS dengan QuotationResource::getEloquentQuery() di
     * Filament — super_admin/direksi lihat semua, staff lain cuma toko
     * sendiri (+ lead lama yang store_id-nya masih null).
     */
    public function index(Request $request)
    {
        $user = $request->user('api');

        if (! $user->hasQuotationAccess()) {
            abort(403, 'Anda tidak punya akses ke menu Quotation.');
        }

        $query = Quotation::with(['vehicle:id,brand,model', 'store:id,name'])
            ->orderByDesc('created_at');

        if (! $user->isFullAccess()) {
            $query->where(function ($q) use ($user) {
                $q->where('store_id', $user->store_id)->orWhereNull('store_id');
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $quotations = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $quotations->items(),
            'meta'    => [
                'current_page' => $quotations->currentPage(),
                'last_page'    => $quotations->lastPage(),
                'total'        => $quotations->total(),
            ],
        ]);
    }

    /**
     * GET /api/staff/quotations/{id}
     */
    public function show(Request $request, int $id)
    {
        $user = $request->user('api');

        if (! $user->hasQuotationAccess()) {
            abort(403, 'Anda tidak punya akses ke menu Quotation.');
        }

        $quotation = Quotation::with(['vehicle', 'store:id,name', 'items.filmProduct:id,name'])
            ->findOrFail($id);

        if (! $user->isFullAccess() && $quotation->store_id && $quotation->store_id !== $user->store_id) {
            abort(403, 'Quotation ini milik toko lain.');
        }

        return response()->json(['success' => true, 'data' => $quotation]);
    }

    /**
     * PATCH /api/staff/quotations/{id}/status
     * Staff cuma boleh ubah status follow-up — bukan data lain (nama,
     * kendaraan, dst — itu tetap lewat Filament kalau perlu dikoreksi),
     * scope sengaja diperkecil supaya endpoint mobile ini tidak jadi
     * duplikat penuh form Filament yang lebih lengkap.
     */
    public function updateStatus(Request $request, int $id)
    {
        $user = $request->user('api');

        if (! $user->hasQuotationAccess()) {
            abort(403, 'Anda tidak punya akses ke menu Quotation.');
        }

        $request->validate([
            'status' => 'required|in:new,contacted,closed,cancelled',
        ]);

        $quotation = Quotation::findOrFail($id);

        if (! $user->isFullAccess() && $quotation->store_id && $quotation->store_id !== $user->store_id) {
            abort(403, 'Quotation ini milik toko lain.');
        }

        $quotation->update(['status' => $request->status]);

        // SEBELUMNYA relasi (items/vehicle/store) tidak di-eager-load di
        // sini — beda dari show(). Field yang tidak di-load tidak muncul
        // SAMA SEKALI di JSON (bukan array kosong), jadi mobile app yang
        // langsung pakai response ini untuk setQuotation() bikin
        // `quotation.items` jadi undefined dan crash saat render (mis.
        // `.items.length`). Disamakan dengan show() supaya bentuk respons
        // konsisten di kedua endpoint. Ditemukan & diperbaiki 2026-08-27.
        $quotation->load(['vehicle', 'store:id,name', 'items.filmProduct:id,name']);

        return response()->json(['success' => true, 'data' => $quotation]);
    }
}
