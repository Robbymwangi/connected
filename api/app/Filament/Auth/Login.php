<?php

namespace App\Filament\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Schemas\Schema;

/* The panel's sign-in without "Remember me". Remembering writes remember_token onto the user
   row, and users is a synchronisable table: the write would bump the row's version and append
   the token to the sync log. The admin panel is online only, so a session that ends and asks
   for a fresh sign-in costs little; the column and the sync-layer exemption it would need are
   not worth it. The field is left out of the form entirely, not hidden: a declared field
   would still accept a value from the client, and the base class reads `$data['remember']`
   with a false fallback, so an undeclared one is never remembered. */
class Login extends BaseLogin
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
        ]);
    }
}
