<?php

namespace Tests\Feature;

use Filament\Resources\Resource;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Pencegahan struktural (diminta 2026-09-14, audit framework item
 * "Otorisasi default-deny") — mencegah bug class yang ditemukan
 * berulang di 20+ Resource sepanjang audit: Laravel Gate default-deny
 * SEMUA orang kalau sebuah model tidak punya Policy terdaftar DAN
 * Resource-nya tidak override canCreate()/canEdit()/canDelete() secara
 * eksplisit. Dua akibat berbeda tergantung Action yang dipakai:
 *   - Action BAWAAN Filament (CreateAction/EditAction/DeleteAction/
 *     DeleteBulkAction) auto-wired ke Gate → tombolnya TIDAK PERNAH
 *     muncul untuk siapa pun (aman tapi rusak).
 *   - Action CUSTOM (Tables\Actions\Action::make()/BulkAction::make())
 *     TIDAK auto-wired ke Gate sama sekali → kalau tidak diberi
 *     ->visible() manual, lolos TANPA PROTEKSI (lihat riwayat
 *     perbaikan MaterialMemoResource, VehicleResource, UserResource,
 *     FilmProductResource — kasus BulkAction 'delete' custom yang bisa
 *     dipakai staf mana pun tanpa Gate check).
 *
 * Test ini HANYA mendeteksi separuh pertama (Action bawaan tanpa
 * override) secara otomatis lewat Reflection + pemindaian source —
 * separuh kedua (Action custom tanpa ->visible()) butuh peninjauan
 * manual per kasus, tidak bisa diverifikasi generik lewat static
 * analysis sederhana ini.
 */
class FilamentResourceAuthorizationTest extends TestCase
{
    private const RESOURCE_NAMESPACE = 'App\\Filament\\Resources\\';

    private const RESOURCE_PATH_ACTION_MAP = [
        'canCreate' => ['CreateAction::make'],
        'canEdit' => ['EditAction::make'],
        'canDelete' => ['DeleteAction::make', 'DeleteBulkAction::make'],
    ];

    public function test_every_resource_using_builtin_actions_declares_explicit_authorization(): void
    {
        $violations = [];

        foreach (File::files(app_path('Filament/Resources')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = self::RESOURCE_NAMESPACE . $file->getFilenameWithoutExtension();

            if (! class_exists($class) || ! is_subclass_of($class, Resource::class)) {
                continue; // Bukan Resource top-level (mis. RelationManager di folder yang sama).
            }

            $source = File::get($file->getPathname());
            $reflection = new ReflectionClass($class);

            $model = $class::getModel();
            $hasPolicy = Gate::getPolicyFor($model) !== null;

            if ($hasPolicy) {
                continue; // Policy resmi terdaftar -- Laravel yang tangani otorisasi, tidak perlu override manual.
            }

            foreach (self::RESOURCE_PATH_ACTION_MAP as $method => $needles) {
                $usesBuiltinAction = collect($needles)->contains(fn ($needle) => str_contains($source, $needle));

                if (! $usesBuiltinAction) {
                    continue;
                }

                if (! $reflection->hasMethod($method)) {
                    $violations[] = "{$class}::{$method}() tidak ada, tapi table() memakai Action bawaan yang butuh Gate ini";
                    continue;
                }

                $declaringClass = (new ReflectionMethod($class, $method))->getDeclaringClass()->getName();
                if ($declaringClass !== $class) {
                    $violations[] = "{$class}::{$method}() masih warisan default Resource (default-deny), belum di-override walau table() memakai Action bawaan yang butuh Gate ini";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Resource berikut memakai Action bawaan Filament yang butuh Gate, tapi belum override method can*()-nya "
            . "(tombolnya tidak akan pernah muncul untuk siapa pun sampai di-override):\n\n"
            . implode("\n", $violations)
            . "\n\nLihat pola perbaikan di CaseStudyResource/MaterialResource/VehicleResource dkk (audit 2026-09-14)."
        );
    }
}
