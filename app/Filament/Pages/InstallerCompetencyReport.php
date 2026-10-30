<?php

namespace App\Filament\Pages;

use App\Models\Store;
use App\Models\Technician;
use App\Models\TechnicianServiceRate;
use App\Models\TechnicianSkill;
use App\Models\TechnicianTraining;
use App\Services\InstallerQualityService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Kompetensi Installer" (Karyawan Fase 4, 2026-10-31): matriks keterampilan teknisi per layanan, sertifikat yang kedaluwarsa atau hampir
 * kedaluwarsa, dan mutu pekerjaan (lulus QC pertama, pekerjaan ulang) per installer pada rentang tanggal. Untuk PEMBINAAN, bukan
 * dasar potongan gaji atau komisi.
 */
class InstallerCompetencyReport extends Page implements HasForms
{
    use InteractsWithForms;
    use \App\Filament\Pages\Concerns\ExportsReportTable;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationGroup = 'Kinerja Installer';

    protected static ?string $navigationLabel = 'Kompetensi Installer';

    protected static ?string $title = 'Kompetensi & Mutu Installer';

    protected static ?int $navigationSort = 502;

    protected static string $view = 'filament.pages.installer-competency-report';

    public ?array $data = [];

    /** Urutan tabel mutu: name | rate (lulus QC pertama terendah dulu) | rework (pekerjaan ulang terbanyak dulu). */
    public string $qualitySort = 'name';

    /** Installer (user id) yang rincian pekerjaannya sedang dibuka di tabel mutu. */
    public ?int $expandedUserId = null;

    /**
     * Mutu dan sertifikat per installer (bahan pembinaan): akses penuh, store manager (untuk installer tokonya), atau staf yang menunya
     * dicentang eksplisit. Akun bawaan (menu_access kosong) tidak melihatnya; daftar menu yang dibatasi tetap dihormati.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class)
            && ($user->isFullAccess() || $user->isStoreManager() || is_array($user->menu_access));
    }

    public function mount(): void
    {
        $this->form->fill([
            'store_id' => auth()->user()?->isFullAccess() ? null : auth()->user()?->store_id,
            'from' => now()->subDays(89)->toDateString(),
            'to' => now()->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('store_id')
                ->label('Toko')
                ->placeholder('Semua toko')
                ->options(fn () => Store::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->disabled(fn () => ! (auth()->user()?->isFullAccess() ?? false))
                ->live(),
            DatePicker::make('from')->label('Mutu dari')->live()->maxDate(today()),
            DatePicker::make('to')->label('Sampai')->live()->maxDate(today()),
        ])->columns(3)->statePath('data');
    }

    private function storeId(): ?int
    {
        $user = auth()->user();

        if (! ($user?->isFullAccess() ?? false)) {
            return $user?->store_id ? (int) $user->store_id : null;
        }

        return ! empty($this->data['store_id']) ? (int) $this->data['store_id'] : null;
    }

    protected function getHeaderActions(): array
    {
        return $this->exportActions();
    }

    protected function reportExportData(): array
    {
        $technicians = $this->getTechnicians();
        $services = $this->serviceLabels();
        $from = ! empty($this->data['from']) ? Carbon::parse($this->data['from']) : now()->subDays(89);
        $to = ! empty($this->data['to']) ? Carbon::parse($this->data['to']) : now();
        $storeName = $this->storeId() ? Store::whereKey($this->storeId())->value('name') : null;

        return [
            'key' => 'kompetensi-installer',
            'title' => 'Kompetensi & Mutu Installer',
            'subtitle' => 'Mutu ' . $from->translatedFormat('d F Y') . ' - ' . $to->translatedFormat('d F Y') . ' · ' . ($storeName ? "Toko {$storeName}" : 'Semua toko'),
            'money' => false,
            'sections' => [
                [
                    'title' => 'Matriks keterampilan',
                    'headings' => array_merge(['Installer', 'Toko'], array_values($services)),
                    'rows' => $technicians->map(fn (Technician $t) => array_merge(
                        [$t->name, $t->store?->name ?? '—'],
                        array_map(fn (string $key) => $this->levelLabel($t->skills->firstWhere('service_type', $key)), array_keys($services)),
                    ))->all(),
                ],
                [
                    'title' => 'Sertifikat kedaluwarsa atau hampir kedaluwarsa',
                    'headings' => ['Installer', 'Pelatihan', 'Status', 'Berlaku sampai'],
                    'rows' => $this->getExpiringTrainings($technicians)->map(fn (array $row) => [
                        $row['technician']->name,
                        $row['training']->title,
                        $row['training']->isExpired() ? 'Kedaluwarsa' : 'Hampir kedaluwarsa',
                        $row['training']->valid_until?->format('d/m/Y'),
                    ])->all(),
                ],
                [
                    'title' => 'Mutu pekerjaan',
                    'headings' => ['Installer', 'Toko', 'Pekerjaan selesai', 'Diperiksa QC', 'Lulus QC pertama', '% Lulus', 'Pernah gagal QC', 'Pekerjaan ulang dicatat'],
                    'rows' => $this->getQuality()->map(fn (array $row) => [
                        $row['name'] . ($row['is_active'] ? '' : ' (nonaktif)'), $row['store'] ?? '—', $row['jobs'], $row['checked'], $row['first_pass'],
                        $row['first_pass_rate'] !== null ? number_format($row['first_pass_rate'], 0) . '%' : null,
                        $row['reworked'], $row['rework_recorded'],
                    ])->all(),
                    'right' => [2, 3, 4, 5, 6, 7],
                ],
            ],
            'note' => 'Mutu untuk pembinaan, bukan dasar potongan. Pada booking tim, semua installer bertugas dihitung sama. Persentase baru berarti bila jumlah pekerjaan yang diperiksa cukup banyak.',
        ];
    }

    /** @return array<string, string> kunci layanan => label */
    public function serviceLabels(): array
    {
        return TechnicianServiceRate::SERVICE_TYPE_LABELS;
    }

