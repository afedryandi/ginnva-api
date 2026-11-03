<?php

namespace App\Filament\Pages;

use App\Models\Attendance;
use App\Support\SensitiveFiles;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * "Absen Saya" (2026-11-03): absen masuk dan keluar dari panel untuk karyawan yang belum bisa memakai aplikasi staff (mis. iPhone sebelum
 * aplikasi iOS tersedia). Memakai jalur yang SAMA dengan aplikasi: Attendance::clockIn()/clockOut() beserta radius toko dan selfie wajib.
 * Lokasi diambil dari GPS browser dan selfie dari kamera depan (atribut capture). Browser tidak melaporkan lokasi palsu, jadi penanda
 * is_mocked dikirim kosong, sama seperti aplikasi iOS.
 */
class MyAttendance extends Page
{
    use WithFileUploads;

    protected static ?string $navigationIcon = 'heroicon-o-finger-print';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationGroup = 'Absensi & Cuti';

    protected static ?string $navigationLabel = 'Absen Saya';

    protected static ?string $title = 'Absen Saya';

    // Di atas Absensi Karyawan (100) dalam grup yang sama.
    protected static ?int $navigationSort = 99;

    protected static string $view = 'filament.pages.my-attendance';

    /** @var TemporaryUploadedFile|null */
    public $photo = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->canAccessStaffArea()
            && ! $user->isAttendanceExempt()
            && $user->hasMenuAccess(static::class);
    }

    public function today(): ?Attendance
    {
        return Attendance::where('user_id', auth()->id())
            ->where('date', Carbon::today()->toDateString())
            ->first();
    }

    public function store(): ?\App\Models\Store
    {
        return auth()->user()?->store;
    }

    public function radiusMeters(): int
    {
        return (int) ($this->store()?->attendance_radius_meters ?? Attendance::DEFAULT_RADIUS_METERS);
    }

    /** Dipanggil dari halaman sesudah lokasi didapat. $kind: 'in' (masuk) atau 'out' (keluar). */
    public function submitClock(string $kind, float $latitude, float $longitude): void
    {
        abort_unless(static::canAccess(), 403);

        if (! in_array($kind, ['in', 'out'], true) || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            Notification::make()->title('Lokasi tidak valid')->body('Coba ambil lokasi lagi.')->danger()->send();

            return;
        }

        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            Notification::make()->title('Tidak bisa absen')->body('Akun ini belum terhubung ke toko mana pun.')->danger()->send();

            return;
        }

        $this->validate(
            ['photo' => 'required|image|max:5120|dimensions:min_width=200,min_height=200'],
            [
                'photo.required' => 'Ambil foto selfie dulu sebelum absen.',
                'photo.image' => 'Berkas harus berupa foto.',
                'photo.max' => 'Ukuran foto maksimal 5 MB.',
                'photo.dimensions' => 'Foto terlalu kecil (minimal 200 x 200 piksel).',
            ]
        );

        $photoPath = SensitiveFiles::store($this->photo, 'attendance-photos');

        try {
            $attendance = $kind === 'in'
                ? Attendance::clockIn($user, $store, $latitude, $longitude, null, $photoPath)
                : Attendance::clockOut($user, $latitude, $longitude, null, $photoPath);
        } catch (\InvalidArgumentException $e) {
            SensitiveFiles::delete($photoPath);
            Notification::make()->title('Absen ditolak')->body($e->getMessage())->danger()->send();

            return;
        } catch (\Throwable $e) {
            SensitiveFiles::delete($photoPath);

            throw $e;
        }

        $this->photo = null;

        Notification::make()
            ->title($kind === 'in' ? 'Absen masuk tercatat' : 'Absen keluar tercatat')
            ->body('Pukul ' . ($kind === 'in' ? $attendance->clock_in_at : $attendance->clock_out_at)?->format('H:i'))
            ->success()
            ->send();
    }
}
