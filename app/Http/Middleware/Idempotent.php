<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotensi untuk tindakan yang tidak boleh terjadi dua kali (Fase 0 pengerjaan ulang booking, 2026-10-14).
 *
 * Klien membuat satu kunci unik per tindakan dan mengirimnya di header `Idempotency-Key`. Server menyimpan kunci beserta
 * hasilnya; kiriman ulang dengan kunci yang sama mengembalikan hasil asli (header `Idempotent-Replayed: true`), bukan
 * menjalankan tindakannya lagi. Tanpa header, middleware ini tidak melakukan apa pun (klien lama tetap bekerja).
 *
 * Aturan:
 * - Kunci berlaku per pemilik (pelanggan/staf/IP), 24 jam.
 * - Kunci yang sama dengan isi permintaan BERBEDA = 422 (kunci dipakai ulang secara keliru).
 * - Permintaan pertama masih diproses = 409 dengan Retry-After (jangan jalankan paralel).
 * - Hanya hasil 2xx berformat JSON yang disimpan. Gagal (4xx/5xx) menghapus kunci supaya klien boleh mengulang dengan
 *   kunci yang sama setelah memperbaiki masalahnya.
 * - Baris "processing" yang menggantung lebih dari 60 detik dianggap basi dan boleh diambil alih.
 *
 * Batasan yang diketahui: hasil disimpan SETELAH tindakan selesai, jadi jika proses mati tepat di antaranya, kiriman
 * ulang setelah 60 detik bisa menjalankan tindakan lagi. Untuk booking itu masih tertahan aturan duplikat (toko,
 * tanggal, layanan sama) dan batas 3 booking pending.
 */
class Idempotent
{
    private const TTL_HOURS = 24;
    private const STALE_PROCESSING_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null || $key === '') {
            return $next($request);
        }

        if (! preg_match('/^[A-Za-z0-9_\-]{8,100}$/', (string) $key)) {
            return response()->json([
                'success' => false,
                'code' => 'invalid_idempotency_key',
                'message' => 'Idempotency-Key tidak valid (8–100 karakter: huruf, angka, "_" atau "-").',
            ], 422);
        }

        $scope = $this->scope($request);
        $hash = $this->fingerprint($request);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $id = DB::table('idempotency_keys')->insertGetId([
                    'scope' => $scope,
                    'key' => $key,
                    'method' => $request->method(),
                    'path' => mb_substr($request->path(), 0, 191),
                    'request_hash' => $hash,
                    'status' => 'processing',
                    'created_at' => now(),
                    'expires_at' => now()->addHours(self::TTL_HOURS),
                ]);
            } catch (UniqueConstraintViolationException) {
                $existing = DB::table('idempotency_keys')->where('scope', $scope)->where('key', $key)->first();

                if (! $existing) {
                    continue; // dihapus di antara dua pengecekan: coba lagi dari awal
                }

                $expired = Carbon::parse($existing->expires_at)->isPast();
                $stale = $existing->status === 'processing'
                    && Carbon::parse($existing->created_at)->lt(now()->subSeconds(self::STALE_PROCESSING_SECONDS));

                if ($expired || $stale) {
                    DB::table('idempotency_keys')->where('id', $existing->id)->delete();
                    continue;
                }

                if ($existing->request_hash !== $hash) {
                    return response()->json([
                        'success' => false,
                        'code' => 'idempotency_key_reused',
                        'message' => 'Kunci permintaan ini sudah dipakai untuk isi yang berbeda. Muat ulang halaman lalu coba lagi.',
                    ], 422);
                }

                if ($existing->status === 'processing') {
                    return response()->json([
                        'success' => false,
                        'code' => 'request_in_progress',
                        'message' => 'Permintaan Anda sebelumnya masih diproses. Tunggu sebentar lalu cek hasilnya.',
                    ], 409)->header('Retry-After', '2');
                }

                return response((string) $existing->response_body, (int) $existing->response_status, [
                    'Content-Type' => 'application/json',
                    'Idempotent-Replayed' => 'true',
                ]);
            }

            return $this->run($request, $next, $id);
        }

        return $next($request); // tidak boleh sampai sini; jangan memblokir pengguna kalau terjadi
    }

    private function run(Request $request, Closure $next, int $id): Response
    {
        $stored = false;

        try {
            $response = $next($request);
            $status = $response->getStatusCode();

            if ($status >= 200 && $status < 300 && $response instanceof JsonResponse) {
                DB::table('idempotency_keys')->where('id', $id)->update([
                    'status' => 'completed',
                    'response_status' => $status,
                    'response_body' => $response->getContent(),
                ]);
                $stored = true;
            }

            return $response;
        } finally {
            if (! $stored) {
                DB::table('idempotency_keys')->where('id', $id)->delete();
            }
        }
    }

    private function scope(Request $request): string
    {
        if ($customer = $request->user('customer')) {
            return 'customer:' . $customer->getAuthIdentifier();
        }

        if ($user = $request->user('api')) {
            return 'user:' . $user->getAuthIdentifier();
        }

        return 'ip:' . $request->ip();
    }

    /**
     * Sidik isi permintaan: metode + jalur + input + identitas berkas (nama dan ukuran; isinya tidak di-hash).
     */
    private function fingerprint(Request $request): string
    {
        $files = collect($request->allFiles())->flatten()->map(
            fn ($file) => $file->getClientOriginalName() . ':' . $file->getSize()
        )->sort()->values()->all();

        $input = $request->except(array_keys($request->allFiles()));
        ksort($input);

        return hash('sha256', $request->method() . '|' . $request->path() . '|' . json_encode($input) . '|' . json_encode($files));
    }
}
