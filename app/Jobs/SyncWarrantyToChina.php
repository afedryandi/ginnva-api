<?php

namespace App\Jobs;

use App\Models\Warranty;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Exception;

class SyncWarrantyToChina implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Tentukan jumlah maksimal percobaan ulang (retry) jika timeout
    public $tries = 5;

    // Jeda waktu (detik) sebelum mencoba ulang
    public $backoff = 60;

    protected $warranty;

    public function __construct(Warranty $warranty)
    {
        $this->warranty = $warranty;
    }

    public function handle(): void
    {
        // Ambil data endpoint China dari konfigurasi .env
        $endpoint = config('services.china.api_url') . '/warranty/sync';

        // Kirim data ke sistem China dengan batas timeout ketat (misal 5 detik)
        $response = Http::timeout(5)
            ->withHeaders([
                'X-Sign-Key' => config('services.china.secret_key')
            ])->post($endpoint, [
                'code' => $this->warranty->code,
                'product_id' => $this->warranty->product_id,
                'store_id' => $this->warranty->store_id,
                'owner' => $this->warranty->owner,
                'car_info' => $this->warranty->car_info,
                'install_date' => $this->warranty->install_date,
            ]);

        if ($response->successful()) {
            $this->warranty->update(['status' => 'success']);
        } else {
            // Gagalkan job secara eksplisit agar masuk mekanisme retry/backoff
            throw new Exception('Sistem China merespons dengan error atau lambat.');
        }
    }

    public function failed(Exception $exception): void
    {
        // Eksekusi jika 5 kali percobaan tetap gagal total
        $this->warranty->update(['status' => 'failed']);
    }
}