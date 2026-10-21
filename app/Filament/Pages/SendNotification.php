<?php

namespace App\Filament\Pages;

use App\Models\Customer;
use App\Models\NotificationTemplate;
use App\Models\Partner;
use App\Services\NotificationCampaignService;
use App\Support\QuietHours;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Section;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SendNotification extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-bell';
    protected static ?string $navigationLabel = 'Kirim Notifikasi';
    // Dipindah ke NotifikasiCluster 2026-09-14 (struktur dropdown top-nav
    // "Lainnya" bertingkat — lihat catatan di MasterDataCluster.php).
    protected static ?string $cluster = \App\Filament\Clusters\NotifikasiCluster::class;
    protected static ?int    $navigationSort  = 5;
    protected static string  $view            = 'filament.pages.send-notification';

    public ?array $data = [];

    /** Pratinjau penerima terakhir (Notifikasi Fase 5); null sebelum tombol Pratinjau ditekan atau setelah kirim. */
    public ?array $preview = null;

    /** Rute tujuan deep link yang sah (harus sama dengan pilihan di form). */
    private const DEEP_LINK_ROUTES = [
        '/account/my-warranties', '/account/my-bookings', '/account/notifications', '/account/edit-profile',
        '/warranty/check', '/booking', '/quotation', '/partnership',
        '/news', '/brand', '/products',
    ];

    private function customerLabel($customer): string
    {
        return $customer->name
            ? "{$customer->name} ({$customer->email})"
            : ($customer->email ?? "Customer #{$customer->id}");
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public function mount(): void
    {
        $this->form->fill($this->defaults());
    }

    /** @return array<string, mixed> */
    private function defaults(string $audience = 'customer'): array
    {
        return $audience === 'partner'
            ? ['audience' => 'partner', 'broadcast_partner' => true]
            : ['audience' => 'customer', 'broadcast' => true, 'category' => 'promo'];
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Radio::make('audience')
                    ->label('Kirim Ke')
                    ->options([
                        'customer' => 'Customer (app utama)',
                        'partner'  => 'Partner (mitra referral)',
                    ])
                    ->default('customer')
                    ->inline()
                    ->live()
                    ->required(),

                Radio::make('category')
                    ->label('Jenis Pesan')
                    ->options([
                        'promo' => 'Promosi (diskon, penawaran, ajakan membeli)',
                        'info'  => 'Informasi penting (jadwal, perubahan layanan, pemberitahuan)',
                    ])
                    ->descriptions([
                        'promo' => 'Hanya dikirim ke pelanggan yang sudah menyetujui menerima promo di aplikasi. Dibatasi beberapa kali per minggu dan tidak dikirim pada jam tenang (' . QuietHours::label() . ').',
                        'info'  => 'Dikirim ke semua pelanggan. Jangan dipakai untuk promosi.',
                    ])
                    ->default('promo')
                    ->required()
                    ->visible(fn ($get) => $get('audience') === 'customer'),

                // Templat (Notifikasi Fase 5): memilih templat mengisi judul, isi, jenis pesan, dan deep link; tidak ikut dikirim.
                Select::make('template_id')
                    ->label('Pakai Templat (opsional)')
                    ->placeholder('— Tulis pesan baru —')
                    ->options(fn (Get $get) => NotificationTemplate::query()
                        ->where('audience', $get('audience') === 'partner' ? 'partner' : 'customer')
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->live()
                    ->dehydrated(false)
                    ->afterStateUpdated(function ($state, Set $set) {
                        $template = $state ? NotificationTemplate::find($state) : null;

                        if (! $template) {
                            return;
                        }

                        $set('title', $template->title);
                        $set('body', $template->body);

                        if ($template->category) {
                            $set('category', $template->category);
                        }

                        $set('deep_link_route', $template->data['deep_link_route'] ?? null);
                        $set('deep_link_param_id', $template->data['deep_link_param_id'] ?? null);
                    }),

                TextInput::make('title')
                    ->label('Judul Notifikasi')
                    ->required()
                    ->maxLength(200)
                    ->placeholder('Promo Spesial Ginnva!'),

                Textarea::make('body')
                    ->label('Isi Pesan')
                    ->required()
                    ->maxLength(500)
                    ->rows(3)
                    ->placeholder('Dapatkan diskon 20% untuk pemasangan PPF bulan ini...'),

                Toggle::make('broadcast')
                    ->label('Kirim ke semua pengguna')
                    ->live()
                    ->default(true)
                    ->visible(fn ($get) => $get('audience') === 'customer'),

                Select::make('customer_ids')
                    ->label('Pilih Pelanggan')
                    ->multiple()
                    ->searchable()
                    // Pencarian bertahap (maks. 50 hasil), bukan memuat SEMUA pelanggan ke dropdown tiap halaman dibuka --
                    // makin berat begitu basis pelanggan tumbuh.
                    ->getSearchResultsUsing(fn (string $search) => Customer::query()
                        ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('phone_number', 'like', "%{$search}%"))
                        ->orderBy('name')
                        ->limit(50)
                        ->get(['id', 'name', 'email'])
                        ->mapWithKeys(fn ($c) => [$c->id => $this->customerLabel($c)])
                        ->all())
                    ->getOptionLabelsUsing(fn (array $values) => Customer::query()
                        ->whereIn('id', $values)
                        ->get(['id', 'name', 'email'])
                        ->mapWithKeys(fn ($c) => [$c->id => $this->customerLabel($c)])
                        ->all())
                    ->visible(fn ($get) => $get('audience') === 'customer' && !$get('broadcast'))
                    ->requiredIf('broadcast', false),

                Toggle::make('broadcast_partner')
                    ->label('Kirim ke semua partner')
                    ->live()
                    ->default(true)
                    ->visible(fn ($get) => $get('audience') === 'partner'),

                Select::make('partner_ids')
                    ->label('Pilih Partner')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->options(fn () => Partner::query()
                        ->get(['id', 'business_name', 'referral_code'])
                        ->mapWithKeys(fn ($p) => [$p->id => "{$p->business_name} ({$p->referral_code})"])
                    )
                    ->visible(fn ($get) => $get('audience') === 'partner' && !$get('broadcast_partner'))
                    ->requiredIf('broadcast_partner', false),

                Section::make('Deep Link (Opsional)')
                    ->description('Jika diisi, tap notifikasi akan membuka halaman tertentu di aplikasi. Khusus notifikasi Customer.')
                    ->collapsed()
                    ->visible(fn ($get) => $get('audience') === 'customer')
                    ->schema([
                        Select::make('deep_link_route')
                            ->label('Tujuan Halaman')
                            // Hanya rute yang ada di daftar yang boleh dikirim ke aplikasi (nilai kiriman tidak otomatis
                            // divalidasi terhadap pilihan).
                            ->in(self::DEEP_LINK_ROUTES)
                            ->placeholder('— Tidak ada (buka beranda) —')
                            ->options([
                                'Akun'     => [
                                    '/account/my-warranties'  => 'Garansi Saya',
                                    '/account/my-bookings'    => 'Booking Saya',
                                    '/account/notifications'  => 'Notifikasi',
                                    '/account/edit-profile'   => 'Edit Profil',
                                ],
                                'Layanan'  => [
                                    '/warranty/check'         => 'Cek Garansi (scan QR)',
                                    '/booking'                => 'Buat Booking',
                                    '/quotation'              => 'Ajukan Penawaran',
                                    '/partnership'            => 'Ajukan Kemitraan',
                                ],
                                'Konten'   => [
                                    '/news'                   => 'Daftar Berita',
                                    '/brand'                  => 'Tentang Brand',
                                    '/products'               => 'Produk',
                                ],
                            ])
                            ->live(),

                        TextInput::make('deep_link_param_id')
                            ->label('ID Record (opsional)')
                            ->placeholder('contoh: 42')
                            ->helperText('Isi ID garansi/booking jika tujuan adalah halaman detail spesifik.')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->visible(fn ($get) => in_array($get('deep_link_route'), [
                                '/account/my-warranties',
                                '/account/my-bookings',
                            ])),
                    ]),

                // Kirim terjadwal (Notifikasi Fase 5). Waktu diisi dalam WIB.
                Toggle::make('schedule')
                    ->label('Jadwalkan pengiriman')
                    ->helperText('Kampanye dikirim otomatis pada waktu yang dipilih. Promosi tidak bisa dijadwalkan pada jam tenang (' . QuietHours::label() . ').')
                    ->live()
                    ->default(false),

                DateTimePicker::make('scheduled_at')
                    ->label('Waktu Kirim (WIB)')
                    ->timezone('Asia/Jakarta')
                    ->seconds(false)
                    ->required()
                    ->visible(fn ($get) => (bool) $get('schedule')),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('saveTemplate')
                ->label('Simpan sebagai templat')
                ->icon('heroicon-o-bookmark')
                ->color('gray')
                ->modalHeading('Simpan sebagai templat')
                ->modalDescription('Judul, isi, jenis pesan, dan deep link yang sedang diisi disimpan untuk dipakai lagi.')
                ->form([
                    TextInput::make('name')->label('Nama Templat')->required()->maxLength(100),
                ])
                ->action(function (array $data): void {
                    $state = $this->data;

                    if (blank($state['title'] ?? null) || blank($state['body'] ?? null)) {
                        Notification::make()->title('Isi judul dan pesan dulu')->body('Templat tidak bisa disimpan tanpa judul dan isi pesan.')->danger()->send();

                        return;
                    }

                    $partner = ($state['audience'] ?? 'customer') === 'partner';

                    NotificationTemplate::create([
                        'name'       => $data['name'],
                        'audience'   => $partner ? 'partner' : 'customer',
                        'category'   => $partner ? null : (($state['category'] ?? 'promo') === 'info' ? 'info' : 'promo'),
                        'title'      => $state['title'],
                        'body'       => $state['body'],
                        'data'       => $partner || blank($state['deep_link_route'] ?? null) ? null : [
                            'deep_link_route'    => $state['deep_link_route'],
                            'deep_link_param_id' => $state['deep_link_param_id'] ?? null,
                        ],
                        'created_by' => auth()->id(),
                    ]);

                    Notification::make()->title('Templat disimpan')->body("Templat \"{$data['name']}\" bisa dipilih lewat \"Pakai Templat\".")->success()->send();
                }),
        ];
    }

    /** Pratinjau penerima: berapa yang akan dikirimi dan kenapa sebagian dikecualikan, tanpa mengirim apa pun. */
    public function previewRecipients(): void
    {
        $this->preview = app(NotificationCampaignService::class)->preview($this->form->getState());
    }

    public function send(): void
    {
        $state = $this->form->getState();
        $service = app(NotificationCampaignService::class);
        $isPartner = ($state['audience'] ?? 'customer') === 'partner';
        $this->preview = null;

        if (! empty($state['schedule'])) {
            $this->schedule($state, $isPartner);

            return;
        }

        $campaign = $service->create($state, null, auth()->id());
        $outcome = $service->dispatch($campaign);

        if (! $outcome['ok']) {
            Notification::make()
                ->title('Gagal mengirim notifikasi')
                ->body($outcome['message'])
                ->danger()
                ->send();

            return;
        }

        $result = $outcome['result'];
        $sent   = $result['sent']   ?? 0;
        $failed = $result['failed'] ?? 0;

        $title = "Terkirim: {$sent} perangkat" . ($failed > 0 ? ", gagal: {$failed}" : '');
        $body = null;

        if (isset($result['recipients'])) {
            $title = "Promosi dikirim ke {$result['recipients']} pelanggan yang setuju promo ({$sent} perangkat)" . ($failed > 0 ? ", gagal: {$failed}" : '');
            $body = ($result['skipped'] ?? 0) > 0 ? "{$result['skipped']} pelanggan yang dipilih dilewati karena belum menyetujui promo." : null;
            if (($result['recipients'] ?? 0) === 0) {
                $title = $result['message'] ?? 'Tidak ada penerima.';
            }
        }

        Notification::make()
            ->title($title)
            ->body($body)
            ->success()
            ->send();

        $this->form->fill($this->defaults($isPartner ? 'partner' : 'customer'));
    }

    /** Simpan sebagai kampanye terjadwal; pengiriman dilakukan oleh notifications:send-scheduled. */
    private function schedule(array $state, bool $isPartner): void
    {
        $at = Carbon::parse($state['scheduled_at'], config('app.timezone'));

        if ($at->lt(now()->addMinutes(2))) {
            Notification::make()->title('Waktu kirim terlalu dekat')->body('Pilih waktu minimal 2 menit dari sekarang.')->danger()->send();

            return;
        }

        if (! $isPartner && ($state['category'] ?? 'promo') === 'promo' && QuietHours::active($at)) {
            Notification::make()
                ->title('Waktu kirim jatuh pada jam tenang')
                ->body('Promosi tidak dikirim pada ' . QuietHours::label() . '. Pilih waktu di luar jam tersebut.')
                ->danger()
                ->send();

            return;
        }

        app(NotificationCampaignService::class)->create($state, $at, auth()->id());

        Notification::make()
            ->title('Kampanye dijadwalkan')
            ->body('Dikirim otomatis pada ' . $at->copy()->setTimezone('Asia/Jakarta')->format('d M Y, H:i') . ' WIB. Pantau di Kampanye Notifikasi.')
            ->success()
            ->send();

        $this->form->fill($this->defaults($isPartner ? 'partner' : 'customer'));
    }
}
