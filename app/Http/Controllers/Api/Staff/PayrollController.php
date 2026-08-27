<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Payroll;
use Illuminate\Http\Request;

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
            'total_deduction' => (float) $p->total_deduction,
            'net_pay' => (float) $p->net_pay,
            'paid_at' => $p->paid_at?->toIso8601String(),
        ];
    }
}
