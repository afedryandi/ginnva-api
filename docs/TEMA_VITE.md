# Tema Vite panel admin

Gaya dasar panel admin (kanvas, kartu, tabel, lencana, isian) ada di `resources/css/filament/admin/theme.css` dan dibuild dengan Vite.
Tema dipasang di `AdminPanelProvider` **hanya kalau hasil build-nya ada** (`public/build/manifest.json` memuat `resources/css/filament/admin/theme.css`).
Kalau belum ada, panel tetap jalan seperti biasa, bukan error 500.

## Memasang atau memperbarui (server)

```bash
npm install && npm run build && php artisan optimize:clear
```

Jalankan setiap kali `resources/css/filament/**` atau view Filament berubah. `public/build` tidak masuk Git, jadi build dilakukan di tiap server.

## Mengembalikan ke tampilan tanpa tema

Hapus hasil build, lalu bersihkan cache; tidak ada perubahan kode:

```bash
rm -rf public/build && php artisan optimize:clear
```

Mengembalikan seluruhnya (termasuk berkas tema): `git revert` commit tema, lalu `php artisan optimize:clear`.

## Catatan

- Font Plus Jakarta Sans dimuat lewat `->font()` Filament (penyedia Bunny Fonts); warna utama tetap merah Ginnva, netral memakai slate.
- Menu yang dirombak satu per satu (`App\Support\CleanUi`) tetap memakai gayanya sendiri di atas tema ini.
