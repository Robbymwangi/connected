<?php

namespace App\Filament\Resources\Classes\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/* The class's students, as enrolments: one row per student per year (docs/spec/data-model.md,
   enrolments), so a student's class in a past year stays on record. The year defaults to the
   current one, as the roster's does (StudentsController). A student sits in one class a year, the
   live unique index on (student_id, year); the form says so before the database refuses it.
   Removing an enrolment soft-deletes it, so devices drop the student from the roster on pull. */
class EnrolmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'enrolments';

    protected static ?string $title = 'Students';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('student_id')
                ->label('Student')
                ->relationship('student', 'name')
                ->searchable()
                ->preload()
                ->required()
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule
                    ->where('year', (int) $get('year'))
                    ->whereNull('deleted_at'))
                ->validationMessages(['unique' => 'This student is already enrolled in a class for that year.']),
            TextInput::make('year')
                ->integer()
                ->minValue(2000)
                ->maxValue(2100)
                ->default(fn () => now()->year)
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('student.name')
            ->columns([
                TextColumn::make('student.name')->label('Student')->searchable(),
                TextColumn::make('year')->sortable(),
            ])
            ->defaultSort('year', 'desc')
            ->headerActions([CreateAction::make()->label('Enrol a student')])
            ->recordActions([DeleteAction::make()->label('Remove')->modalHeading('Remove this enrolment?')]);
    }
}
