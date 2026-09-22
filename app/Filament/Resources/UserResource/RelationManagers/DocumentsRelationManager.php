<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\EmployeeDocument;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

/**
 * "Dokumen" personalia karyawan (audit Majoo f54) — KTP/NPWP/Ijazah/
 * Kontrak/dll. TERBATAS full-access, sama filosofi Data Personalia
 * (HRIS) di form utama. File disimpan di disk PRIVATE (local),
 * dibaca lewat aksi "Download" yang terautentikasi -- BUKAN URL
 * publik langsung.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Dokumen';

    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public function isReadOnly(): bool
    {
        return ! (auth()->user()?->isFullAccess() ?? false);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('type')
                ->label('Jenis Dokumen')
                ->options(EmployeeDocument::TYPE_LABELS)
                ->required(),

            Forms\Components\FileUpload::make('file_path')
                ->label('File')
                ->disk('local')
                ->directory('employee-documents')
                ->visibility('private')
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                ->required()
                ->helperText('PDF atau gambar (JPG/PNG), maks sesuai batas upload server.'),

            Forms\Components\Textarea::make('notes')
                ->label('Catatan (opsional)')
                ->rows(2),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('type')
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->label('Jenis')
                    ->formatStateUsing(fn (string $state) => EmployeeDocument::TYPE_LABELS[$state] ?? $state)
                    ->badge(),

                Tables\Columns\TextColumn::make('original_filename')
                    ->label('Nama File')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('notes')
                    ->label('Catatan')
                    ->limit(40)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('uploadedBy.name')
                    ->label('Diunggah Oleh')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu Unggah')
                    ->dateTime('d M Y, H:i'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data) {
                        // FileUpload::disk('local') menyimpan path RELATIF
                        // ke disk itu -- simpan juga nama file asli
                        // (client) sebelum di-hash Filament, supaya staff
                        // tetap kenal filenya dari daftar.
                        $data['original_filename'] = basename($data['file_path']);
                        $data['uploaded_by'] = auth()->id();

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(fn (EmployeeDocument $record) => Storage::disk('local')->download($record->file_path, $record->original_filename)),

                Tables\Actions\DeleteAction::make()
                    ->action(function (EmployeeDocument $record) {
                        Storage::disk('local')->delete($record->file_path);
                        $record->delete();
                    }),
            ])
            ->emptyStateHeading('Belum ada dokumen')
            ->emptyStateDescription('Upload KTP, NPWP, ijazah, kontrak kerja, atau dokumen lain milik karyawan ini.');
    }
}
