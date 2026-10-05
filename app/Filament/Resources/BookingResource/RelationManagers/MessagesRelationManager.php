<?php

namespace App\Filament\Resources\BookingResource\RelationManagers;

use App\Models\BookingMessage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Riwayat chat + progress instalasi (BACA-SAJA sejak 2026-10-06). Pengiriman
 * pesan/tahap/foto lewat mobile staff; hapus pesan hanya full access.
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
                ->options(BookingMessage::allStages())
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
                    ->formatStateUsing(fn (?string $state): string => $state ? (BookingMessage::allStages()[$state] ?? $state) : '—'),

                Tables\Columns\TextColumn::make('photos_count')
                    ->label('Jumlah Foto')
                    ->counts('photos')
                    ->placeholder('—'),

                Tables\Columns\ImageColumn::make('photos.path')
                    ->label('Foto Progress')
                    ->disk('public')
                    ->square()
                    ->stacked()
                    ->limit(4)
                    ->limitedRemainingText()
                    ->extraImgAttributes(['loading' => 'lazy']),

                // Kolom lama (pesan sebelum tabel booking_message_photos ada).
                Tables\Columns\ImageColumn::make('photo_path')
                    ->label('Foto')
                    ->square(),

                Tables\Columns\TextColumn::make('body')
                    ->label('Isi Pesan')
                    ->limit(40)
                    ->placeholder('—'),
            ])
            // Baca-saja (keputusan 2026-10-06): kirim pesan/update tahap/foto
            // HANYA lewat mobile staff supaya semua aturan API (tahap per produk,
            // status booking, QC, validasi foto) berlaku. Filament tidak lagi
            // melewati guard itu.
            ->headerActions([])
            ->actions([
                // Chat & foto di sini dokumentasi penting (kondisi
                // kendaraan, riwayat komunikasi) yang relevan untuk
                // dispute/klaim garansi — Store Manager/staff toko TIDAK
                // boleh hapus pesan siapa pun (termasuk pesan customer).
                // Cuma super_admin/direksi untuk kasus khusus (spam, salah
                // kirim data sensitif, dll).
                Tables\Actions\DeleteAction::make()
                    ->visible(fn () => auth()->user()?->isFullAccess()),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
