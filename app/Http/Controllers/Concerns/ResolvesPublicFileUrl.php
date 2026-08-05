<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Facades\Storage;

/**
 * Sebelumnya duplikat identik di NewsController dan CaseStudyController
 * (dan sempat juga di BookingMessageController sebelum dipindah ke model
 * BookingMessage) — disatukan di sini supaya cuma ada satu sumber
 * kebenaran, mencegah drift kalau logikanya perlu diubah di masa depan.
 */
trait ResolvesPublicFileUrl
{
    /**
     * Storage::url() bisa mengembalikan path relatif ("/storage/...")
     * ATAU sudah full URL (kalau disk-nya S3/CDN). Path relatif digabung
     * manual dengan APP_URL supaya hasilnya selalu full absolute URL,
     * terlepas dari domain frontend yang berbeda dari domain API.
     */
    private function fullImageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $relative = Storage::url($path);

        if (str_starts_with($relative, 'http://') || str_starts_with($relative, 'https://')) {
            return $relative;
        }

        return rtrim(config('app.url'), '/') . '/' . ltrim($relative, '/');
    }
}
