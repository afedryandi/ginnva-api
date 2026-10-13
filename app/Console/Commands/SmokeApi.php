<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\DemoDataGuard;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\ConsumableItem;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\MaterialMemo;
use App\Models\News;
use App\Models\Partner;
use App\Models\Payroll;
use App\Models\Quotation;
use App\Models\RawMaterial;
use App\Models\Spk;
use App\Models\Store;
use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyMaintenanceSchedule;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Sapu (smoke test) semua endpoint BACA (GET) API yang dipakai aplikasi mobile pada data sungguhan di server dev:
 * masuk sebagai pelanggan, staf (beberapa role), dan partner, membuka tiap endpoint dengan parameter contoh dari data
 * yang ada, dan melaporkan yang error 500 (beserta pesannya). Hanya GET — tidak mengubah data. Pasangan dari
 * smoke:panel (halaman admin). Endpoint tulis (POST/PUT) sudah dicakup tes PHPUnit dan audit kontrak aplikasi.
 *
 * Penjelasan hasil: 200 = OK; 401/403/404/422 = ditolak/tidak ada/butuh parameter (normal untuk kombinasi role+contoh
 * data tertentu); 429 = terkena batas laju; 5xx atau exception = BUG.
 */
class SmokeApi extends Command
{
    use DemoDataGuard;

    protected $signature = 'smoke:api
        {--actor=* : Aktor yang dicoba: publik, pelanggan, partner, store_manager, installer, installer_leader, direksi (default: semua)}
        {--only= : Hanya endpoint yang alamatnya mengandung teks ini}';

    protected $description = 'Buka semua endpoint GET API mobile sebagai pelanggan/staf/partner dan laporkan yang error (hanya server dev)';

    public function handle(): int
    {
        if ($this->refuseOutsideDemoEnvironment()) {
            return self::FAILURE;
        }

        // Tanpa penghitung batas laju yang menetap, dan pesan error asli di respons JSON.
        config(['cache.default' => 'array', 'app.debug' => true]);

        $only = (string) $this->option('only');
        $wanted = $this->option('actor') ?: ['publik', 'pelanggan', 'partner', 'store_manager', 'installer', 'installer_leader', 'direksi'];

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (LaravelRoute $r) => in_array('GET', $r->methods(), true) && str_starts_with($r->uri(), 'api/'))
            ->filter(fn (LaravelRoute $r) => $only === '' || str_contains($r->uri(), $only))
            ->values();

        $failures = [];
        $skipped = [];
        $total = 0;
        $counts = ['200' => 0, 'ditolak' => 0, 'error' => 0];

        foreach ($wanted as $actorName) {
            [$actor, $guard] = $this->actor($actorName);

            if ($actorName !== 'publik' && ! $actor) {
                $this->warn("Aktor {$actorName}: tidak ada akun yang cocok di database, dilewati.");
                continue;
            }

            $this->info("== Aktor {$actorName}" . ($actor ? " (#{$actor->id})" : '') . ' ==');

            foreach ($routes as $route) {
                if (! $this->routeBelongsTo($route, $actorName)) {
                    continue;
                }

                $uri = $this->fillParams($route, $actor, $actorName);

                if ($uri === null) {
                    $skipped[] = "{$actorName}: /{$route->uri()}";
                    continue;
                }

                $total++;
                [$status, $message] = $this->hit('/' . $uri, $actor, $guard);
                $this->line(sprintf('  %s  /%s', $status, $uri));

                if ($status === 200) {
                    $counts['200']++;
                } elseif ($status >= 500) {
                    $counts['error']++;
                    $failures[] = [$actorName, '/' . $uri, (string) $status, $message];
                } else {
                    $counts['ditolak']++;
                }
            }
        }

        $this->newLine();
        $this->info("Selesai: {$total} permintaan. OK: {$counts['200']}, ditolak/tidak ada/butuh data: {$counts['ditolak']}, ERROR: {$counts['error']}.");

        if ($skipped) {
            $this->comment(count($skipped) . ' endpoint dilewati karena tidak ada data contoh untuk parameternya (bukan error).');
        }

        if ($failures) {
            $this->error(count($failures) . ' endpoint bermasalah:');
            $this->table(['Aktor', 'Alamat', 'Status', 'Pesan'], array_map(fn ($f) => [$f[0], $f[1], $f[2], mb_substr((string) $f[3], 0, 170)], $failures));

            return self::FAILURE;
        }

