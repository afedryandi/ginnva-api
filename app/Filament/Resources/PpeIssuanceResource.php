<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\HasKaryawanAccess;
use App\Filament\Resources\PpeIssuanceResource\Pages;
use App\Models\PpeIssuance;
use App\Models\PpeType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pembagian APD (Karyawan Fase 5, 2026-11-02): siapa menerima apa dan kapan harus diganti. Jatuh tempo penggantian diisi otomatis dari
 * umur pakai jenisnya bila dikosongkan. Barang yang sudah dikembalikan tidak lagi dihitung jatuh tempo.
 */
class PpeIssuanceResource extends Resource
{
    use HasKaryawanAccess;

    protected static ?string $model = PpeIssuance::class;

    protected static ?string $navigationIcon = 'heroicon-o-hand-raised';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationGroup = 'K3 & Kinerja';

    protected static ?string $navigationLabel = 'Pembagian APD';

    protected static ?string $modelLabel = 'Pembagian APD';

    protected static ?string $pluralModelLabel = 'Pembagian APD';

    protected static ?int $navigationSort = 520;

    public static function getEloquentQuery(): Builder
    {
        return static::limitToOwnStore(parent::getEloquentQuery()->with(['user:id,name', 'type:id,name']));
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(2)->schema([
                Forms\Components\Select::make('user_id')->label('Karyawan')->options(fn () => static::employeeOptions())->searchable()->required(),
                Forms\Components\Select::make('ppe_type_id')
                    ->label('Jenis APD')
                    ->options(fn () => PpeType::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->required()->native(false),
                Forms\Components\TextInput::make('quantity')->label('Jumlah')->numeric()->integer()->minValue(1)->default(1)->required(),
                Forms\Components\DatePicker::make('issued_on')->label('Tanggal dibagikan')->required()->default(today())->maxDate(today()),
                Forms\Components\DatePicker::make('replace_due_on')->label('Jatuh tempo ganti')->helperText('Kosongkan untuk dihitung otomatis dari umur pakai jenisnya.'),
                Forms\Components\DatePicker::make('returned_on')->label('Dikembalikan/diganti pada')->helperText('Isi saat barang lama dikembalikan atau sudah diganti.'),
                Forms\Components\Textarea::make('notes')->label('Catatan')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Karyawan')->searchable(),
                Tables\Columns\TextColumn::make('type.name')->label('APD'),
                Tables\Columns\TextColumn::make('quantity')->label('Jml'),
                Tables\Columns\TextColumn::make('issued_on')->label('Dibagikan')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('replace_due_on')
                    ->label('Jatuh tempo ganti')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (PpeIssuance $record) => $record->isOverdue() ? 'danger' : 'gray'),
                Tables\Columns\TextColumn::make('returned_on')->label('Dikembalikan')->date('d M Y')->placeholder('—'),
            ])
            ->defaultSort('issued_on', 'desc')
            ->filters([
                Tables\Filters\Filter::make('overdue')
                    ->label('Perlu diganti')
                    ->query(fn (Builder $query) => $query->whereNull('returned_on')->whereNotNull('replace_due_on')->whereDate('replace_due_on', '<=', today())),
                Tables\Filters\SelectFilter::make('ppe_type_id')->label('Jenis')->relationship('type', 'name'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPpeIssuances::route('/'),
            'create' => Pages\CreatePpeIssuance::route('/create'),
            'edit' => Pages\EditPpeIssuance::route('/{record}/edit'),
        ];
    }
}
