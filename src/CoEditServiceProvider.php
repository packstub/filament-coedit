<?php

namespace Packstub\CoEdit;

use Filament\Actions\EditAction;
use Filament\Resources\Events\RecordSaved;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Packstub\CoEdit\Actions\CoEditAction;
use Packstub\CoEdit\Concerns\HasPresence;
use Packstub\CoEdit\Concerns\InteractsWithCoEdit;
use Packstub\CoEdit\Http\LeaveController;
use Packstub\CoEdit\Livewire\Presence;
use Packstub\CoEdit\Presence\PresenceStore;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class CoEditServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-coedit';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile()
            ->hasViews(static::$name)
            ->hasTranslations();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(PresenceStore::class);
    }

    public function packageBooted(): void
    {
        Livewire::component('filament-coedit-presence', Presence::class);

        /** @param  list<string>  $ignore */
        EditAction::macro('coEdit', function (array $ignore = [], bool $presence = true): EditAction {
            /** @var EditAction $this */
            return CoEditAction::configure($this, $ignore, $presence);
        });

        // No session or CSRF token on a beacon; the signature is the authorisation.
        Route::post('filament-coedit/leave', LeaveController::class)
            ->middleware([...(array) config('filament-coedit.leave_middleware', []), ValidateSignature::class.':relative'])
            ->name('filament-coedit.leave');

        FilamentAsset::register([
            Css::make('filament-coedit', __DIR__.'/../resources/dist/coedit.css'),
        ], 'packstub/filament-coedit');

        // One hook for every page: the avatars appear on pages that use the
        // traits and nowhere else, so there is nothing to register per panel.
        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE,
            function (): string {
                $page = Livewire::current();

                if (! $page || ! in_array(HasPresence::class, class_uses_recursive($page), strict: true)) {
                    return '';
                }

                // Protected, so the browser cannot call it on the page.
                return (fn (): string => $this->renderCoEditPresence()->render())->call($page);
            },
        );

        // Filament dispatches the class name with a [record, data, page] payload;
        // accept an event object too in case that ever changes.
        Event::listen(RecordSaved::class, function (mixed ...$payload): void {
            $page = $payload[0] instanceof RecordSaved ? $payload[0]->getPage() : ($payload[2] ?? null);

            if (is_object($page) && in_array(InteractsWithCoEdit::class, class_uses_recursive($page), strict: true)) {
                /** @var InteractsWithCoEdit $page */
                $page->coeditAfterSave();
            }
        });
    }
}
