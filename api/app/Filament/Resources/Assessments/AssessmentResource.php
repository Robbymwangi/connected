<?php

namespace App\Filament\Resources\Assessments;

use App\Filament\Resources\Assessments\Pages\ListAssessments;
use App\Models\Assessment;
use App\Models\TermClosure;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/* Assessments, read-only, for the one thing an administrator does to them here: unlock a finalized
   one (build plan 5.6). The action calls Assessment::unlock, the same model method the API's
   unlock endpoint calls, so the rule exists once: who may, which statuses, the term close it
   unwinds, and when a note is required (once report cards exist under that close) and recorded.
   Assessments are created and marked in the PWA, never here. */
class AssessmentResource extends Resource
{
    protected static ?string $model = Assessment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 4;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('schoolClass.stream')->label('Stream'),
                TextColumn::make('subject.name')->label('Subject'),
                TextColumn::make('year')->sortable(),
                TextColumn::make('term')->formatStateUsing(fn ($state) => "Term {$state}"),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'scheduled' ? 'gray' : 'success'),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                SelectFilter::make('status')->options(['scheduled' => 'Open', 'finalized' => 'Finalized']),
            ])
            ->recordActions([self::unlockAction()]);
    }

    public static function unlockAction(): Action
    {
        return Action::make('unlock')
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('warning')
            ->visible(fn (Assessment $record) => in_array($record->status, Assessment::FINALIZED, true))
            ->modalHeading('Unlock this assessment?')
            ->modalDescription('It reopens for marking, its results are withdrawn, and if its term was closed the close is undone with its comments and report cards.')
            ->schema(fn (Assessment $record) => [
                Textarea::make('note')
                    ->label('Why')
                    ->required(self::reportsIssued($record))
                    ->helperText(self::reportsIssued($record)
                        ? 'Report cards have been issued for this term, so a note is required and kept.'
                        : 'No report cards exist for this term yet, so no note is kept.'),
            ])
            ->action(function (Assessment $record, array $data): void {
                try {
                    $record->unlock(Auth::guard('admin')->user(), $data['note'] ?? null);
                    Notification::make()->title('Assessment unlocked')->success()->send();
                } catch (ValidationException $refused) {
                    Notification::make()->title(collect($refused->errors())->flatten()->first())->danger()->send();
                }
            });
    }

    private static function reportsIssued(Assessment $record): bool
    {
        return TermClosure::liveFor($record->class_id, $record->year, $record->term)->first()?->reports()->exists() ?? false;
    }

    public static function getPages(): array
    {
        return ['index' => ListAssessments::route('/')];
    }
}
