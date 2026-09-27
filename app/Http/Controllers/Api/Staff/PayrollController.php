<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Payroll;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Slip gaji mandiri (self-service) — SAMA POLA dengan AttendanceController:
 * tidak dibatasi hasMenuAccess(), setiap staff selalu boleh lihat gajinya
 * SENDIRI. Cuma baris 'paid' yang ditampilkan — 'draft' sengaja
 * disembunyikan dari staff karena angkanya masih bisa berubah (belum
 * final), menampilkannya bisa bikin salah paham/khawatir duluan.
 */
class PayrollController extends Controller
{
    /**
     * GET /api/staff/payroll
     */
    public function index(Request $request)
    {
        $payrolls = Payroll::where('user_id', $request->user('api')->id)
            ->where('status', 'paid')
            ->orderByDesc('period_month')
            ->get();

        return response()->json([
            'success' => true,
            'payrolls' => $payrolls->map(fn (Payroll $p) => $this->transform($p)),
        ]);
    }

    /**
     * GET /api/staff/payroll/{id}/slip
     * Diperbaiki 2026-09-27 (audit Penggajian, "Sisi mobile") --
     * SEBELUMNYA tidak ada endpoint unduh slip gaji sama sekali di
     * mobile, staff cuma bisa lihat rincian dari JSON. Pakai view PDF
     * yang sama dengan Filament (resources/views/pdf/payslip.blade.php)
     * supaya format identik dengan yang admin unduh.
     */
    public function slip(Request $request, int $id)
    {
        $payroll = Payroll::where('user_id', $request->user('api')->id)
            ->where('status', 'paid')
            ->findOrFail($id);

        $periodLabel = $payroll->period_month->translatedFormat('F Y');

        $pdf = Pdf::loadView('pdf.payslip', ['payroll' => $payroll, 'periodLabel' => $periodLabel])
            ->setPaper('a4', 'portrait');

        $filename = 'Slip-Gaji-' . str_replace(' ', '-', $payroll->user->name) . '-' . $payroll->period_month->format('Ym') . '.pdf';

        return new Response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * total_commission/has_unrated_commission ditambahkan 2026-09-27
     * (audit Penggajian, "Sisi mobile") -- SEBELUMNYA field ini sudah
     * dihitung & disimpan (integrasi komisi teknisi 2026-09-23, lihat
     * Payroll::generateForMonth()) tapi tidak pernah dikirim ke mobile,
     * jadi komisi teknisi yang sudah masuk net_pay invisible buat staff
     * sendiri -- mereka cuma lihat net_pay tanpa tahu ada komponen
     * komisi di dalamnya.
     */
    private function transform(Payroll $p): array
    {
        return [
            'id' => $p->id,
            'period_month' => $p->period_month->toDateString(),
            'base_salary' => (float) $p->base_salary,
            'prorated_base_salary' => (float) $p->prorated_base_salary,
            'working_days_in_month' => $p->working_days_in_month,
            'total_late_minutes' => $p->total_late_minutes,
            'late_violation_days' => $p->late_violation_days,
            'alpha_days' => $p->alpha_days,
            'alpha_deduction' => (float) $p->alpha_deduction,
            'total_commission' => (float) $p->total_commission,
            'has_unrated_commission' => (bool) $p->has_unrated_commission,
            'total_deduction' => (float) $p->total_deduction,
            'net_pay' => (float) $p->net_pay,
            'paid_at' => $p->paid_at?->toIso8601String(),
        ];
    }
}
