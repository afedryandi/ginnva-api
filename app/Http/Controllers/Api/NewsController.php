<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesPublicFileUrl;
use App\Http\Controllers\Controller;
use App\Models\News;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NewsController extends Controller
{
    use ResolvesPublicFileUrl;

    /**
     * GET /api/news
     *
     * Daftar berita yang sudah dipublikasikan, untuk halaman "/news" di
     * web (sebelumnya hardcoded di NewsGrid.tsx — endpoint ini
     * menggantikan itu, sesuai catatan di migration create_news_table).
     *
     * Hanya menampilkan is_published = true DAN published_at <= sekarang,
     * supaya berita yang dijadwalkan tayang nanti tidak bocor duluan.
     * Diurutkan terbaru dulu.
     *
     * Query param opsional:
     *   ?limit=6   — batasi jumlah hasil (default semua)
     */
    public function index(Request $request): JsonResponse
    {
        $query = News::query()
            ->where('is_published', true)
            ->where(function ($q) {
                $q->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->orderByDesc('published_at')
            ->orderByDesc('created_at');

        if ($limit = $request->query('limit')) {
            $query->limit((int) $limit);
        }

        $news = $query->get()->map(fn (News $item) => $this->transform($item));

        return response()->json([
            'success' => true,
            'data'    => $news,
        ]);
    }

    /**
     * GET /api/news/{slug}
     *
     * Detail satu berita berdasarkan slug. Mengembalikan 404 kalau tidak
     * ditemukan atau belum dipublikasikan (disembunyikan dari publik,
     * sama seperti pola di StoreController::show).
     */
    public function show(string $slug): JsonResponse
    {
        $news = News::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->where(function ($q) {
                $q->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->first();

        if (! $news) {
            return response()->json([
                'success' => false,
                'message' => 'Berita tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->transform($news, withContent: true),
        ]);
    }

    /**
     * cover_image disimpan sebagai path relatif (mis. "news/xxxx.jpg")
     * oleh Filament FileUpload. Storage::url() saja TIDAK CUKUP di sini —
     * hasilnya cuma path relatif ("/storage/news/xxxx.jpg") tanpa domain,
     * sehingga browser di frontend (domain berbeda, mis. ginnva.id) akan
     * mencoba load gambar dari domainnya sendiri, bukan dari api.ginnva.id
     * tempat file itu sebenarnya berada — itulah sebab gambar 404/broken.
     *
     * Di sini path relatif itu digabung manual dengan APP_URL (paksa,
     * tidak mengandalkan Storage::url() membaca APP_URL dengan benar),
     * supaya hasilnya selalu full absolute URL terlepas dari konfigurasi
     * filesystem disk yang dipakai.
     */
    private function transform(News $news, bool $withContent = false): array
    {
        $data = [
            'id'           => $news->id,
            'title'        => $news->title,
            'slug'         => $news->slug,
            'excerpt'      => $news->excerpt,
            'cover_image'  => $this->fullImageUrl($news->cover_image),
            'source_url'   => $news->source_url,
            'published_at' => $news->published_at,
        ];

        if ($withContent) {
            $data['content'] = $news->content;
        }

        return $data;
    }
}