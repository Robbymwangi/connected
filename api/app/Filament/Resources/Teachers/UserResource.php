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
use Illuminate\Support\Facades\Auth;

/* Teachers, that is accounts (build plan 5.5), with the same fields and rules as POST and PUT
   /api/teachers. There is no delete action anywhere here: an account is deactivated, never
   deleted, because its name must still resolve on every mark and comment it wrote. Deactivating
   revokes its tokens (User::booted) and is refused for oneself and for the last active
   administrator (User::deactivate). "Revoke devices" signs a lost phone out without deactivating
   the person. Subject-moderation grants are a relation manager on the account. The panel has one
   gate, canAccessPanel; there are no finer policies inside it (ADR 0009, decision 3). */
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
            TextInput::make('email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
            TextInput::make('password')
                ->password()
                ->revealable()
                ->minLength(12)
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn (?string $state) => filled($state))
                ->helperText(fn (string $operation) => $operation === 'edit' ? 'Leave blank to keep the current password.' : null),
            Toggle::make('is_admin')
                ->label('Administrator')
                // Neither oneself nor the last active administrator can lose the flag here, for the same
                // reason neither can be deactivated. A disabled field is not saved.
                ->disabled(fn (?User $record) => $record !== null && ($record->is(Auth::guard('admin')->user()) || $record->isLastActiveAdmin())),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
                IconColumn::make('is_admin')->label('Admin')->boolean(),
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
                Action::make('reactivate')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (User $record) => $record->deactivated_at !== null)
                    ->requiresConfirmation()
                    ->action(fn (User $record) => $record->update(['deactivated_at' => null])),
                // The app's devices hold Sanctum tokens; an administrator's panel session is a
                // separate login this does not end. Deactivating the account does: the panel
                // refuses a deactivated administrator on their next request.
                Action::make('revokeDevices')
                    ->label('Sign out of the app')
                    ->icon(Heroicon::OutlinedDevicePhoneMobile)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Every phone and computer signed in to the app as this person is signed out, and can sign in again. An administrator\'s session in this panel is not ended; to end it, deactivate the account.')
                    ->action(function (User $record): void {
                        $record->tokens()->delete();
                        Notification::make()->title('Signed out of the app')->success()->send();
                    }),
            ]);
    }

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
