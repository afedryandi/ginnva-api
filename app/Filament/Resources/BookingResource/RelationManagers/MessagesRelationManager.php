<?php

namespace App\Filament\Resources\BookingResource\RelationManagers;

use App\Models\BookingMessage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Admin toko kelola chat + update progress instalasi dari sini — sampai
 * ekosistem login role-based di ginnva-mobile untuk staff toko dibangun,
 * ini jadi satu-satunya cara admin kirim pesan/update tahap ke customer.
 * Pesan yang dikirim dari sini langsung muncul di chat mobile app
 * customer + trigger push notification (lihat BookingMessageObserver).
 */
class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    protected static ?string $title = 'Chat & Progress Instalasi';

    protected static ?string $modelLabel = 'Pesan';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('type')
                ->label('Jenis Pesan')
                ->options([
                    'text'  => '💬 Pesan Teks',
                    'photo' => '📷 Foto (tanpa update tahap)',
                    'stage' => '✅ Update Tahap Progress',
                ])
                ->default('text')
                ->required()
                ->live(),

            Forms\Components\Select::make('stage')
                ->label('Tahap')
                ->options(BookingMessage::STAGES)
                ->required(fn (Forms\Get $get) => $get('type') === 'stage')
                ->visible(fn (Forms\Get $get) => $get('type') === 'stage'),

            Forms\Components\FileUpload::make('photo_path')
                ->label('Foto')
                ->image()
                ->directory('booking-messages')
                ->maxSize(4096)
                ->required(fn (Forms\Get $get) => $get('type') === 'photo')
                ->visible(fn (Forms\Get $get) => in_array($get('type'), ['photo', 'stage'])),

            Forms\Components\Textarea::make('body')
                ->label(fn (Forms\Get $get) => $get('type') === 'text' ? 'Pesan' : 'Keterangan (opsional)')
                ->required(fn (Forms\Get $get) => $get('type') === 'text')
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('body')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('sender_type')
                    ->label('Pengirim')
                    ->colors([
                        'primary' => 'admin',
                        'gray'    => 'customer',
                        'warning' => 'system',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'admin'    => '🏪 Toko',
                        'customer' => '👤 Customer',
                        'system'   => '⚙️ Sistem',
                        default    => $state,
                    }),

                Tables\Columns\BadgeColumn::make('type')
                    ->label('Jenis')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'text'  => 'Teks',
                        'photo' => 'Foto',
                        'stage' => 'Update Tahap',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('stage')
                    ->label('Tahap')
                    ->formatStateUsing(fn (?string $state): string => $state ? (BookingMessage::STAGES[$state] ?? $state) : '—'),

                Tables\Columns\ImageColumn::make('photo_path')
                    ->label('Foto')
                    ->square(),

                Tables\Columns\TextColumn::make('body')
                    ->label('Isi Pesan')
                    ->limit(40)
                    ->placeholder('—'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Kirim Pesan / Update Progress')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['sender_type']    = 'admin';
                        $data['sender_user_id'] = auth()->id();

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
