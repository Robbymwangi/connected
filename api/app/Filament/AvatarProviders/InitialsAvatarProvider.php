<?php

namespace App\Filament\AvatarProviders;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/* The signed-in account's initials on the brand accent, drawn as an inline SVG. Replaces Filament's
   default, which fetches the image from ui-avatars.com on every page: a third party told who is
   using the panel, and a broken image whenever it cannot be reached. Nothing leaves the page. */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $initials = str(Filament::getNameForDefaultAvatar($record))
            ->trim()
            ->explode(' ')
            ->map(fn (string $word): string => mb_substr(preg_replace('/^[^\p{L}\p{N}]+/u', '', $word) ?? '', 0, 1))
            ->filter()
            ->take(2)
            ->join('');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="#c1440e"/>'
            .'<text x="50%" y="50%" dy=".35em" text-anchor="middle" fill="#ffffff" '
            .'font-family="Figtree, system-ui, sans-serif" font-size="26" font-weight="600">'
            .e(mb_strtoupper($initials))
            .'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
