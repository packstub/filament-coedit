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
    public function getCoEditRoom(): string
    {
        return PresenceStore::room($this->getRecord());
    }

    public function getCoEditMode(): string
    {
        return 'viewing';
    }

    public function renderCoEditPresence(): View
    {
        return view('filament-coedit::hook', [
            'room' => $this->getCoEditRoom(),
            'mode' => $this->getCoEditMode(),
            'version' => (int) (CoEdit::store()->lastSave($this->getCoEditRoom())['version'] ?? 0),
        ]);
    }
}
