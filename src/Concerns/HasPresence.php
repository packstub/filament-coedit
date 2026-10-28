<?php

namespace Packstub\CoEdit\Concerns;

use Illuminate\Contracts\View\View;
use Packstub\CoEdit\CoEdit;
use Packstub\CoEdit\Presence\PresenceStore;

/**
 * Shows who else is on the record in the page header. For view pages; edit
 * pages use InteractsWithCoEdit, which adds the merge on save.
 */
trait HasPresence
{
    protected function getCoEditRoom(): string
    {
        return PresenceStore::room($this->getRecord());
    }

    protected function getCoEditMode(): string
    {
        return 'viewing';
    }

    /**
     * Protected like the getters above: a public method on a Livewire page can be
     * called from the browser, and the room names the database behind the record.
     */
    protected function renderCoEditPresence(): View
    {
        return view('filament-coedit::hook', [
            'room' => $this->getCoEditRoom(),
            'mode' => $this->getCoEditMode(),
            'version' => (int) (CoEdit::store()->lastSave($this->getCoEditRoom())['version'] ?? 0),
        ]);
    }
}
