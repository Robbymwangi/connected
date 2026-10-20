<?php

namespace App\Filament\Resources\Classes\RelationManagers;

use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/* Who teaches which subject in this class (teacher_assignments): the fact that scopes editing,
   finalizing, and unlocking an assessment to its teacher (#36 Option B). It never gates grading or
   creating an assessment. The teacher is an active teacher account, never an admin; the subject
   is one this class offers. */
class TeacherAssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'teacherAssignments';

    protected static ?string $title = 'Subject teachers';

    public function form(Schema $schema): Schema
    {
        $offered = $this->getOwnerRecord()->classSubjects()->pluck('subject_id');

        return $schema->components([
            Select::make('subject_id')
                ->label('Subject')
                ->relationship('subject', 'name', fn ($query) => $query->whereIn('id', $offered))
                ->helperText('Subjects this class offers; add one under Subjects first.')
                ->required(),
            Select::make('user_id')
                ->label('Teacher')
                ->relationship('teacher', 'name', fn ($query) => $query
                    ->where('kind', User::TEACHER)
                    ->whereNull('deactivated_at'))
                ->searchable()
                ->preload()
                ->required()
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule
                    ->where('class_id', $this->getOwnerRecord()->getKey())
                    ->where('subject_id', $get('subject_id'))
                    ->whereNull('deleted_at'))
                ->validationMessages(['unique' => 'That teacher already teaches this subject in this class.']),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('subject.name')->label('Subject')->sortable(),
                TextColumn::make('teacher.name')->label('Teacher'),
            ])
            ->headerActions([CreateAction::make()->label('Assign a teacher')])
            ->recordActions([DeleteAction::make()->label('Remove')->modalHeading('Remove this assignment?')]);
    }
}
