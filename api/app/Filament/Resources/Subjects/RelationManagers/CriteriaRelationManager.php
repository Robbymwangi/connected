<?php

namespace App\Filament\Resources\Subjects\RelationManagers;

use App\Models\Criterion;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/* A subject's rubric, one line per criterion with its maximum score. Once a mark is held against a
   criterion, its maximum is fixed and the line cannot be removed: a mark is valid against the
   maximum it was entered under, and removing the line would orphan it. Its name may still be
   corrected. Removing an unused line soft-deletes it, so devices learn of it by pull. */
class CriteriaRelationManager extends RelationManager
{
    protected static string $relationship = 'criteria';

    protected static ?string $title = 'Criteria';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('max_score')
                ->label('Maximum score')
                ->integer()
                ->minValue(1)
                ->required()
                ->disabled(fn (?Criterion $record) => $record?->marks()->exists() ?? false)
                ->helperText(fn (?Criterion $record) => $record?->marks()->exists()
                    ? 'Fixed: marks have been entered against this criterion.'
                    : null),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('max_score')->label('Maximum score'),
            ])
            ->headerActions([CreateAction::make()->label('Add criterion')])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->label('Remove')
                    ->modalHeading('Remove this criterion?')
                    ->hidden(fn (Criterion $record) => $record->marks()->exists()),
            ]);
    }
}
