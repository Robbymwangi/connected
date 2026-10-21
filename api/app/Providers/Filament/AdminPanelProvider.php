<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Http\Middleware\ResolveAdminInstitution;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Font;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentAsset;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/* The admin panel (ADR 0009): roster management for administrators, online only and server
   rendered, beside the API in the same application. It signs in through its own session guard,
   `admin`. Tenancy is the same global scope as everywhere else: ResolveAdminInstitution
   (ResolveInstitution with that guard) runs as persistent middleware, after the session has
   started, so a page load and every Livewire update after it (a table search, a form save) are
   scoped to the signed-in account's institution, and an unauthenticated request matches no rows.
   It must carry no ":argument": Livewire would not match it, and updates would run unscoped. */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->authGuard('admin')
            // An authenticator app, with recovery codes (ADR 0014, panel MFA). Required in
            // production, so every admin sets it up at their first sign-in; optional elsewhere, so
            // the seeded development admin signs in with a password alone.
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()], isRequired: fn (): bool => app()->isProduction())
            ->brandName('ConnectED admin')
            // The app's look (AGENTS.md, Frontend rules), so the two read as one product: its brand
            // mark, its self-hosted Figtree and Fraunces, the brand accent with warm Stone neutrals,
            // and theme.css (registered in boot) for the background, the gradient top bar, and serif
            // headings. The panel is server-rendered, outside the PWA's tokens, so theme.css repeats
            // their values.
            ->brandLogo(fn () => view('filament.brand'))
            ->brandLogoHeight('2rem')
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->font('Figtree', url: fn (): string => asset('fonts/app/connected/index.css').'?v='.self::assetVersion(), provider: LocalFontProvider::class)
            ->serifFont('Fraunces', provider: LocalFontProvider::class)
            ->colors(['primary' => self::brandPalette(), 'gray' => Color::Stone])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->middleware([
                ResolveAdminInstitution::class,
            ], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /* A primary button is filled with 600 and hovers to 500, and Filament uses them only if white
       text on both passes WCAG AA; otherwise it falls back to a pale shade with dark text. The
       palette generated from the brand puts lighter oranges at both, which fail. So 600 is the
       brand itself (5.1 to 1 against white), and 500 the lightest of its hue that still passes
       (#c94c1b, 4.6 to 1): a primary button looks like the app's. */
    private static function brandPalette(): array
    {
        return array_replace(Color::hex('#c1440e'), [
            500 => 'oklch(0.58 0.17 39.321)',
            600 => Color::convertToOklch('#c1440e'),
        ]);
    }

    /* The look's files, as Filament assets of the app: composer install (filament:upgrade)
       publishes them to public/css/app and public/fonts/app, both routed to the host by CloudFront,
       and Filament puts the stylesheet on every panel page. Versioned by their content, so a
       change reaches a browser on its next page load rather than when Filament next upgrades. */
    public function boot(): void
    {
        FilamentAsset::appVersion(self::assetVersion());
        FilamentAsset::register([
            Css::make('connected-theme', resource_path('filament/connected/theme.css')),
            Font::make('connected', resource_path('filament/connected/fonts')),
        ]);
    }

    private static function assetVersion(): string
    {
        return substr(md5(
            file_get_contents(resource_path('filament/connected/theme.css'))
            .file_get_contents(resource_path('filament/connected/fonts/index.css')),
        ), 0, 12);
    }
}
