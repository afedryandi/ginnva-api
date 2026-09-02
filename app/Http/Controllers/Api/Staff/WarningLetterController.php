<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\WarningLetter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Riwayat Surat Peringatan mandiri (self-service) — SAMA POLA dengan
 * Attendance/Payroll: setiap staff selalu boleh lihat riwayat SP MILIK
 * SENDIRI, tidak dibatasi hasMenuAccess() (bukan izin opsional yang bisa
 * lupa dicentang admin, sama seperti absen). Read-only — SP hanya bisa
 * diterbitkan/diedit lewat Filament (WarningLetterResource), tidak ada
 * jalur tulis dari mobile.
 *
 * Sebelum ini TIDAK ADA cara sama sekali bagi karyawan melihat SP-nya
 * sendiri di mobile app — satu-satunya jalur tahu adalah diberitahu
 * manual/ditunjukkan cetakan. Ditemukan & dibangun saat audit modul
 * Karyawan > Surat Peringatan.
 */
class WarningLetterController extends Controller
{
    /**
     * GET /api/staff/warning-letters
     */
    public function index(Request $request)
    {
        $letters = WarningLetter::where('user_id', $request->user('api')->id)
            ->with('issuer:id,name')
            ->orderByDesc('issued_date')
            ->get();

        return response()->json([
            'success' => true,
            'warning_letters' => $letters->map(fn (WarningLetter $w) => $this->transform($w)),
        ]);
    }

    private function transform(WarningLetter $w): array
    {
        return [
            'id' => $w->id,
            'warning_number' => $w->warning_number,
            'level' => $w->level,
            'reason' => $w->reason,
            'issued_date' => $w->issued_date->toDateString(),
            'valid_until' => $w->valid_until?->toDateString(),
            'document_url' => $w->document ? Storage::disk('public')->url($w->document) : null,
            'issuer_name' => $w->issuer?->name,
        ];
    }
}
