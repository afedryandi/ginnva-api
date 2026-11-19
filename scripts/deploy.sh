#!/usr/bin/env bash
#
# Deploy satu perintah untuk server API (dev maupun production), 2026-12-16.
#
#   bash scripts/deploy.sh             tarik kode, build tema bila perlu, bangun ulang cache
#   bash scripts/deploy.sh --migrate   sama, ditambah php artisan migrate --force (setelah cache dibersihkan)
#
# Yang dilakukan, berurutan:
#   1. git pull
#   2. npm install + npm run build HANYA bila berkas yang memengaruhi tema berubah (resources/css, resources/js, app/Filament,
#      resources/views/filament, package*.json, konfigurasi vite/tailwind) atau hasil build belum ada. Tailwind memindai berkas Filament,
#      jadi kelas baru di sana juga butuh build.
#   3. php artisan optimize:clear
#   4. (opsional) php artisan migrate --force
#   5. php artisan optimize, filament:optimize, queue:restart
#
# Sengaja TIDAK menjalankan tes: tes harus berjalan sebelum `optimize` (cache konfigurasi membuat tes mengarah ke database dev; lihat
# catatan insiden di docs). Jalankan tes terpisah, SEBELUM skrip ini:
#   php artisan optimize:clear && php artisan test --filter=...
#
# Aman dijalankan ulang. Berhenti di kesalahan pertama (set -e), jadi cache tidak dibangun di atas kode yang gagal di-pull atau di-build.

set -euo pipefail

MIGRATE=0
for arg in "$@"; do
    case "$arg" in
        --migrate) MIGRATE=1 ;;
        *) echo "Argumen tidak dikenal: $arg (yang ada: --migrate)" >&2; exit 2 ;;
    esac
done

before="$(git rev-parse HEAD)"
git pull
after="$(git rev-parse HEAD)"
changed="$(git diff --name-only "$before" "$after" || true)"

needs_install=0
needs_build=0

if [ ! -d node_modules ] || echo "$changed" | grep -qE '^package(-lock)?\.json$'; then
    needs_install=1
    needs_build=1
fi

if [ ! -f public/build/manifest.json ] \
    || echo "$changed" | grep -qE '^(resources/css/|resources/js/|app/Filament/|resources/views/filament/|vite\.config|postcss\.config|tailwind\.config)'; then
    needs_build=1
fi

if [ "$needs_install" -eq 1 ]; then
    echo ">> npm install"
    npm install
fi

if [ "$needs_build" -eq 1 ]; then
    echo ">> npm run build"
    npm run build
else
    echo ">> build tema dilewati (tidak ada berkas tema yang berubah)"
fi

php artisan optimize:clear

if [ "$MIGRATE" -eq 1 ]; then
    php artisan migrate --force
fi

php artisan optimize
php artisan filament:optimize
php artisan queue:restart

echo ">> selesai: $(git rev-parse --short HEAD)"
