<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Warranty;
use Illuminate\Http\Request;

class MyWarrantyController extends Controller
{
    /**
     * GET /api/customer/warranties
     * Daftar warranty milik customer yang login (我的质保).
     */
    public function index(Request $request)
    {
        $warranties = $request->user('customer')
            ->warranties()
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $warranties->map(fn (Warranty $w) => $this->transformSummary($w)),
        ]);
    }

    /**
     * GET /api/customer/warranties/{id}
     * Detail warranty milik customer yang login.
     */
    public function show(Request $request, int $id)
    {
        // Eager-load 'store' (untuk CTA hubungi toko) & 'claims' (riwayat
        // klaim after-sales) — SEBELUMNYA respons ini cuma model mentah,
        // customer tidak pernah bisa lihat riwayat klaimnya sendiri
        // walau datanya sudah ada (staff isi lewat Filament), dan tidak
        // ada cara untuk tahu kontak toko buat mengajukan klaim baru.
        // Lihat audit modul Garansi 2026-08-27.
        $warranty = $request->user('customer')
            ->warranties()
            ->with(['store:id,name,phone', 'claims' => fn ($q) => $q->orderByDesc('created_at')])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $this->transformDetail($warranty),
        ]);
    }

    /**
     * Whitelist field yang dikirim ke customer sendiri — SEBELUMNYA model
     * mentah (Warranty::create()-style array) dikembalikan apa adanya.
     * Risikonya rendah (sudah scoped ke akun sendiri via
     * $customer->warranties()), tapi kalau ada field sensitif baru
     * ditambahkan ke $fillable nanti, field itu otomatis ikut ter-expose
     * tanpa sengaja. Whitelist eksplisit sama seperti
     * WarrantyController::check(). Lihat audit modul Garansi 2026-08-27.
     */
    private function transformSummary(Warranty $w): array
    {
        return [
            'id'                => $w->id,
            'warranty_code'     => $w->warranty_code,
            'customer_name'     => $w->customer_name,
            'car_type'          => $w->car_type,
            'car_plate'         => $w->car_plate,
            'product_series'    => $w->product_series,
            'product_category'  => $w->product_category,
            'dealer_name'       => $w->dealer_name,
            'installation_date' => $w->installation_date,
            'expiry_date'       => $w->expiry_date,
            'status'            => $w->status,
            'remaining_days'    => $w->remaining_days,
            'review_status'     => $w->review_status,
        ];
    }

    private function transformDetail(Warranty $w): array
    {
        return array_merge($this->transformSummary($w), [
            'rejection_reason'              => $w->rejection_reason,
            'installation_position'         => $w->installation_position,
            'installation_position_detail'  => $w->installation_position_detail,
            'roll_number'                   => $w->roll_number,
            'roll_number_2'                 => $w->roll_number_2,
            'roll_number_front'             => $w->roll_number_front,
            'roll_number_side_rear'         => $w->roll_number_side_rear,
            'film_model_front'              => $w->film_model_front,
            'film_model_side_rear'          => $w->film_model_side_rear,
            'store'                         => $w->store ? [
                'name'  => $w->store->name,
                'phone' => $w->store->phone,
            ] : null,
            'claims'                        => $w->claims->map(fn ($c) => [
                'claim_number'      => $c->claim_number,
                'category'          => $c->category,
                'description'       => $c->description,
                'status'            => $c->status,
                'rejection_reason'  => $c->rejection_reason,
                'created_at'        => $c->created_at,
            ]),
        ]);
    }
}
