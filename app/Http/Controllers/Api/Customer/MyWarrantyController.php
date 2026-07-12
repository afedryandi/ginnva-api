<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
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
            'data' => $warranties,
        ]);
    }

    /**
     * GET /api/customer/warranties/{id}
     * Detail warranty milik customer yang login.
     */
    public function show(Request $request, int $id)
    {
        $warranty = $request->user('customer')
            ->warranties()
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $warranty,
        ]);
    }
}