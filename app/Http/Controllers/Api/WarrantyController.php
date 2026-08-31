<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Warranty;
use App\Models\PointTransaction;
use App\Services\QrCodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Barryvdh\DomPDF\Facade\Pdf;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use App\Mail\WarrantyRegisteredMail;

class WarrantyController extends Controller
{
    // SEBELUMNYA cuma throttle per-menit biasa (30/menit untuk check,
    // 20/menit untuk download) — warranty_code cuma 5 digit acak (GNV-
    // PPF-XXXXX, ruang kandidat 100.000 kombinasi), jadi script yang sabar
    // (distribusi lewat banyak IP/proxy, atau nunggu antar menit) tetap
    // bisa menyapu seluruh ruang kode dalam waktu wajar walau tiap IP
    // dibatasi. Counter TERPISAH ini melacak PERCOBAAN GAGAL per IP
    // (bukan semua request) — pencarian legit oleh pemilik asli nyaris
    // tidak pernah gagal berkali-kali, jadi ini tidak mengganggu mereka,
    // tapi menghentikan scan berbasis tebak-tebakan jauh lebih cepat
    // daripada throttle per-menit biasa. Lihat audit modul Garansi
    // 2026-08-27.
    private const MAX_FAILED_LOOKUPS = 15;
    private const FAILED_LOOKUP_DECAY_SECONDS = 600; // 10 menit

    private function failedLookupKey(Request $request): string
    {
        return 'warranty-failed-lookup:' . $request->ip();
    }

    private function tooManyFailedLookups(Request $request): bool
    {
        return RateLimiter::tooManyAttempts($this->failedLookupKey($request), self::MAX_FAILED_LOOKUPS);
    }

    private function registerFailedLookup(Request $request): void
    {
        RateLimiter::hit($this->failedLookupKey($request), self::FAILED_LOOKUP_DECAY_SECONDS);
    }

    /**
     * Mask nama supaya tidak sepenuhnya identifiable — kata pertama utuh,
     * sisanya jadi inisial+titik. "Budi Santoso" -> "Budi S.".
     */
    private function maskName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        if (count($parts) <= 1) return $parts[0] ?? $name;

        $rest = array_slice($parts, 1);
        $maskedRest = array_map(fn ($p) => mb_substr($p, 0, 1) . '.', $rest);

