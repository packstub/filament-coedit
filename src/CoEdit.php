<?php

namespace Packstub\CoEdit;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Packstub\CoEdit\Presence\PresenceStore;

class CoEdit
{
    /**
     * How the signed-in user appears to the others: the panel's own user name and avatar.
     *
     * @return array{user: string, name: string, avatar: ?string}
     */
    public static function who(?Authenticatable $user = null): array
    {
        $user ??= Filament::auth()->user() ?? auth()->user();

        if (! $user instanceof Model) {
            return ['user' => 'guest', 'name' => __('filament-coedit::coedit.someone'), 'avatar' => null];
        }

        return [
            // The model type too: admin #1 and customer #1 of two panels are two people.
            'user' => $user->getMorphClass().':'.$user->getAuthIdentifier(),
            'name' => Filament::getUserName($user),
            'avatar' => Filament::getUserAvatarUrl($user),
        ];
    }

    public static function store(): PresenceStore
    {
        return app(PresenceStore::class);
    }
}
