<?php

namespace App\Filament\Pages;

use App\Http\Controllers\Api\NotificationController;
use App\Models\Customer;
use App\Models\DeviceToken;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
    protected static ?string $navigationGroup = 'Komunikasi';
    protected static ?int    $navigationSort  = 10;
    protected static string  $view            = 'filament.pages.send-notification';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['broadcast' => true]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
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
                    ->default(true),

                Select::make('customer_ids')
                    ->label('Pilih Pelanggan')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->options(fn () => Customer::query()->pluck('name', 'id'))
                    ->visible(fn ($get) => !$get('broadcast'))
                    ->requiredIf('broadcast', false),
            ])
            ->statePath('data');
    }

    public function send(): void
    {
        $state = $this->form->getState();

        // Delegate ke NotificationController supaya logika FCM v1 tidak duplikat
        $fakeRequest = Request::create('/api/notifications/send', 'POST', [
            'title'        => $state['title'],
            'body'         => $state['body'],
            'customer_ids' => empty($state['broadcast']) ? ($state['customer_ids'] ?? []) : null,
        ]);

        $controller = app(NotificationController::class);
        $response   = $controller->send($fakeRequest);
        $result     = $response->getData(true);

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

        $this->form->fill(['broadcast' => true]);
    }
}