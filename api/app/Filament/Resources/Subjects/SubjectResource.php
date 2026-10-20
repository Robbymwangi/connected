<?php

namespace App\Filament\Resources\Subjects;

use App\Filament\Resources\Subjects\Pages\CreateSubject;
use App\Filament\Resources\Subjects\Pages\EditSubject;
use App\Filament\Resources\Subjects\Pages\ListSubjects;
use App\Filament\Resources\Subjects\RelationManagers\CriteriaRelationManager;
use App\Models\Subject;
use App\Support\CurrentInstitution;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/* The school's subjects and, on each, its rubric (docs/spec/data-model.md, subjects and criteria):
   what a class can offer and what the marking grid scores. The demo seeder plants these for a
   local school; a real school has only this screen. No delete, as for students and classes: an
   assessment and its marks name the subject. */
class SubjectResource extends Resource
{
    protected static ?string $model = Subject::class;

    protected static ?string $slug = 'subjects';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            // Unique among this school's live subjects; the table has no such index, so the form says it.
            TextInput::make('name')->required()->maxLength(255)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule
                    ->where('institution_id', app(CurrentInstitution::class)->id())
                    ->whereNull('deleted_at')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('criteria_count')->counts('criteria')->label('Criteria'),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [CriteriaRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubjects::route('/'),
            'create' => CreateSubject::route('/create'),
            'edit' => EditSubject::route('/{record}/edit'),
        ];
    }
}
