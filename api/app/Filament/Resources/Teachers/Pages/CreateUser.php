<?php

namespace App\Filament\Resources\Teachers\Pages;

use App\Filament\Resources\Teachers\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;
}
