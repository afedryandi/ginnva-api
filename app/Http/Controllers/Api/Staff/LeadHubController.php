<?php

namespace App\Http\Controllers\Api\Staff;

use App\Filament\Resources\PartnershipInquiryResource;
use App\Filament\Resources\ProductInquiryResource;
use App\Http\Controllers\Controller;
use App\Models\PartnershipInquiry;
use App\Models\ProductInquiry;
use App\Models\Quotation;
use Illuminate\Http\Request;

/**
 * Daftar Lead gabungan untuk aplikasi staf (2026-11-13): Quotation, Inquiry Produk, dan Kemitraan dalam satu daftar, terbaru di atas.
 *
 *  GET /api/staff/lead-hub?type=all|quotation|product-inquiry|partnership&status=&mine=1&page=1
 *
 * Tiap jenis tetap memakai izin dan cakupan aslinya (quotation dibatasi toko, inquiry mengikuti izin menu); jenis yang tidak boleh
 * diakses user itu dilewati, bukan menggagalkan seluruh daftar. Detail dan tindak lanjut tetap lewat endpoint masing-masing jenis.
 * Status memakai satu himpunan: new, contacted, deal, rejected, closed, cancelled.
 */
class LeadHubController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request)
    {
        $user = $request->user('api');
        abort_unless($user->canAccessStaffArea(), 403, 'Anda tidak punya akses ke menu ini.');

        $page = max(1, (int) $request->integer('page', 1));
        $status = $request->filled('status') ? (string) $request->string('status') : null;
        $mine = $request->boolean('mine');
        $wanted = (string) $request->string('type', 'all');

        $allowed = array_values(array_filter(['quotation', 'product-inquiry', 'partnership'], fn (string $t) => $this->canView($request, $t)));
        abort_if($allowed === [], 403, 'Anda tidak punya akses ke menu ini.');

        $types = $wanted === 'all' ? $allowed : array_values(array_intersect([$wanted], $allowed));
        abort_if($types === [], 403, 'Anda tidak punya akses ke jenis lead ini.');

        // Ambil (page x 20) teratas dari tiap jenis, gabung, urutkan, lalu potong halaman yang diminta. Cukup untuk volume lead toko.
        $limit = $page * self::PER_PAGE;
        $rows = collect();
        $total = 0;

        foreach ($types as $type) {
            $query = $this->query($request, $type, $status, $mine);
            $total += (clone $query)->count();
            $rows = $rows->merge($query->orderByDesc('created_at')->limit($limit)->get()->map(fn ($m) => $this->present($type, $m)));
        }

        $items = $rows->sortByDesc('created_at')->values();
        $pageItems = $items->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values();

        return response()->json([
            'success' => true,
            'data' => $pageItems,
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / self::PER_PAGE)),
                'total' => $total,
                'types' => $allowed,
            ],
        ]);
    }

    private function canView(Request $request, string $type): bool
    {
        $user = $request->user('api');

        return match ($type) {
            'quotation' => $user->hasQuotationAccess(),
            'product-inquiry' => $user->hasMenuAccess(ProductInquiryResource::class),
            'partnership' => $user->hasMenuAccess(PartnershipInquiryResource::class),
            default => false,
        };
    }

    private function query(Request $request, string $type, ?string $status, bool $mine)
    {
        $user = $request->user('api');

        $query = match ($type) {
            'quotation' => Quotation::query()->with(['vehicle:id,brand,model', 'store:id,name', 'owner:id,name']),
            'product-inquiry' => ProductInquiry::query()->with('owner:id,name'),
            'partnership' => PartnershipInquiry::query()->with('owner:id,name'),
        };

        if ($type === 'quotation' && ! $user->isFullAccess()) {
            $query->where(fn ($q) => $q->where('store_id', $user->store_id)->orWhereNull('store_id'));
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($mine) {
            $query->where('owner_user_id', $user->id);
        }

        return $query;
    }

    private function present(string $type, $m): array
    {
        $base = [
            'type' => $type,
            'id' => $m->id,
            'status' => $m->status,
            'outcome' => $m->outcome ?? null,
            'outcome_reason' => $m->outcome_reason ?? null,
            'outcome_note' => $m->outcome_note ?? null,
            'owner_user_id' => $m->owner_user_id,
            'owner' => $m->owner ? ['id' => $m->owner->id, 'name' => $m->owner->name] : null,
            'message' => $m->message,
            'created_at' => $m->created_at?->toIso8601String(),
        ];

        return match ($type) {
            'quotation' => $base + [
                'name' => $m->customer_name,
                'contact' => $m->customer_phone,
                'subtitle' => $m->vehicle ? trim($m->vehicle->brand.' '.$m->vehicle->model) : null,
                'store_name' => $m->store?->name,
                'number' => $m->quotation_number,
            ],
            'product-inquiry' => $base + [
                'name' => $m->customer_name,
                'contact' => $m->customer_contact,
                'subtitle' => $m->interest ?: $m->message,
                'store_name' => null,
                'number' => $m->inquiry_number,
            ],
            'partnership' => $base + [
                'name' => $m->applicant_name,
                'contact' => $m->phone_number,
                'subtitle' => $m->dealer_name ?: $m->city,
                'store_name' => null,
                'number' => null,
            ],
        };
    }
}
