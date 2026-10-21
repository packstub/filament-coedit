<?php

namespace Packstub\CoEdit\Tables;

use Filament\Tables\Columns\Column;
use Illuminate\Database\Eloquent\Model;
use Packstub\CoEdit\CoEdit;
use Packstub\CoEdit\Presence\PresenceStore;

/**
 * Who is on each row right now, as small avatars. The table shows what was
 * true when it rendered; add ->poll() on the table to keep it live.
 */
class PresenceColumn extends Column
{
    protected string $view = 'filament-coedit::column';

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('');
        $this->width('1%');
        $this->state(fn (Model $record): array => CoEdit::store()->members(PresenceStore::room($record), exceptUser: CoEdit::who()['user']));
    }
}
