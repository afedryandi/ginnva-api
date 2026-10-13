<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\DemoDataGuard;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Sapu (smoke test) seluruh halaman panel admin pada data SUNGGUHAN di server dev: membuka tiap halaman (daftar, tambah,
 * lihat, edit, semua laporan/halaman) sebagai tiap role, lalu melaporkan yang error 500 beserta pesannya. Hanya request
 * GET (tidak ada simpan/hapus), dan tidak membuat sesi di database. Tes PHPUnit tidak bisa melakukan ini karena
 * database tes kosong; di sini data demo (demo:presentasi) ikut terjangkau.
 *
 * Penjelasan hasil: 200 = halaman terbuka; 403/404 = ditolak/tidak ada untuk role itu (normal bila memang dibatasi);
 * 302 = dialihkan (mis. ke halaman lain); 5xx atau exception = BUG yang harus diperbaiki.
 */
class SmokePanel extends Command
{
    use DemoDataGuard;

    protected $signature = 'smoke:panel
        {--role=* : Role yang dicoba (default: super_admin, direksi, store_manager)}
        {--only= : Hanya halaman yang namanya mengandung teks ini}';

    protected $description = 'Buka semua halaman panel admin sebagai tiap role dan laporkan yang error (hanya server dev)';

    private string $current = '';

    public function handle(): int
    {
        if ($this->refuseOutsideDemoEnvironment()) {
            return self::FAILURE;
        }

        // Kalau PHP mati mendadak (kehabisan memori / exit di tengah halaman), tampilkan halaman terakhir yang dibuka.
        register_shutdown_function(function () {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
                fwrite(STDERR, "
PROSES MATI saat membuka: {$this->current}
  {$error['message']} @ " . basename($error['file']) . ':' . $error['line'] . "
");
            }
        });

        // Tanpa sesi database (jangan menumpuk ratusan baris sesi), dan pesan error asli di respons JSON.
        config(['session.driver' => 'array', 'app.debug' => true]);

        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);

        $roles = $this->option('role') ?: ['super_admin', 'direksi', 'store_manager'];
        $only = (string) $this->option('only');

        $failures = [];
        $stats = ['200' => 0, 'ditolak' => 0, 'redirect' => 0, 'error' => 0];
        $total = 0;

        foreach ($roles as $role) {
            $user = User::role($role)->where('is_active', true)->orderBy('id')->first();

            if (! $user) {
                $this->warn("Role {$role}: tidak ada akun aktif, dilewati.");
                continue;
            }

            Auth::guard('web')->setUser($user);
            $this->info("== Role {$role} (akun #{$user->id}) ==");

            foreach ($this->targets($panel, $only) as [$label, $builder]) {
                try {
                    $url = $builder();
                } catch (Throwable $e) {
                    $failures[] = [$role, $label, 'URL', 'gagal membuat URL: ' . $e->getMessage()];
                    $stats['error']++;
                    $total++;
                    continue;
                }

                if ($url === null) {
                    continue; // tidak ada data untuk halaman record, atau role tidak boleh melihat.
                }

                $total++;
                $this->current = "{$role} / {$label}";
                [$status, $message] = $this->hit($url, $user);
                $this->line(sprintf('  %s  %s', $status, $label));

                if ($status === 200) {
                    $stats['200']++;
                } elseif (in_array($status, [401, 403, 404], true)) {
                    $stats['ditolak']++;
                } elseif ($status >= 300 && $status < 400) {
                    $stats['redirect']++;
                } else {
                    $stats['error']++;
                    $failures[] = [$role, $label, (string) $status, $message];
                }
            }
        }

        $this->newLine();
        $this->info("Selesai: {$total} halaman diuji. OK: {$stats['200']}, ditolak/tidak ada: {$stats['ditolak']}, dialihkan: {$stats['redirect']}, ERROR: {$stats['error']}.");

        if ($failures) {
            $this->error(count($failures) . ' halaman bermasalah:');
            $this->table(['Role', 'Halaman', 'Status', 'Pesan'], array_map(fn ($f) => [$f[0], $f[1], $f[2], mb_substr((string) $f[3], 0, 160)], $failures));

            return self::FAILURE;
        }

        $this->info('Tidak ada halaman yang error.');

        return self::SUCCESS;
    }

    /**
     * @return iterable<array{0: string, 1: callable}>
     */
    private function targets($panel, string $only): iterable
    {
        foreach ($panel->getPages() as $page) {
            $name = class_basename($page);
            if ($only !== '' && ! str_contains($name, $only)) {
                continue;
            }

            yield ["Halaman {$name}", fn () => $page::canAccess() ? $page::getUrl() : null];
        }

        foreach ($panel->getResources() as $resource) {
            $name = class_basename($resource);
            if ($only !== '' && ! str_contains($name, $only)) {
                continue;
            }

            $pages = $resource::getPages();

            if (isset($pages['index'])) {
                yield ["{$name} / daftar", fn () => $resource::canViewAny() ? $resource::getUrl('index') : null];
            }

            if (isset($pages['create'])) {
                yield ["{$name} / tambah", fn () => $resource::canCreate() ? $resource::getUrl('create') : null];
            }

            foreach (['view', 'edit'] as $kind) {
                if (! isset($pages[$kind])) {
                    continue;
                }

                yield ["{$name} / {$kind}", function () use ($resource, $kind) {
                    if (! $resource::canViewAny()) {
                        return null;
                    }

                    $record = $resource::getEloquentQuery()->first();

                    return $record ? $resource::getUrl($kind, ['record' => $record]) : null;
                }];
            }
        }
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function hit(string $url, User $user): array
    {
        try {
            Auth::guard('web')->setUser($user);
            $request = Request::create($url, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json, text/html']);
            $response = app(HttpKernel::class)->handle($request);
            $status = $response->getStatusCode();
            $message = '';

            if ($status >= 500) {
                $payload = json_decode((string) $response->getContent(), true);
                $message = is_array($payload)
                    ? trim(($payload['message'] ?? '') . ' @ ' . basename((string) ($payload['file'] ?? '')) . ':' . ($payload['line'] ?? ''))
                    : mb_substr(strip_tags((string) $response->getContent()), 0, 200);
            }

            return [$status, $message];
        } catch (Throwable $e) {
            return [500, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()];
        }
    }
}
