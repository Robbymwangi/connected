<?php

namespace App\Filament\Resources\Classes\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/* Which subjects a class offers (class_subjects), the list the app's new-assessment dialog picks
   from. Through SchoolClass::classSubjects, a hasMany, so each row is created and soft-deleted as
   the ClassSubject model with its own version, never by a pivot writer. Removing an offering
   leaves the class's existing assessments in that subject as they are. */
class ClassSubjectsRelationManager extends RelationManager
{
    protected static string $relationship = 'classSubjects';

    protected static ?string $title = 'Subjects';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('subject_id')
                ->label('Subject')
                ->relationship('subject', 'name')
                ->required()
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule
                    ->where('class_id', $this->getOwnerRecord()->getKey())
                    ->whereNull('deleted_at'))
                ->validationMessages(['unique' => 'This class already offers that subject.']),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('subject.name')
            ->columns([TextColumn::make('subject.name')->label('Subject')])
            ->headerActions([CreateAction::make()->label('Offer a subject')])
            ->recordActions([DeleteAction::make()->label('Remove')->modalHeading('Stop offering this subject?')]);
    }
}
