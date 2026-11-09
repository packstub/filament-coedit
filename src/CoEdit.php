<?php

namespace Packstub\CoEdit;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
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
        $user ??= static::user();

        if (! $user instanceof Model) {
            return ['user' => 'guest', 'name' => __('filament-coedit::coedit.someone'), 'avatar' => null];
        }

        return [
            // The model type too: admin #1 and customer #1 of two panels are two people.
            // getMorphAlias(), not getMorphClass(): it never throws under an enforced morph map.
            'user' => Relation::getMorphAlias($user::class).':'.$user->getAuthIdentifier(),
            'name' => Filament::getUserName($user),
            'avatar' => Filament::getUserAvatarUrl($user),
        ];
    }

    /**
     * The person on this request, through the panel's guard first: a panel with
     * its own guard leaves the default one empty.
     */
    public static function user(): ?Authenticatable
    {
        return Filament::auth()->user() ?? auth()->user();
    }

    public static function store(): PresenceStore
    {
        return app(PresenceStore::class);
    }
}