    /** @return Collection<int, Technician> teknisi aktif beserta keterampilan dan pelatihannya */
    public function getTechnicians(): Collection
    {
        return Technician::query()
            ->where('status', '!=', 'inactive')
            ->when($this->storeId(), fn ($q, $storeId) => $q->where('store_id', $storeId))
            ->with(['skills', 'trainings', 'store:id,name'])
            ->orderBy('name')
            ->get();
    }

    public function levelLabel(?TechnicianSkill $skill): string
    {
        return $skill ? (TechnicianSkill::LEVELS[$skill->level] ?? '—') : 'Belum dinilai';
    }

    /** Sertifikat yang sudah atau hampir kedaluwarsa dari teknisi yang tampil. */
    public function getExpiringTrainings(Collection $technicians): Collection
    {
        return $technicians->flatMap(fn (Technician $t) => $t->trainings->map(fn (TechnicianTraining $tr) => ['technician' => $t, 'training' => $tr]))
            ->filter(fn (array $row) => $row['training']->isExpired() || $row['training']->expiresSoon())
            // Sertifikat yang sudah diperbarui (ada catatan baru yang sama judulnya dan berlaku lebih lama) tidak lagi dihitung kedaluwarsa.
            ->reject(fn (array $row) => $row['training']->isSupersededBy($row['technician']->trainings))
            ->sortBy(fn (array $row) => $row['training']->valid_until)
            ->values();
    }

    public function getQuality(): Collection
    {
        $rows = app(InstallerQualityService::class)->forPeriod($this->qualityFrom(), $this->qualityTo(), $this->storeId());

        return match ($this->qualitySort) {
            // Yang paling perlu perhatian di atas; yang belum pernah diperiksa QC (tanpa persentase) di akhir.
            'rate' => $rows->sortBy(fn (array $r) => [$r['first_pass_rate'] === null ? 1 : 0, $r['first_pass_rate'] ?? 0, $r['name']])->values(),
            'rework' => $rows->sortBy(fn (array $r) => [-($r['reworked'] + $r['rework_recorded']), $r['name']])->values(),
            default => $rows,
        };
    }

    private function qualityFrom(): Carbon
    {
        return ! empty($this->data['from']) ? Carbon::parse($this->data['from']) : now()->subDays(89);
    }

    private function qualityTo(): Carbon
    {
        return ! empty($this->data['to']) ? Carbon::parse($this->data['to']) : now();
    }

    public function sortQuality(string $by): void
    {
        $this->qualitySort = in_array($by, ['name', 'rate', 'rework'], true) ? $by : 'name';
    }

    /** Buka atau tutup rincian pekerjaan seorang installer; hanya yang tampil di tabel mutu yang bisa dibuka. */
    public function toggleQualityJobs(int $userId): void
    {
        if ($this->expandedUserId === $userId) {
            $this->expandedUserId = null;

            return;
        }

        $this->expandedUserId = $this->getQuality()->contains('user_id', $userId) ? $userId : null;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getQualityJobs(): Collection
    {
        if (! $this->expandedUserId || ! $this->getQuality()->contains('user_id', $this->expandedUserId)) {
            return collect();
        }

        return app(InstallerQualityService::class)->jobsFor($this->expandedUserId, $this->qualityFrom(), $this->qualityTo(), $this->storeId());
    }

    public function bookingUrl(?int $bookingId): ?string
    {
        return $bookingId && \App\Filament\Resources\BookingResource::canViewAny()
            ? \App\Filament\Resources\BookingResource::getUrl('view', ['record' => $bookingId])
            : null;
    }
}
