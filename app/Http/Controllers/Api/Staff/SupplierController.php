<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;

/**
 * GET /api/staff/suppliers?search= — pilihan pemasok untuk Terima Barang di mobile. Hanya nama (tanpa kontak, NPWP, atau rekening)
 * dan hanya supplier aktif; dibuka bagi akun yang punya akses menu Permohonan Pembelian.
 */
class SupplierController extends Controller
{
    public function index(Request $request)
    {
        if (! ($request->user('api')?->hasPurchaseRequestAccess() ?? false)) {
            return response()->json(['success' => false, 'message' => 'Akun ini tidak punya akses ke menu Permohonan Pembelian.'], 403);
        }

        $search = trim((string) $request->query('search', ''));

        $suppliers = Supplier::query()->where('is_active', true)
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')->limit(30)->get(['id', 'name']);

        return response()->json(['success' => true, 'data' => $suppliers]);
    }
}