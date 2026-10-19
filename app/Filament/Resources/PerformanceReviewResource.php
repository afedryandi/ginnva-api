<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\HasKaryawanAccess;
use App\Filament\Resources\PerformanceReviewResource\Pages;
use App\Models\PerformanceReview;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Penilaian Kinerja (Karyawan Fase 5, 2026-11-02): penilaian berkala empat aspek (skor 1-5) dengan catatan kekuatan, perbaikan, dan target.
 * Untuk pembinaan, bukan dasar gaji atau potongan. Penilaian yang sudah dibaca karyawan terkunci dari perubahan nilai agar yang dibaca
 * karyawan sama dengan yang tersimpan. Satu penilaian per karyawan per periode.
 */
class PerformanceReviewResource extends Resource
{
    use HasKaryawanAccess {
        canDelete as private traitCanDelete;
    }

    protected static ?string $model = PerformanceReview::class;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationGroup = 'K3 & Kinerja';

    protected static ?string $navigationLabel = 'Penilaian Kinerja';

    protected static ?string $modelLabel = 'Penilaian Kinerja';

    protected static ?string $pluralModelLabel = 'Penilaian Kinerja';

    protected static ?int $navigationSort = 550;

    /** Penilaian yang sudah dibaca karyawan tidak dihapus (bukti tetap utuh). */
    public static function canDelete($record): bool
    {
        return $record->acknowledged_at === null && static::traitCanDelete($record);
    }

    public static function getEloquentQuery(): Builder
    {
        return static::limitToOwnStore(parent::getEloquentQuery()->with(['user:id,name', 'reviewer:id,name']));
    }

    public static function form(Form $form): Form
    {
        $locked = fn (?PerformanceReview $record) => $record?->acknowledged_at !== null;

        return $form->schema([
            Forms\Components\Section::make('Penilaian')->columns(2)->schema([
                Forms\Components\Select::make('user_id')->label('Karyawan')->options(fn () => static::employeeOptions())->searchable()->required()
                    ->disabled(fn (?PerformanceReview $record) => $record !== null)->dehydrated(),
                Forms\Components\TextInput::make('period_label')->label('Periode')->required()->maxLength(60)->placeholder('mis. 2026 Semester 1')
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule, Forms\Get $get) => $rule->where('user_id', $get('user_id')))
                    ->disabled($locked)->dehydrated(),
                Forms\Components\DatePicker::make('reviewed_on')->label('Tanggal penilaian')->required()->default(today())->maxDate(today())->disabled($locked)->dehydrated(),
                ...collect(PerformanceReview::ASPECTS)->map(fn (string $label, string $column) => Forms\Components\Select::make($column)
                    ->label($label)->options(PerformanceReview::SCORES)->required()->native(false)->disabled($locked)->dehydrated())->values()->all(),
                Forms\Components\Textarea::make('strengths')->label('Kekuatan')->rows(2)->columnSpanFull()->disabled($locked)->dehydrated(),
                Forms\Components\Textarea::make('improvements')->label('Yang perlu diperbaiki')->rows(2)->columnSpanFull()->disabled($locked)->dehydrated(),
                Forms\Components\Textarea::make('goals')->label('Target periode berikutnya')->rows(2)->columnSpanFull()->disabled($locked)->dehydrated(),
                Forms\Components\Placeholder::make('acknowledged_at')
                    ->label('Dibaca karyawan')
                    ->visible(fn (?PerformanceReview $record) => $record !== null)
                    ->content(fn (?PerformanceReview $record) => $record?->acknowledged_at?->translatedFormat('d M Y H:i') ?? 'Belum dibaca'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Karyawan')->searchable(),
                Tables\Columns\TextColumn::make('period_label')->label('Periode')->searchable(),
                Tables\Columns\TextColumn::make('reviewed_on')->label('Tanggal')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('average')->label('Rata-rata')->state(fn (PerformanceReview $record) => number_format($record->average(), 2)),
                Tables\Columns\TextColumn::make('reviewer.name')->label('Penilai')->placeholder('—')->toggleable(),
                Tables\Columns\IconColumn::make('acknowledged_at')->label('Dibaca')->boolean()->getStateUsing(fn (PerformanceReview $record) => $record->acknowledged_at !== null),
            ])
            ->defaultSort('reviewed_on', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('user_id')->label('Karyawan')->relationship('user', 'name')->searchable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPerformanceReviews::route('/'),
            'create' => Pages\CreatePerformanceReview::route('/create'),
            'edit' => Pages\EditPerformanceReview::route('/{record}/edit'),
        ];
    }
}
