<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductInquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductInquiryController extends Controller
{
    /**
     * POST /api/inquiry/submit
     *
     * Endpoint untuk minat customer terhadap produk yang belum tersedia
     * (Color Change & Architectural Film). Sengaja dipisah dari
     * QuotationController::submit karena tidak butuh vehicle_id maupun
     * daftar item produk — hanya kontak + catatan bebas.
     */
    public function submit(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'customer_name'    => ['required', 'string', 'max:255'],
                'customer_contact' => ['required', 'string', 'max:255'],
                'message'          => ['nullable', 'string', 'max:2000'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors'  => $e->errors(),
            ], 422);
        }

        $inquiry = ProductInquiry::create([
            'customer_name'    => $validated['customer_name'],
            'customer_contact' => $validated['customer_contact'],
            'message'          => $validated['message'] ?? null,
            'status'           => 'new',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pertanyaan Anda berhasil dikirim. Tim kami akan segera menghubungi Anda.',
            'data'    => [
                'inquiry_number' => $inquiry->inquiry_number,
            ],
        ], 201);
    }

    /**
     * GET /api/inquiry
     *
     * Untuk admin/dashboard internal melihat daftar inquiry yang masuk.
     * Disediakan agar konsisten dengan kemungkinan kebutuhan admin panel
     * (Filament) ke depan; aman dihapus jika belum diperlukan.
     */
    public function index(Request $request): JsonResponse
    {
        $inquiries = ProductInquiry::query()
            ->when($request->query('status'), function ($query, $status) {
                $query->where('status', $status);
            })
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $inquiries,
        ]);
    }
}