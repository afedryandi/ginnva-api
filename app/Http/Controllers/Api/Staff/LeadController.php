<?php

namespace App\Http\Controllers\Api\Staff;

use App\Filament\Resources\ProductInquiryResource;
use App\Http\Controllers\Controller;
use App\Models\ProductInquiry;
use App\Support\LeadAttribution;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Catat lead manual dari aplikasi staf (Marketing/Konten Fase 0, 2026-10-22): lead yang masuk lewat WhatsApp, telepon, DM, atau
 * datang langsung dan tidak punya jejak otomatis. Disimpan sebagai Inquiry Produk dengan kanal yang dipilih staf dan penanda
 * pencatatnya (created_by_user_id), jadi tidak memicu notifikasi "inquiry baru" ke staf lain.
 *
 *  POST /api/staff/leads
 */
class LeadController extends Controller
{
    public const INTERESTS = ['ppf', 'kaca_film', 'detailing', 'premium_wash', 'color_change', 'kaca_bangunan', 'lainnya'];

    public function store(Request $request)
    {
        $user = $request->user('api');

        abort_unless(
            $user->canAccessStaffArea()
                && $user->hasMenuAccess(ProductInquiryResource::class)
                && $user->hasModuleAction(ProductInquiryResource::class, 'create', true),
            403,
            'Anda tidak punya akses mencatat lead.'
        );

        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_contact' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
            'channel' => ['required', Rule::in(LeadAttribution::MANUAL_CHANNELS)],
            'interest' => ['nullable', Rule::in(self::INTERESTS)],
            'campaign' => ['nullable', 'string', 'max:150'],
        ], [
            'channel.in' => 'Kanal tidak valid.',
            'channel.required' => 'Pilih dari mana lead ini datang.',
        ]);

        $campaign = isset($data['campaign']) ? trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $data['campaign'])) : null;

        $inquiry = ProductInquiry::create([
            'customer_name' => $data['customer_name'],
            'customer_contact' => $data['customer_contact'],
            'message' => $data['message'] ?? null,
            'status' => 'new',
            'interest' => $data['interest'] ?? null,
            'created_by_user_id' => $user->id,
            'channel' => $data['channel'],
            'utm_campaign' => $campaign !== '' ? $campaign : null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Lead dicatat.',
            'data' => ['inquiry_number' => $inquiry->inquiry_number, 'id' => $inquiry->id],
        ], 201);
    }
}
