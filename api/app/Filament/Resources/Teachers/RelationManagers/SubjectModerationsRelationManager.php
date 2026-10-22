<?php

namespace App\Filament\Resources\Teachers\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/* Subject-moderation grants on an account (ADR 0009, decision 1; docs/spec/access-model.md): a
   user and subject link, granted and revoked here. The interface calls the holder the subject's
   head of department (ADR 0014, decision 2); the data keeps the moderation name. Revoking soft-deletes the link, as for every
   synchronisable row, so devices learn of it by pull; the grant is what the link is, so removing
   it is the right verb here, unlike for an account. */
class SubjectModerationsRelationManager extends RelationManager
{
    protected static string $relationship = 'subjectModerations';

    protected static ?string $title = 'Head of department';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('subject_id')
                ->label('Subject')
                ->relationship('subject', 'name')
                ->required()
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule
                    ->where('user_id', $this->getOwnerRecord()->getKey())
                    ->whereNull('deleted_at')),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('subject.name')
            ->description('The subjects this teacher heads. For each, they settle disputed marks between teachers and unlock finalized assessments, in the app.')
            ->columns([TextColumn::make('subject.name')->label('Subject')])
            ->headerActions([CreateAction::make()->label('Grant')])
            ->recordActions([DeleteAction::make()->label('Revoke')->modalHeading('Revoke this grant?')]);
    }
}
