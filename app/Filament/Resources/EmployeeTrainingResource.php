<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\HasKaryawanAccess;
use App\Filament\Resources\EmployeeTrainingResource\Pages;
use App\Models\EmployeeTraining;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pelatihan Karyawan (Karyawan Fase 5, 2026-11-02): pelatihan K3, pengetahuan produk, dll. beserta masa berlaku dan sertifikat privat.
 * Pelatihan installer per layanan tetap di Teknisi > Pelatihan & Sertifikat.
 */
class EmployeeTrainingResource extends Resource
{
    use HasKaryawanAccess;

    protected static ?string $model = EmployeeTraining::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationGroup = 'K3 & Kinerja';

    protected static ?string $navigationLabel = 'Pelatihan Karyawan';

    protected static ?string $modelLabel = 'Pelatihan';

    protected static ?string $pluralModelLabel = 'Pelatihan Karyawan';

    protected static ?int $navigationSort = 530;

    public static function getEloquentQuery(): Builder
    {
        return static::limitToOwnStore(parent::getEloquentQuery()->with('user:id,name'));
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(2)->schema([
                Forms\Components\Select::make('user_id')->label('Karyawan')->options(fn () => static::employeeOptions())->searchable()->required(),
                Forms\Components\Select::make('category')->label('Jenis')->options(EmployeeTraining::CATEGORIES)->required()->native(false)->default('k3'),
                Forms\Components\TextInput::make('title')->label('Nama pelatihan')->required()->maxLength(160)->columnSpanFull(),
                Forms\Components\TextInput::make('provider')->label('Penyelenggara')->maxLength(160),
                Forms\Components\DatePicker::make('held_on')->label('Tanggal pelatihan')->required()->maxDate(today()),
                Forms\Components\DatePicker::make('valid_until')->label('Berlaku sampai')->helperText('Kosongkan bila tidak kedaluwarsa.')->afterOrEqual('held_on'),
                Forms\Components\FileUpload::make('certificate_path')
                    ->label('Berkas sertifikat (opsional)')
                    ->disk('local')->visibility('private')->directory('employee-certificates')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize(10240),
                Forms\Components\Textarea::make('notes')->label('Catatan')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Karyawan')->searchable(),
                Tables\Columns\TextColumn::make('category')->label('Jenis')->badge()
                    ->formatStateUsing(fn (string $state) => EmployeeTraining::CATEGORIES[$state] ?? $state),
                Tables\Columns\TextColumn::make('title')->label('Pelatihan')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('held_on')->label('Tanggal')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('valid_until')
                    ->label('Berlaku sampai')->date('d M Y')->placeholder('Tidak kedaluwarsa')->badge()
                    ->color(fn (EmployeeTraining $record) => $record->isExpired() ? 'danger' : ($record->expiresSoon() ? 'warning' : 'gray')),
            ])
            ->defaultSort('held_on', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('category')->label('Jenis')->options(EmployeeTraining::CATEGORIES),
                Tables\Filters\Filter::make('expiring')
                    ->label('Kedaluwarsa/hampir (30 hari)')
                    ->query(fn (Builder $query) => $query->whereNotNull('valid_until')->whereDate('valid_until', '<=', today()->addDays(30))),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployeeTrainings::route('/'),
            'create' => Pages\CreateEmployeeTraining::route('/create'),
            'edit' => Pages\EditEmployeeTraining::route('/{record}/edit'),
        ];
    }
}