        $this->info('Tidak ada endpoint yang error.');

        return self::SUCCESS;
    }

    /**
     * @return array{0: mixed, 1: ?string} [model akun, nama guard]
     */
    private function actor(string $name): array
    {
        return match ($name) {
            'publik' => [null, null],
            'pelanggan' => [Customer::whereHas('bookings')->orderBy('id')->first() ?? Customer::orderBy('id')->first(), 'customer'],
            'partner' => [Partner::orderBy('id')->first()?->user, 'api'],
            default => [User::role($name === 'direksi' ? ['direksi', 'super_admin'] : [$name])->where('is_active', true)->orderBy('id')->first(), 'api'],
        };
    }

    private function routeBelongsTo(LaravelRoute $route, string $actorName): bool
    {
        $middleware = implode(',', $route->gatherMiddleware());
        $uri = $route->uri();

        if (str_contains($middleware, 'auth:customer')) {
            return $actorName === 'pelanggan';
        }

        if (str_contains($middleware, 'auth:api')) {
            return match (true) {
                str_starts_with($uri, 'api/partner') => $actorName === 'partner',
                default => in_array($actorName, ['store_manager', 'installer', 'installer_leader', 'direksi'], true),
            };
        }

        return $actorName === 'publik'; // tanpa autentikasi
    }

    /**
     * Isi parameter rute dengan contoh dari data yang ada. null = tidak ada contoh (dilewati).
     */
    private function fillParams(LaravelRoute $route, mixed $actor, string $actorName): ?string
    {
        $uri = $route->uri();
        $segments = explode('/', $uri);

        foreach ($segments as $i => $segment) {
            if (! preg_match('/^\{(\w+)(\?)?\}$/', $segment, $m)) {
                continue;
            }

            $previous = $segments[$i - 1] ?? '';
            try {
                $value = $this->sample($previous, $m[1], $actor, $actorName, $segments);
            } catch (Throwable) {
                $value = null; // contoh tidak bisa diambil (kolom/tabel berbeda) — endpoint dilewati, bukan sapuan dibatalkan
            }

            if ($value === null) {
                if (($m[2] ?? '') === '?') {
                    $segments[$i] = '';
                    continue;
                }

                return null;
            }

            $segments[$i] = rawurlencode((string) $value);
        }

        return trim(implode('/', array_filter($segments, fn ($s) => $s !== '')), '/');
    }

    private function sample(string $previous, string $param, mixed $actor, string $actorName, array $segments): mixed
    {
        $store = fn () => Store::orderBy('id')->value('id');

        return match ($previous) {
            'stores' => $store(),
            'bookings' => $actorName === 'pelanggan'
                ? Booking::withoutGlobalScopes()->where('customer_id', $actor->id)->orderBy('id')->value('id')
                : Booking::withoutGlobalScopes()->when($actor?->store_id && ! $actor->isFullAccess(), fn ($q) => $q->where('store_id', $actor->store_id))->orderBy('id')->value('id'),
            'warranties' => Warranty::withoutGlobalScopes()->when($actorName === 'pelanggan', fn ($q) => $q->where('customer_id', $actor->id))->orderBy('id')->value('id'),
            'maintenance-schedules' => WarrantyMaintenanceSchedule::orderBy('id')->value('id'),
            'spks' => Spk::withoutGlobalScopes()->orderBy('id')->value('id'),
            'memos' => MaterialMemo::withoutGlobalScopes()->orderBy('id')->value('id'),
            'materials' => RawMaterial::orderBy('id')->value('id'),
            'consumables' => ConsumableItem::orderBy('id')->value('id'),
            'inventory' => InventoryItem::orderBy('id')->value('code'),
            'assets' => Asset::orderBy('id')->value('asset_tag'),
            'quotations' => Quotation::withoutGlobalScopes()->orderBy('id')->value('id'),
            'payroll' => Payroll::where('user_id', $actor?->id)->where('status', 'paid')->orderBy('id')->value('id'),
            'news' => News::where('is_published', true)->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))->orderBy('id')->value('slug'),
            'products' => 'ppf',
            default => $param === 'id' && $previous === 'api' ? $store() : null,
        };
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function hit(string $uri, mixed $actor, ?string $guard): array
    {
        try {
            if ($actor && $guard) {
                Auth::shouldUse($guard);
                Auth::guard($guard)->setUser($actor);
            }

            $request = Request::create($uri, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
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
