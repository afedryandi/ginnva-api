<?php

namespace App\Console\Commands;

use App\Models\DeviceToken;
use App\Models\NotificationDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Notifikasi Fase 3 (2026-10-21): periksa kuitansi Expo untuk push yang tiketnya sudah diterima. Tiket hanya berarti Expo menerima
 * pesan; kuitansi menyatakan pesan benar-benar diserahkan ke FCM/APNs. Dokumentasi Expo menyarankan menunggu sekitar 15 menit dan
 * menyatakan kuitansi dihapus setelah 24 jam. Token berstatus DeviceNotRegistered dihapus agar berhenti dikirimi.
 */
class CheckPushReceipts extends Command
{
    protected $signature = 'notifications:check-receipts {--minutes=15 : Periksa tiket yang lebih tua dari N menit}';

    protected $description = 'Periksa kuitansi Expo untuk push yang sudah diterima, tandai terkirim/gagal, dan hapus token mati';

    private const RECEIPTS_URL = 'https://exp.host/--/api/v2/push/getReceipts';

    private const MAX_PER_RUN = 1000;

    public function handle(): int
    {
        $due = NotificationDelivery::where('status', NotificationDelivery::ACCEPTED)
            ->whereNotNull('ticket_id')
            ->where('sent_at', '<=', now()->subMinutes((int) $this->option('minutes')))
            ->orderBy('sent_at')
            ->limit(self::MAX_PER_RUN)
            ->get();

        $counts = ['delivered' => 0, 'failed' => 0, 'dead_token' => 0, 'unknown' => 0, 'pending' => 0];

        foreach ($due->chunk(300) as $batch) {
            try {
                $response = Http::timeout(15)->withHeaders(['Accept' => 'application/json'])
                    ->post(self::RECEIPTS_URL, ['ids' => $batch->pluck('ticket_id')->unique()->values()->all()]);
            } catch (\Throwable $e) {
                report($e);
                $counts['pending'] += $batch->count();
                continue;
            }

            if (! $response->successful()) {
                $this->warn('Expo getReceipts HTTP ' . $response->status());
                $counts['pending'] += $batch->count();
                continue;
            }

            $receipts = $response->json('data') ?? [];

            foreach ($batch as $delivery) {
                $receipt = $receipts[$delivery->ticket_id] ?? null;

                if ($receipt === null) {
                    // Belum tersedia: coba lagi di putaran berikutnya, kecuali sudah lewat umur kuitansi (24 jam).
                    if ($delivery->sent_at->lte(now()->subHours(23))) {
                        $delivery->update(['status' => NotificationDelivery::UNKNOWN, 'checked_at' => now()]);
                        $counts['unknown']++;
                    } else {
                        $counts['pending']++;
                    }
                    continue;
                }

                if (($receipt['status'] ?? '') === 'ok') {
                    $delivery->update(['status' => NotificationDelivery::DELIVERED, 'checked_at' => now()]);
                    $counts['delivered']++;
                    continue;
                }

                if (($receipt['details']['error'] ?? '') === 'DeviceNotRegistered') {
                    DeviceToken::where('token', $delivery->token)->delete();
                    $delivery->update(['status' => NotificationDelivery::DEAD_TOKEN, 'error' => 'DeviceNotRegistered', 'checked_at' => now()]);
                    $counts['dead_token']++;
                    continue;
                }

                $delivery->update([
                    'status'     => NotificationDelivery::FAILED,
                    'error'      => mb_substr((string) ($receipt['message'] ?? ($receipt['details']['error'] ?? 'Gagal diserahkan')), 0, 255),
                    'checked_at' => now(),
                ]);
                $counts['failed']++;
            }
        }

        $this->info("Kuitansi: {$counts['delivered']} terkirim, {$counts['failed']} gagal, {$counts['dead_token']} token mati dihapus, {$counts['unknown']} tidak diketahui, {$counts['pending']} masih menunggu.");

        return self::SUCCESS;
    }
}
