<?php

namespace App\Filament\Resources\Teachers;

use App\Filament\Resources\Teachers\Pages\CreateUser;
use App\Filament\Resources\Teachers\Pages\EditUser;
use App\Filament\Resources\Teachers\Pages\ListUsers;
use App\Filament\Resources\Teachers\RelationManagers\SubjectModerationsRelationManager;
use App\Models\User;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Unique;

/* Teacher accounts (build plan 5.5; ADR 0014): the accounts that sign in to the app. Admin
   accounts are AdminResource; neither screen lists the other kind. There is no delete action
   anywhere here: an account is deactivated, never deleted, because its name must still resolve on
   every mark and comment it wrote. Deactivating revokes its tokens (User::booted) and is refused
   for oneself (User::deactivate). "Sign out of the app" signs a lost phone out without
   deactivating the person. The two academic grants an admin makes, never holds, are here: subject
   moderation (HOD) as a relation manager, and the Director of Studies toggle. */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'teachers';

    protected static ?string $modelLabel = 'teacher';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            // Unique within the kind, as the database is (ADR 0014): a head teacher's admin account may share the address.
            TextInput::make('email')->email()->required()->maxLength(255)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('kind', User::TEACHER)),
            TextInput::make('password')
                ->password()
                ->revealable()
                ->minLength(12)
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn (?string $state) => filled($state))
                ->helperText(fn (string $operation) => $operation === 'edit' ? 'Leave blank to keep the current password.' : null),
            Toggle::make('directs_studies')
                ->label('Director of Studies')
                ->helperText('May unlock any assessment and close terms, in the app.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
                IconColumn::make('directs_studies')->label('DOS')->boolean(),
                TextColumn::make('subjectModerations.subject.name')->label('HOD of')->badge()->placeholder('None'),
                TextColumn::make('deactivated_at')
                    ->label('Status')
                    ->formatStateUsing(fn ($state) => $state === null ? 'Active' : 'Deactivated')
                    ->placeholder('Active')
                    ->badge()
                    ->color(fn ($state) => $state === null ? 'success' : 'gray'),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                self::deactivateAction(),
                self::reactivateAction(),
                Action::make('revokeDevices')
                    ->label('Sign out of the app')
                    ->icon(Heroicon::OutlinedDevicePhoneMobile)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Every phone and computer signed in to the app as this person is signed out, and can sign in again.')
                    ->action(function (User $record): void {
                        $record->tokens()->delete();
                        Notification::make()->title('Signed out of the app')->success()->send();
                    }),
            ]);
    }

    /** Only teacher accounts; the global institution scope still applies beneath this. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('kind', User::TEACHER);
    }

    /* Shared with AdminResource: the refusals live in User::deactivate, so both screens apply the
       same ones. */
    public static function deactivateAction(): Action
    {
        return Action::make('deactivate')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (User $record) => $record->deactivated_at === null)
            ->requiresConfirmation()
            ->modalDescription('They can no longer sign in, and every device signed in as them is signed out now. Their name stays on everything they wrote.')
            ->action(function (User $record): void {
                try {
                    $record->deactivate(Auth::guard('admin')->user());
                    Notification::make()->title('Account deactivated')->success()->send();
                } catch (DomainException $refused) {
                    Notification::make()->title($refused->getMessage())->danger()->send();
                }
            });
    }

    public static function reactivateAction(): Action
    {
        return Action::make('reactivate')
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn (User $record) => $record->deactivated_at !== null)
            ->requiresConfirmation()
            ->action(fn (User $record) => $record->update(['deactivated_at' => null]));
    }

    public static function getRelations(): array
    {
        return [SubjectModerationsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