        return $parts[0] . ' ' . implode(' ', $maskedRest);
    }

    /**
     * Mask plat nomor — 2 karakter awal & akhir tetap kelihatan, tengah
     * ditutup. "B 1234 XYZ" -> "B •••• XYZ".
     */
    private function maskPlate(string $plate): string
    {
        $clean = trim($plate);
        if (mb_strlen($clean) <= 4) return $clean;

        return mb_substr($clean, 0, 2) . ' •••• ' . mb_substr($clean, -3);
    }
    // POST /api/warranty/submit
    //
    // SEBELUMNYA endpoint ini publik total. Per keputusan bisnis: daftar
    // garansi HANYA boleh staff (Store Manager/super_admin/direksi) --
    // customer/guest cuma boleh CEK garansi (check()/download() di bawah
    // TETAP publik, itu tidak berubah), tidak boleh bikin garansi
    // sendiri. Route sudah wajib auth:api (guard staff), tapi permission
    // 'warranty.manage' dicek ULANG di sini juga (defense in depth) --
    // sama persis dengan WarrantyPolicy::create() yang dipakai Filament,
    // supaya staff berperan lain (mis. installer, kalau suatu saat
    // punya guard 'api' juga) tidak bisa daftar garansi cuma karena
    // lolos login staff. Ditemukan lewat testing manual 2026-08-31.
    public function submit(Request $request)
    {
        if (! $request->user('api')?->can('warranty.manage')) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses untuk mendaftarkan garansi.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'customer_name'     => 'required|string|max:255',
            'phone_number'      => 'required|string|max:30',
            'car_plate'         => 'required|string|max:20',
            'car_type'          => 'required|string|max:255',
            'product_series'    => 'required|string|max:255',
            'installation_date' => 'required|date',
            'expiry_date'       => 'required|date|after:installation_date',
            'dealer_name'       => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // warranty_code TIDAK diisi di sini — baru di-generate otomatis
        // (nomor acak GNV-PPF-XXXXX / GNV-WF-XXXXX) begitu staff toko
        // pilih kode gulungan lewat Filament (Select dari ScrollCode)
        // saat mengisi Detail Instalasi. Jadi warranty_code kosong dulu
        // sampai saat itu — lihat Warranty::booted() untuk logic auto-
        // generate-nya.

        // SEBELUMNYA blok ini auto-link warranty ke akun customer kalau
        // request menyertakan Bearer token customer yang valid -- masuk
        // akal SAAT endpoint ini publik (customer bisa daftar sendiri
        // sambil login). Sekarang endpoint ini staff-only (lihat guard
        // 'api' di route + cek permission di atas), jadi requester
        // SELALU staff, tidak pernah bawa token customer -- blok ini
        // otomatis jadi no-op ($customerId selalu null di sini). Sengaja
        // TIDAK dihapus (harmless, try-catch sudah aman) supaya kalau
        // nanti staff diberi opsi "hubungkan ke akun customer yang sudah
        // ada" saat input di Filament, mekanismenya tinggal disambung ke
        // sini lagi. Customer tetap bisa hubungkan warranty ke akunnya
        // sendiri belakangan lewat endpoint terpisah /warranty/claim.
        $customerId = null;
        try {
            $customer = JWTAuth::setToken(JWTAuth::getToken())->authenticate();
            $customerId = $customer?->id;
        } catch (\Throwable $e) {
            // Tidak ada token / token tidak valid -> lanjut sebagai guest.
        }

        // Catatan QA Management: submission baru TIDAK langsung aktif.
        // status tetap 'active' sebagai nilai kolom mentah, tapi
        // review_status dimulai dari 'pending_review' — accessor
        // getStatusAttribute() di model Warranty akan menampilkan
        // 'pending_review' ke luar selama belum di-approve oleh
        // super_admin lewat panel Filament.
        $warranty = Warranty::create([
            'customer_name'     => $request->customer_name,
            'phone_number'      => $request->phone_number,
            'car_plate'         => $request->car_plate,
            'car_type'          => $request->car_type,
            'product_series'    => $request->product_series,
            'installation_date' => $request->installation_date,
            'expiry_date'       => $request->expiry_date,
            'dealer_name'       => $request->dealer_name,
            'customer_id'       => $customerId,
            'status'            => 'active',
            'review_status'     => 'pending_review',
        ]);

        // CATATAN PENTING (per info resmi tim Ginnva China, akhir Juni
        // 2026): mereka belum bisa menyediakan API/data interface untuk
        // koneksi sistem realtime karena ketentuan pemerintah. Sinkronisasi
        // data warranty + after-sales + info pelanggan ke China sekarang
        // dilakukan lewat EXPORT EXCEL manual (mingguan/bulanan), dikirim
        // via email oleh tim Indonesia, BUKAN lewat API call. Export ada
        // di WarrantyResource (tombol "Export ke Excel"), bukan otomatis
        // dari sini.

        return response()->json([
            'success' => true,
            'message' => 'Data garansi berhasil didaftarkan dan sedang menunggu review admin.',
            'data' => $warranty,
        ], 201);
    }

    // GET /api/warranty/check?code=GNV-XXXXXXXXXX
    public function check(Request $request)
    {
        $code = $request->query('code');

        if (!$code) {
            return response()->json([
                'success' => false,
                'message' => 'Parameter "code" wajib diisi.',
            ], 422);
        }

        if ($this->tooManyFailedLookups($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Terlalu banyak percobaan pencarian gagal dari perangkat ini. Coba lagi dalam beberapa menit.',
            ], 429);
        }

        // Sesuai kebijakan garansi resmi Ginnva (tercantum di halaman
        // produk & materi resmi lain): verifikasi mandiri bisa pakai salah
        // satu dari nomor ponsel, plat nomor, VIN, atau kode garansi.
        //
        // SEBELUMNYA pakai ->first() (1 warranty saja) — tidak menangani
        // kasus 1 mobil punya LEBIH DARI 1 garansi (mis. PPF + Window
        // Film terdaftar terpisah untuk mobil yang sama, plat/VIN/nomor
        // HP-nya identik). Cek pakai plat/VIN/HP cuma menampilkan 1
        // garansi (yang pertama di DB), garansi satunya tidak pernah
        // terlihat sama sekali dari pencarian itu — padahal keduanya
        // valid & seharusnya sama-sama ditemukan. Sekarang pakai ->get()
        // (bisa >1) supaya SEMUA garansi yang cocok dikembalikan
        // sekaligus. Cek via warranty_code sendiri tetap selalu 1 hasil
        // (kodenya unik per garansi). Ditemukan lewat testing manual
        // 2026-08-31.
        $warranties = Warranty::where('warranty_code', $code)
            ->orWhere('car_plate', $code)
            ->orWhere('vin', $code)
            ->get();

        // warranty_code/car_plate/vin adalah identifier yang secara FISIK
        // tertera di mobil/sertifikat — wajar diketahui publik yang
        // memang di depan mobilnya. phone_number BUKAN identifier fisik
        // seperti itu — seseorang bisa mencoba nomor telepon acak/hasil
        // leak untuk cek apakah nomor itu terdaftar dan dapat data
        // lengkap pemiliknya (correlation attack). Match lewat phone_number
        // SENGAJA dianggap kurang terpercaya, datanya di-mask di bawah.
        $matchedViaPublicIdentifier = $warranties->isNotEmpty();
        if ($warranties->isEmpty()) {
            $warranties = Warranty::where('phone_number', $code)->get();
        }

        if ($warranties->isEmpty()) {
            $this->registerFailedLookup($request);

            return response()->json([
                'success' => false,
                'message' => 'Nomor garansi, plat nomor, VIN, atau nomor ponsel tidak ditemukan.',
            ], 404);
        }

        // Whitelist field yang aman untuk publik — tidak expose customer_id
        // maupun kode gulungan (roll_number*) yang dipakai bergantian
        // banyak mobil (lihat Warranty::booted()/WarrantyObserver untuk
        // alasan roll_number sengaja TIDAK publik). product_category, vin,
        // & installation_position AMAN ditambahkan (untuk match via
        // identifier publik) — data ini sama persis dengan yang sudah bisa
        // diakses siapa pun lewat PDF E-Warranty publik (download(), di
        // bawah), jadi bukan exposure baru, cuma menyamakan apa yang
        // tampil di halaman cek vs di PDF-nya.
        //
        // Match lewat NOMOR TELEPON dapat versi MASKED — termasuk
        // warranty_code-nya sendiri, supaya hasil tebak-nomor-telepon
        // tidak bisa langsung dipakai buka /warranty/download/{code} dan
        // dapat PDF lengkap juga (itu akan membuat masking di sini
        // percuma). Lihat audit modul Garansi 2026-08-27.
        $transform = function (Warranty $warranty) use ($matchedViaPublicIdentifier) {
            if (! $matchedViaPublicIdentifier) {
                return [
                    'id'                => $warranty->id,
                    'warranty_code'     => 'GNV-••••• (hubungi toko untuk kode lengkap)',
                    'customer_name'     => $this->maskName($warranty->customer_name),
                    'car_plate'         => $this->maskPlate($warranty->car_plate),
                    'car_type'          => $warranty->car_type,
                    'product_series'    => $warranty->product_series,
                    'product_category'  => $warranty->product_category,
                    // ->format('Y-m-d') WAJIB, bukan pass Carbon instance
                    // mentah -- Carbon implements JsonSerializable, dan
                    // default jsonSerialize()-nya konversi lewat UTC
                    // berdasarkan APP_TIMEZONE server. Untuk field
                    // TANGGAL MURNI (bukan datetime, tidak ada makna jam)
                    // ini menggeser tanggalnya mundur 1 hari kalau
                    // APP_TIMEZONE bukan UTC (mis. Asia/Jakarta) --
                    // dikonfirmasi lewat testing manual: Filament (render
                    // server-side, tidak lewat JSON) tampil benar, mobile
                    // app (baca dari sini) tampil mundur 1 hari. Ditemukan
                    // & diperbaiki 2026-08-31.
                    'installation_date' => optional($warranty->installation_date)->format('Y-m-d'),
                    'expiry_date'       => optional($warranty->expiry_date)->format('Y-m-d'),
                    'dealer_name'       => $warranty->dealer_name,
                    'status'            => $warranty->status,
                    'review_status'     => $warranty->review_status,
                    'has_owner'         => $warranty->customer_id !== null,
                    'masked'            => true,
                ];
            }

            return [
                'id'                            => $warranty->id,
                'warranty_code'                 => $warranty->warranty_code,
                'customer_name'                 => $warranty->customer_name,
                'car_plate'                     => $warranty->car_plate,
                'car_type'                      => $warranty->car_type,
                'product_series'                => $warranty->product_series,
                'product_category'              => $warranty->product_category,
                'vin'                           => $warranty->vin,
                'installation_position'         => $warranty->installation_position,
                'installation_position_detail'  => $warranty->installation_position_detail,
                // Sama seperti di atas -- ->format('Y-m-d') mencegah
                // pergeseran tanggal akibat konversi UTC saat JSON
                // serialize.
                'installation_date'             => optional($warranty->installation_date)->format('Y-m-d'),
                'expiry_date'                   => optional($warranty->expiry_date)->format('Y-m-d'),
                'dealer_name'                   => $warranty->dealer_name,
                'status'                        => $warranty->status,
                'review_status'                 => $warranty->review_status,
                'has_owner'                     => $warranty->customer_id !== null,
                'masked'                        => false,
            ];
        };

        return response()->json([
            'success' => true,
            // SEBELUMNYA object tunggal ('data' => [...1 warranty...]),
            // sekarang SELALU array (walau cuma 1 hasil) supaya frontend
            // konsisten satu bentuk untuk semua kasus, tidak perlu cek
            // "array atau object" — lihat WarrantyForm.tsx (web) &
            // app/warranty/check.tsx (mobile) yang disesuaikan mengikuti
            // perubahan ini.
            'data' => $warranties->map($transform)->values(),
        ], 200);
    }

    /**
     * POST /api/warranty/claim
     * Hubungkan warranty ke akun customer yang sedang login.
     * Hanya bisa kalau warranty belum dimiliki siapapun (customer_id = null).
     */
    public function claim(Request $request)
    {
        $request->validate(['warranty_code' => 'required|string']);

        $customer = auth('customer')->user();

        // Dibungkus transaction + lockForUpdate supaya dua request claim()
        // yang hampir bersamaan (double-tap, atau race 2 akun berbeda)
        // tidak bisa dua-duanya lolos cek "belum ada pemilik" / "belum
        // pernah dapat poin" sebelum salah satu commit duluan — tanpa lock
        // ini bisa berakibat customer_id ke-assign dobel sesaat atau poin
        // ke-award dua kali untuk warranty yang sama.
        return DB::transaction(function () use ($request, $customer) {
            $warranty = Warranty::where('warranty_code', $request->warranty_code)
                ->lockForUpdate()
                ->first();

            if (!$warranty) {
                return response()->json(['success' => false, 'message' => 'Garansi tidak ditemukan.'], 404);
            }

            if ($warranty->customer_id !== null) {
                return response()->json(['success' => false, 'message' => 'Garansi ini sudah terhubung ke akun lain.'], 409);
            }

            $warranty->update(['customer_id' => $customer->id]);

            // Kalau warranty sudah approved sebelum diklaim, award poin sekarang.
            // Observer tidak akan terpicu lagi karena review_status tidak berubah.
            $alreadyRewarded = PointTransaction::where('reference_type', 'warranty')
                ->where('reference_id', $warranty->id)
                ->lockForUpdate()
                ->exists();

            $pointsAwarded = false;
            if ($warranty->review_status === 'approved' && !$alreadyRewarded) {
                PointTransaction::create([
                    'customer_id'    => $customer->id,
                    'type'           => 'earn',
                    'points'         => 100,
                    'description'    => 'Garansi disetujui: ' . $warranty->warranty_code,
                    'reference_type' => 'warranty',
                    'reference_id'   => $warranty->id,
                ]);
                $customer->increment('loyalty_points', 100);
                $pointsAwarded = true;
            }

            return response()->json([
                'success'       => true,
                'message'       => 'Garansi berhasil dihubungkan ke akun Anda.',
                'points_awarded' => $pointsAwarded ? 100 : 0,
            ]);
        });
    }

    // GET /api/warranty/download/{code}
    public function download(Request $request, $code)
    {
        if ($this->tooManyFailedLookups($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Terlalu banyak percobaan pencarian gagal dari perangkat ini. Coba lagi dalam beberapa menit.',
            ], 429);
        }

        $warranty = Warranty::where('warranty_code', $code)->first();

        if (! $warranty) {
            $this->registerFailedLookup($request);
            abort(404);
        }

        // E-warranty resmi hanya bisa diunduh setelah QA Certificate
        // disetujui oleh super_admin. Sebelum itu, dokumen belum sah.
        if ($warranty->review_status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Sertifikat garansi ini belum disetujui dan belum bisa diunduh.',
            ], 403);
        }

        // QR code berisi LINK ke halaman cek garansi publik di web
        // (bukan cuma teks kode polos), supaya kalau di-scan pakai
        // kamera HP manapun (tidak harus app Ginnva), langsung terbuka
        // di browser dan otomatis menampilkan hasil verifikasi —
        // ginnva-web/app/warranty/WarrantyForm.tsx sudah disesuaikan
        // untuk baca query param ?code= ini dan auto-trigger pencarian.
        $verifyUrl = rtrim(config('app.frontend_url', 'https://ginnva.id'), '/')
            . '/warranty?code=' . urlencode($warranty->warranty_code);

        $qrCodeDataUri = QrCodeService::generateDataUri($verifyUrl);

        $pdf = Pdf::loadView('pdf.warranty_card', compact('warranty', 'qrCodeDataUri'))
                  ->setPaper('a4', 'portrait');

        return $pdf->download("E-Warranty-Ginnva-{$code}.pdf");
    }
}