<?php

namespace App\Filament\Resources\Classes;

use App\Filament\Resources\Classes\Pages\CreateSchoolClass;
use App\Filament\Resources\Classes\Pages\EditSchoolClass;
use App\Filament\Resources\Classes\Pages\ListSchoolClasses;
use App\Filament\Resources\Classes\RelationManagers\ClassSubjectsRelationManager;
use App\Filament\Resources\Classes\RelationManagers\EnrolmentsRelationManager;
use App\Filament\Resources\Classes\RelationManagers\TeacherAssignmentsRelationManager;
use App\Models\SchoolClass;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/* Classes, that is streams (build plan 5.5), with the same fields as POST and PUT /api/classes.
   The class teacher is chosen from this institution's active accounts: the select's query goes
   through the global scope like every other. No delete, as for students. */
class SchoolClassResource extends Resource
{
    protected static ?string $model = SchoolClass::class;

    protected static ?string $slug = 'classes';

    protected static ?string $modelLabel = 'class';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('grade')->required()->maxLength(255),
            TextInput::make('stream')->required()->maxLength(255),
            Select::make('class_teacher_id')
                ->label('Class teacher')
                ->relationship('classTeacher', 'name', fn ($query) => $query
                    ->where('kind', User::TEACHER)
                    ->whereNull('deactivated_at'))
                ->searchable()
                ->preload(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('grade')->sortable(),
                TextColumn::make('stream')->searchable()->sortable(),
                TextColumn::make('classTeacher.name')->label('Class teacher'),
            ])
            ->defaultSort('grade')
            ->recordActions([EditAction::make()]);
    }

    /* What a class holds besides its own fields: the subjects it offers, its students for each
       year, and who teaches what. */
    public static function getRelations(): array
    {
        return [
            ClassSubjectsRelationManager::class,
            EnrolmentsRelationManager::class,
            TeacherAssignmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSchoolClasses::route('/'),
            'create' => CreateSchoolClass::route('/create'),
            'edit' => EditSchoolClass::route('/{record}/edit'),
        ];
    }
}
