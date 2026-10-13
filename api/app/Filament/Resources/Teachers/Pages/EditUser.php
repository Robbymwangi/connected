<?php

namespace App\Filament\Resources\Teachers\Pages;

use App\Filament\Resources\Teachers\UserResource;
use Filament\Resources\Pages\EditRecord;

/* No DeleteAction: an account is deactivated, never deleted (UserResource). */
class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [UserResource::deactivateAction()];
    }
}
