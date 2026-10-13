<?php

namespace App\Filament\Resources\Terms;

use App\Filament\Resources\Terms\Pages\ListTerms;
use App\Models\Term;
use BackedEnum;
use Closure;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/* The term calendar (ADR 0014, decision 4). A list and three actions: open a term (which ends the
   one open), end the open term for the holidays, and withdraw a term opened by mistake. Every rule
   is in the Term model; this screen only asks and reports what the model answered. No create or
   edit page: a term's dates are what happened, not fields to type. */
class TermResource extends Resource
{
    protected static ?string $model = Term::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 5;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('term')->label('Term')->formatStateUsing(fn (Term $record) => $record->label()),
                TextColumn::make('opened_at')->label('Opened')->dateTime('j M Y')->placeholder('Before the calendar'),
                TextColumn::make('openedBy.name')->label('Opened by')->placeholder('-'),
                TextColumn::make('ended_at')->label('Ended')->dateTime('j M Y')->placeholder('-'),
                TextColumn::make('endedBy.name')->label('Ended by')->placeholder('-'),
                TextColumn::make('status')
                    ->state(fn (Term $record) => $record->isOpen() ? 'Open' : 'Ended')
                    ->badge()
                    ->color(fn (string $state) => $state === 'Open' ? 'success' : 'gray'),
            ])
            ->defaultSort(fn ($query) => $query->orderByDesc('year')->orderByDesc('term'))
            ->recordActions([self::withdrawAction()]);
    }

    public static function openAction(): Action
    {
        return Action::make('openTerm')
            ->label('Open a term')
            ->icon(Heroicon::OutlinedPlay)
            ->modalDescription(fn () => ($current = Term::current(self::institutionId())) === null
                ? 'Record that the school has opened for this term, as of now.'
                : "Record that the school has opened for this term, as of now. {$current->label()} ends at the same moment.")
            ->schema([
                TextInput::make('year')->integer()->required()->minValue(2000)->maxValue(2100)
                    ->default(fn () => Term::suggestedNext(self::institutionId())['year'] ?? (int) now()->year),
                Select::make('term')->options([1 => 'Term 1', 2 => 'Term 2', 3 => 'Term 3'])->required()
                    ->default(fn () => Term::suggestedNext(self::institutionId())['term']),
            ])
            ->action(fn (array $data) => self::attempt(
                fn () => Term::open(Auth::guard('admin')->user(), (int) $data['year'], (int) $data['term']),
                fn (Term $opened) => "{$opened->label()} is open",
            ));
    }

    public static function endAction(): Action
    {
        return Action::make('endTerm')
            ->label('End the current term')
            ->icon(Heroicon::OutlinedStop)
            ->color('gray')
            ->visible(fn () => Term::current(self::institutionId()) !== null)
            ->requiresConfirmation()
            ->modalDescription('Record that the school has closed for the holidays, as of now. No term is open until the next one is opened.')
            ->action(fn () => self::attempt(
                fn () => Term::endCurrent(Auth::guard('admin')->user()),
                fn (Term $ended) => "{$ended->label()} has ended",
            ));
    }

    public static function withdrawAction(): Action
    {
        return Action::make('withdraw')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->visible(fn (Term $record) => $record->isOpen())
            ->requiresConfirmation()
            ->modalDescription('For a term opened by mistake. It is removed, and the term it ended, if any, is open again. Refused once any assessment names it.')
            ->action(fn (Term $record) => self::attempt(
                fn () => tap($record)->withdraw(Auth::guard('admin')->user()),
                fn (Term $withdrawn) => "{$withdrawn->label()} was withdrawn",
            ));
    }

    private static function institutionId(): string
    {
        return Auth::guard('admin')->user()->institution_id;
    }

    /** Runs a calendar change and says what happened, or what the model refused and why. */
    private static function attempt(Closure $change, Closure $title): void
    {
        try {
            $term = $change();
            Notification::make()->title($title($term))->success()->send();
        } catch (DomainException $refused) {
            Notification::make()->title($refused->getMessage())->danger()->send();
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTerms::route('/'),
        ];
    }
}
