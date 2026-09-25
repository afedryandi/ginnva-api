<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Endpoint dan kredensial API milik kantor pusat China.
    // Wajib diminta ke tim China: base URL dan secret key untuk autentikasi.
    'china' => [
        'api_url' => env('CHINA_API_URL'),
        'secret_key' => env('CHINA_API_SECRET_KEY'),
    ],
    
    // Asisten AI (ChatController) — sempat dicoba pindah ke Gemini API,
    // tapi project Google Cloud yang dipakai ternyata butuh billing
    // aktif (bukan gratis seperti seharusnya untuk kasus ini), jadi
    // dikembalikan ke Groq dengan model lebih kecil supaya limit
    // token-per-menit free tier-nya lebih longgar (awalnya
    // llama-3.1-8b-instant, dipensiunkan Groq per 2026-08-19, diganti
    // openai/gpt-oss-20b — lihat ChatController::send()).
    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
    ],

    // WhatsApp Business Cloud API (Meta) — dipakai untuk reminder servis
    // berkala (lihat App\Services\WhatsAppService). Diisi setelah akun
    // Meta Business Manager + template pesan disetujui. Kalau
    // access_token/phone_number_id kosong, WhatsAppService diam-diam
    // skip pengiriman (log saja) — reminder Push & Email tetap jalan
    // tanpa menunggu ini.
    'whatsapp' => [
        'access_token'    => env('WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'template_name'   => env('WHATSAPP_TEMPLATE_SERVICE_REMINDER', 'service_reminder'),
        'template_lang'   => env('WHATSAPP_TEMPLATE_LANG', 'id'),
    ],

    'fcm' => [
        // Path ke Service Account JSON yang didownload dari Firebase Console
        // → Project Settings → Service Accounts → Generate new private key
        // Simpan file di storage/app/firebase/ (jangan di public/)
        'credentials_path' => env('FCM_CREDENTIALS_PATH', storage_path('app/firebase/service-account.json')),
    ],

    // Backup database otomatis (App\Console\Commands\BackupDatabase, lihat
    // routes/console.php) -- audit framework 2026-09-14, "Jadwal backup
    // database otomatis". rclone_remote OPSIONAL: kalau kosong, backup
    // tetap dibuat & disimpan lokal, cuma tidak ada salinan off-site.
    'backup' => [
        'rclone_remote' => env('RCLONE_BACKUP_REMOTE'),
        'retention_days' => env('BACKUP_RETENTION_DAYS', 14),
    ],

    // Kalkulator "Price List Kaca Film" (halaman terpisah di ginnva-web)
    // — fitur berdiri sendiri, tidak berhubungan dengan auth staff Ginnva.
    // Data harga/ukuran/allow-list semua dibaca live dari Google Sheet
    // yang dikelola owner. File service account (untuk baca Sheet secara
    // read-only) harus ditaruh manual di storage/app/google/ karena berisi
    // private key — tidak bisa lewat env var.
    'google_pricelist' => [
        'spreadsheet_id' => env('GOOGLE_PRICELIST_SPREADSHEET_ID', '1CkgZwBduVechS69CGXVJiDvCo7yD8P4AmiXzGZ3y4fI'),
        'oauth_client_id' => env('GOOGLE_PRICELIST_OAUTH_CLIENT_ID', '57970143794-r8eq5l809f3ait3d67ra3iqsunjdag04.apps.googleusercontent.com'),
        'service_account_path' => storage_path('app/google/pricelist-service-account.json'),
    ],

];