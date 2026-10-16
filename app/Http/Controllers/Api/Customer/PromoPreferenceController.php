<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Preferensi promosi pelanggan (Marketing/Konten Fase 5): opt-in eksplisit, bawaan MATI. Hanya mengatur notifikasi promosi; pesan
 * transaksional (booking, garansi, chat) tetap terkirim. Perubahan dicatat bersama waktunya.
 *
 *  GET /api/customer/promo-preference
 *  PUT /api/customer/promo-preference   { "opt_in": true|false }   (juga menandai pelanggan sudah ditanya)
 */
class PromoPreferenceController extends Controller
{
    public function show(Request $request)
    {
        return response()->json(['success' => true, 'data' => $this->payload($request->user('customer'))]);
    }

    public function update(Request $request)
    {
        $data = $request->validate(['opt_in' => 'required|boolean']);
        $customer = $request->user('customer');
        $optIn = (bool) $data['opt_in'];

        if ($optIn !== (bool) $customer->promo_opt_in) {
            $customer->forceFill([
                'promo_opt_in' => $optIn,
                'promo_opt_in_at' => now(),
                'promo_opt_in_source' => 'app',
            ]);
        }

        // Pilihan apa pun (ya atau tidak) berarti pelanggan sudah ditanya; pertanyaan awal di aplikasi tidak muncul lagi.
        if (! $customer->promo_prompted_at) {
            $customer->promo_prompted_at = now();
        }

        $customer->save();

        return response()->json([
            'success' => true,
            'message' => $optIn ? 'Anda akan menerima info promo dari Ginnva.' : 'Anda tidak akan menerima notifikasi promo lagi.',
            'data' => $this->payload($customer->fresh()),
        ]);
    }

    private function payload($customer): array
    {
        return [
            'opt_in' => (bool) $customer->promo_opt_in,
            'changed_at' => $customer->promo_opt_in_at?->toIso8601String(),
            'prompted' => $customer->promo_prompted_at !== null,
        ];
    }
}
