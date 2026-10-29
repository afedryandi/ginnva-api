<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Setiap penolakan akses (403) dicatat sebagai peringatan "Akses ditolak (403)" berisi alamat, rute, akun, dan asal permintaan, supaya
 * 403 yang muncul kadang-kadang di panel bisa ditelusuri. Respons ke pengguna tidak berubah.
 */
class ForbiddenLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_forbidden_page_is_logged_with_the_user_and_path(): void
    {
        Http::fake();
        Log::spy();
        Role::findOrCreate('kasir', 'web');
        $user = tap(User::create(['name' => 'Rina', 'email' => uniqid() . '@test.local', 'password' => 'x']), fn (User $u) => $u->assignRole('kasir'));

        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));

        $this->actingAs($user, 'web')->get(\App\Filament\Resources\ActivityResource::getUrl('index'))->assertForbidden();

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === 'Akses ditolak (403)'
            && $context['user_id'] === $user->id
            && str_contains((string) $context['url'], 'activities'))->atLeast()->once();
    }

    public function test_an_allowed_request_is_not_logged(): void
    {
        Http::fake();
        Log::spy();

        $this->getJson('/api/staff/safety')->assertStatus(401);

        Log::shouldNotHaveReceived('warning', ['Akses ditolak (403)']);
    }
}
