<?php

namespace App\Filament\Resources\Admins\Pages;

use App\Filament\Resources\Admins\AdminResource;
use App\Filament\Resources\Teachers\UserResource;
use Filament\Resources\Pages\EditRecord;

/* No DeleteAction: an account is deactivated, never deleted (UserResource). */
class EditAdmin extends EditRecord
{
    protected static string $resource = AdminResource::class;

    protected function getHeaderActions(): array
    {
        return [UserResource::deactivateAction()];
    }
}
