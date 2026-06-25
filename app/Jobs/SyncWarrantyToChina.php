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

    public $tries = 5;
    public $backoff = 60;

    protected $warranty;

    public function __construct(Warranty $warranty)
    {
        $this->warranty = $warranty;
    }

    public function handle(): void
    {
        $endpoint = config('services.china.api_url') . '/warranty/sync';

        $response = Http::timeout(5)
            ->withHeaders([
                'X-Sign-Key' => config('services.china.secret_key'),
            ])->post($endpoint, [
                'warranty_code'     => $this->warranty->warranty_code,
                'customer_name'     => $this->warranty->customer_name,
                'phone_number'      => $this->warranty->phone_number,
                'car_plate'         => $this->warranty->car_plate,
                'car_type'          => $this->warranty->car_type,
                'product_series'    => $this->warranty->product_series,
                'installation_date' => $this->warranty->installation_date,
                'expiry_date'       => $this->warranty->expiry_date,
                'dealer_name'       => $this->warranty->dealer_name,
            ]);

        if (!$response->successful()) {
            // Gagalkan job secara eksplisit agar masuk mekanisme retry/backoff
            throw new Exception('Sistem China merespons dengan error atau lambat. Status: ' . $response->status());
        }

        // Tidak perlu update status jadi "success" — status warranty (active/expired/pending)
        // sudah ditentukan di awal saat submit dan dihitung otomatis oleh model, bukan oleh hasil sync.
    }

    public function failed(Exception $exception): void
    {
        // Catat ke log supaya tim bisa cek kasus gagal sync secara manual,
        // karena tidak ada kolom status khusus "failed" di skema warranty saat ini.
        \Illuminate\Support\Facades\Log::error('Gagal sync warranty ke China: ' . $this->warranty->warranty_code, [
            'error' => $exception->getMessage(),
        ]);
    }
}