<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasName;

class NamedUser extends User implements HasAvatar, HasName
{
    public function getFilamentName(): string
    {
        return 'Dr. '.$this->name;
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return 'https://example.com/avatars/'.$this->getKey().'.png';
    }
}
