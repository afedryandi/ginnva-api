<?php

namespace App\Http\Controllers\Api\Staff;

use App\Filament\Resources\PartnershipInquiryResource;
use App\Filament\Resources\ProductInquiryResource;
use App\Http\Controllers\Controller;
use App\Models\PartnershipInquiry;
use App\Models\ProductInquiry;
use App\Support\LeadOutcome;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Daftar dan tindak lanjut lead Inquiry Produk dan Kemitraan dari aplikasi staf (Marketing/Konten Fase 1, 2026-10-23); quotation punya
 * endpointnya sendiri (StaffQuotationController).
 *
 *  GET   /api/staff/lead-inbox/{type}        type = product-inquiry | partnership
 *  PATCH /api/staff/lead-inbox/{type}/{id}   status, pemilik, hasil, alasan kalah
 *
 * Staf hanya boleh memindahkan status antara Baru dan Sudah Dihubungi. "Deal" (yang membuat partner) dan "Ditolak" pada kemitraan, serta
 * "Selesai" pada inquiry produk, tetap lewat panel web yang punya alur lengkapnya; hasil Menang/Kalah dicatat lewat kolom hasil.
 */
class LeadInboxController extends Controller
{
    private const TYPES = [
        'product-inquiry' => ['model' => ProductInquiry::class, 'resource' => ProductInquiryResource::class],
        'partnership' => ['model' => PartnershipInquiry::class, 'resource' => PartnershipInquiryResource::class],
    ];

    public function index(Request $request, string $type)
    {
        [$model, $resource] = $this->resolve($request, $type, 'view');

        $query = $model::query()->with('owner:id,name')->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->boolean('mine')) {
            $query->where('owner_user_id', $request->user('api')->id);
        }

        return response()->json(['success' => true, 'data' => $query->paginate(20)]);
    }

    public function update(Request $request, string $type, int $id)
    {
        [$model, $resource] = $this->resolve($request, $type, 'update');

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['new', 'contacted'])],
            'owner_user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'outcome' => ['sometimes', 'nullable', Rule::in(array_keys(LeadOutcome::OUTCOMES))],
            'outcome_reason' => ['sometimes', 'nullable', Rule::in(array_keys(LeadOutcome::REASONS))],
        ]);

        $lead = $model::findOrFail($id);

        $outcome = array_key_exists('outcome', $data) ? $data['outcome'] : $lead->outcome;

        if ($outcome === LeadOutcome::LOST && blank($data['outcome_reason'] ?? $lead->outcome_reason)) {
            return response()->json([
                'success' => false,
                'message' => 'Alasan wajib diisi bila lead ditandai kalah.',
                'errors' => ['outcome_reason' => ['Alasan wajib diisi bila lead ditandai kalah.']],
            ], 422);
        }

        $lead->update($data);

        return response()->json(['success' => true, 'data' => $lead->load('owner:id,name')]);
    }

    /** @return array{0: class-string, 1: class-string} */
    private function resolve(Request $request, string $type, string $action): array
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        ['model' => $model, 'resource' => $resource] = self::TYPES[$type];
        $user = $request->user('api');

        abort_unless(
            $user->canAccessStaffArea()
                && $user->hasMenuAccess($resource)
                && ($action === 'view' || $user->hasModuleAction($resource, $action, true)),
            403,
            'Anda tidak punya akses ke menu ini.'
        );

        return [$model, $resource];
    }
}
