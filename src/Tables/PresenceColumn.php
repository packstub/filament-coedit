<?php

namespace Packstub\CoEdit\Tables;

use Filament\Tables\Columns\Column;
use Illuminate\Database\Eloquent\Model;
use Packstub\CoEdit\CoEdit;
use Packstub\CoEdit\Presence\PresenceStore;
use Throwable;

/**
 * Who is on each row right now, as small avatars. The table shows what was
 * true when it rendered; add ->poll() on the table to keep it live.
 */
class PresenceColumn extends Column
{
    protected string $view = 'filament-coedit::column';

    /** @var array<string, list<array<string, mixed>>> members per room, read once for the whole page of rows */
    protected array $membersByRoom = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('');
        $this->width('1%');
        $this->state(fn (mixed $record): array => $record instanceof Model ? $this->membersOf($record) : []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function membersOf(Model $record): array
    {
        $room = PresenceStore::room($record);

        if (! array_key_exists($room, $this->membersByRoom)) {
            $rooms = array_unique([$room, ...array_map(PresenceStore::room(...), $this->recordsOnPage())]);

            $this->membersByRoom += CoEdit::store()->membersOfMany($rooms, exceptUser: CoEdit::who()['user']);
        }

        return $this->membersByRoom[$room] ?? [];
    }

    /**
     * @return list<Model>
     */
    protected function recordsOnPage(): array
    {
        try {
            $records = $this->getTable()->getLivewire()->getTableRecords();
        } catch (Throwable) {
            return [];
        }

        return array_values(array_filter(
            is_iterable($records) ? [...$records] : [],
            fn (mixed $record): bool => $record instanceof Model,
        ));
    }
}
