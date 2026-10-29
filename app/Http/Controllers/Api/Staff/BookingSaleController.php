<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingSaleLine;
use App\Models\PaymentAccount;
use App\Services\BookingRevenueSplitter;
use App\Services\BookingSaleLineService;
use App\Services\BookingSaleProcessor;
use App\Services\BookingSettlementPaymentService;
use App\Services\SaleProcessingException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Catat penjualan dan pembayaran booking dari aplikasi staf (Penjualan Fase 5, 2026-11-13).
 *
 *  GET  /api/staff/bookings/{id}/sale  keadaan penjualan booking + pilihan form (jenis layanan, metode, rekening)
 *  POST /api/staff/bookings/{id}/sale  catat penjualan pertama kali (rincian harga, nominal, pembayaran diterima)
 *
 * Memakai BookingSaleProcessor yang sama dengan aksi Proses Referral di panel, jadi aturan persetujuan sama: staf non-akses-penuh
 * masuk antrean persetujuan kecuali diskon masih dalam batas nego. Dari HP hanya pencatatan PERTAMA; booking yang sudah diproses hanya
 * bisa dilihat (koreksi nominal dilakukan di panel oleh yang berwenang). Hanya booking berstatus selesai.
 */
class BookingSaleController extends Controller
{
    public function show(Request $request, int $id)
    {
        $booking = $this->authorizeSale($request->user('api'), Booking::with(['customer:id,name', 'saleLines', 'settlementPayments'])->findOrFail($id));

        return response()->json(['success' => true, 'data' => $this->payload($booking)]);
    }

    public function store(Request $request, int $id)
    {
        $user = $request->user('api');
        $booking = $this->authorizeSale($user, Booking::findOrFail($id));

        if ($booking->status !== 'completed') {
            abort(422, 'Penjualan hanya bisa dicatat setelah booking ditandai selesai.');
        }

        if ($booking->isSaleProcessed()) {
            abort(422, 'Penjualan booking ini sudah dicatat. Koreksi dilakukan di panel admin oleh yang berwenang.');
        }

        $validated = $request->validate([
            'transaction_amount' => 'required|numeric|min:0',
            'sale_lines' => 'nullable|array|max:30',
            'sale_lines.*.service_type' => 'nullable|string',
            'sale_lines.*.film_product_id' => 'nullable|integer',
            'sale_lines.*.position' => 'nullable|string|max:80',
            'sale_lines.*.quantity' => 'nullable|numeric',
            'sale_lines.*.unit_price' => 'nullable|numeric',
            'sale_lines.*.discount_amount' => 'nullable|numeric',
            'settlement_payments' => 'nullable|array|max:10',
            'settlement_payments.*.method' => 'nullable|string',
            'settlement_payments.*.payment_account_id' => 'nullable|integer',
            'settlement_payments.*.amount' => 'nullable|numeric',
            'referral_code' => 'nullable|string|max:20',
        ]);

        // Layanan lain (jurnal, kas) memakai auth()->id() untuk pencatat; dalam permintaan API pengguna ada di guard 'api'.
        Auth::setUser($user);

        try {
            $result = app(BookingSaleProcessor::class)->process($booking, $validated, $user);
        } catch (SaleProcessingException $e) {
            return response()->json(['success' => false, 'title' => $e->title, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'status' => $result['status'],
            'message' => $result['status'] === 'pending' ? $result['message'] : 'Penjualan dicatat.',
            'messages' => $result['messages'],
            'data' => $this->payload($booking->fresh(['customer:id,name', 'saleLines', 'settlementPayments'])),
        ]);
    }

    private function authorizeSale($user, Booking $booking): Booking
    {
        if ($user->hasRole('installer') || $user->hasRole('partner')) {
            abort(403, 'Anda tidak bisa mencatat penjualan booking.');
        }

        abort_unless($user->hasBookingAccess(), 403, 'Anda tidak punya akses menu Booking.');

        if (! $user->isFullAccess() && $booking->store_id !== $user->store_id) {
            abort(403, 'Anda tidak punya akses ke booking toko lain.');
        }

        return $booking;
    }

    private function payload(Booking $booking): array
    {
        // Rekening kas/bank hanya ada bila modul Keuangan menyala (FEATURE_FINANCE).
        $accounts = ! config('features.finance') ? collect() : PaymentAccount::query()
            ->with('chartAccount')
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('store_id')->orWhere('store_id', $booking->store_id))
            ->orderBy('name')
            ->get()
            ->filter(fn (PaymentAccount $a) => $a->isUsable())
            ->map(fn (PaymentAccount $a) => ['id' => $a->id, 'name' => $a->name, 'methods' => $a->methods ?? []])
            ->values();

        return [
            'booking' => [
                'id' => $booking->id,
                'booking_number' => $booking->booking_number,
                'customer_name' => $booking->display_customer_name,
                'status' => $booking->status,
                'completed_at' => $booking->completed_at?->toIso8601String(),
            ],
            'processed' => $booking->isSaleProcessed(),
            'can_record' => $booking->status === 'completed' && ! $booking->isSaleProcessed(),
            'transaction_amount' => $booking->transaction_amount !== null ? (float) $booking->transaction_amount : null,
            'amount_received' => $booking->amount_received !== null ? (float) $booking->amount_received : null,
            'lines_required' => BookingSaleLineService::isRequiredFor($booking),
            'down_payment_outstanding' => (float) $booking->outstanding_down_payment,
            'sale_lines' => $booking->saleLines->map(fn ($l) => [
                'service_type' => $l->service_type,
                'position' => $l->position,
                'quantity' => (float) $l->quantity,
                'unit_price' => (float) $l->unit_price,
                'discount_amount' => (float) $l->discount_amount,
                'line_total' => (float) $l->line_total,
            ])->values(),
            'settlement_payments' => $booking->settlementPayments->map(fn ($p) => [
                'method' => $p->method,
                'payment_account_id' => $p->payment_account_id,
                'amount' => (float) $p->amount,
            ])->values(),
            'suggested_service_types' => BookingRevenueSplitter::productKeys($booking),
            'service_types' => BookingSaleLine::SERVICE_TYPES,
            'methods' => Booking::PAYMENT_METHOD_LABELS,
            'accounts' => $accounts,
        ];
    }
}
