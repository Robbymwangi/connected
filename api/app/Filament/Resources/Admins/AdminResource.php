<?php

namespace App\Filament\Resources\Admins;

use App\Filament\Resources\Admins\Pages\CreateAdmin;
use App\Filament\Resources\Admins\Pages\EditAdmin;
use App\Filament\Resources\Admins\Pages\ListAdmins;
use App\Filament\Resources\Teachers\UserResource;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

/* Admin accounts (ADR 0014, decision 1): the back-office staff who sign in to this panel and
   nowhere else. They hold no academic grant, so the form is only who they are and a password. No
   delete, as for teachers; deactivation is refused for oneself and for the last active admin
   (User::deactivate), so the school always keeps someone who can reactivate others. There is no
   "sign out of the app" here: an admin account can never hold a device token. */
class AdminResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'admins';

    protected static ?string $modelLabel = 'admin';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('email')->email()->required()->maxLength(255)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('kind', User::ADMIN)),
            TextInput::make('password')
                ->password()
                ->revealable()
                ->minLength(12)
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn (?string $state) => filled($state))
                ->helperText(fn (string $operation) => $operation === 'edit' ? 'Leave blank to keep the current password.' : null),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
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
                UserResource::deactivateAction(),
                UserResource::reactivateAction(),
            ]);
    }

    /** Only admin accounts; the global institution scope still applies beneath this. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('kind', User::ADMIN);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdmins::route('/'),
            'create' => CreateAdmin::route('/create'),
            'edit' => EditAdmin::route('/{record}/edit'),
        ];
    }
}
