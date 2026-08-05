<?php

namespace App\Filament\Pages;

use App\Http\Controllers\Api\NotificationController;
use App\Models\Customer;
use App\Models\DeviceToken;
use App\Models\Partner;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Section;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\Request;

class SendNotification extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-bell';
    protected static ?string $navigationLabel = 'Kirim Notifikasi';
    protected static ?string $navigationGroup = 'Sistem';
    protected static ?int    $navigationSort  = 5;
    protected static string  $view            = 'filament.pages.send-notification';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public function mount(): void
    {
        $this->form->fill(['audience' => 'customer', 'broadcast' => true]);
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
                    ->preload()
                    ->options(fn () => Customer::query()
                        ->get(['id', 'name', 'email'])
                        ->mapWithKeys(fn ($c) => [
                            $c->id => $c->name
                                ? "{$c->name} ({$c->email})"
                                : ($c->email ?? "Customer #{$c->id}"),
                        ])
                    )
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
                            ->visible(fn ($get) => in_array($get('deep_link_route'), [
                                '/account/my-warranties',
                                '/account/my-bookings',
                            ])),
                    ]),
            ])
            ->statePath('data');
    }

    public function send(): void
    {
        $state = $this->form->getState();

        if (($state['audience'] ?? 'customer') === 'partner') {
            $this->sendToPartners($state);
            return;
        }

        // Route yang butuh ID record — field deep_link_param_id cuma
        // relevan untuk dua ini (lihat ->visible() di form() di atas).
        $routesNeedingId = ['/account/my-warranties', '/account/my-bookings'];

        // Susun data deep link kalau ada route yang dipilih. Field
        // deep_link_param_id pakai ->visible() (bukan ->disabled()), yang
        // TIDAK mencegah nilainya ikut ter-submit walau field-nya
        // tersembunyi — kalau staff sempat isi ID lalu ganti pikiran pilih
        // route lain yang tidak butuh ID, nilai lama itu tetap ada di
        // $state. Dicek ulang di sini terhadap $routesNeedingId supaya ID
        // basi tidak ikut terkirim untuk route yang tidak membutuhkannya.
        $deepLinkData = null;
        if (! empty($state['deep_link_route'])) {
            $deepLinkData = ['route' => $state['deep_link_route']];

            if (
                in_array($state['deep_link_route'], $routesNeedingId, true)
                && ! empty($state['deep_link_param_id'])
            ) {
                $deepLinkData['params'] = ['id' => (string) $state['deep_link_param_id']];
            }
        }

        // Delegate ke NotificationController supaya logika FCM v1 tidak duplikat
        $fakeRequest = Request::create('/api/notifications/send', 'POST', [
            'title'        => $state['title'],
            'body'         => $state['body'],
            'customer_ids' => empty($state['broadcast']) ? ($state['customer_ids'] ?? []) : null,
            'data'         => $deepLinkData,
        ]);

        // $controller->send() dipanggil langsung (bukan lewat HTTP kernel),
        // jadi ValidationException dari $request->validate() di dalamnya
        // TIDAK otomatis ditangkap & dikonversi ke response seperti request
        // HTTP normal — akan lolos sebagai error mentah ke Livewire kalau
        // tidak ditangkap di sini (mis. kalau ada customer_id yang basi,
        // sempat dihapus sebelum form ini disubmit).
        try {
            $controller = app(NotificationController::class);
            $response   = $controller->send($fakeRequest);
            $result     = $response->getData(true);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Notification::make()
                ->title('Gagal mengirim notifikasi')
                ->body(collect($e->errors())->flatten()->implode(' '))
                ->danger()
                ->send();
            return;
        }

        if ($response->getStatusCode() >= 400) {
            Notification::make()
                ->title('Gagal mengirim notifikasi')
                ->body($result['message'] ?? 'Terjadi kesalahan.')
                ->danger()
                ->send();
            return;
        }

        $sent   = $result['sent']   ?? 0;
        $failed = $result['failed'] ?? 0;

        Notification::make()
            ->title("Terkirim: {$sent} perangkat" . ($failed > 0 ? ", gagal: {$failed}" : ''))
            ->success()
            ->send();

        $this->form->fill(['audience' => 'customer', 'broadcast' => true]);
    }

    /**
     * Delegate ke NotificationController::sendPartner() supaya logika FCM
     * v1 & penyimpanan history tidak duplikat — lihat catatan di send()
     * di atas untuk kenapa dipanggil lewat fake Request, bukan langsung.
     */
    private function sendToPartners(array $state): void
    {
        $fakeRequest = Request::create('/api/partner-notifications/send', 'POST', [
            'title'       => $state['title'],
            'body'        => $state['body'],
            'partner_ids' => empty($state['broadcast_partner']) ? ($state['partner_ids'] ?? []) : null,
        ]);

        try {
            $controller = app(NotificationController::class);
            $response    = $controller->sendPartner($fakeRequest);
            $result      = $response->getData(true);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Notification::make()
                ->title('Gagal mengirim notifikasi')
                ->body(collect($e->errors())->flatten()->implode(' '))
                ->danger()
                ->send();
            return;
        }

        if ($response->getStatusCode() >= 400) {
            Notification::make()
                ->title('Gagal mengirim notifikasi')
                ->body($result['message'] ?? 'Terjadi kesalahan.')
                ->danger()
                ->send();
            return;
        }

        $sent   = $result['sent']   ?? 0;
        $failed = $result['failed'] ?? 0;

        Notification::make()
            ->title("Terkirim: {$sent} perangkat" . ($failed > 0 ? ", gagal: {$failed}" : ''))
            ->success()
            ->send();

        $this->form->fill(['audience' => 'partner', 'broadcast_partner' => true]);
    }
}