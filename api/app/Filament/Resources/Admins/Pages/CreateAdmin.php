<?php

namespace App\Filament\Resources\Admins\Pages;

use App\Filament\Resources\Admins\AdminResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

/* The kind is not a form field: this screen makes admin accounts and nothing else. */
class CreateAdmin extends CreateRecord
{
    protected static string $resource = AdminResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'kind' => User::ADMIN];
    }
}
