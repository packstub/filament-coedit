<?php

namespace Packstub\CoEdit\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched while a save is being merged: both sides changed these fields; the person is asked to choose.
 */
class ConflictDetected
{
    use Dispatchable;

    /**
     * @param  list<string>  $fields  form field paths (`title`, `meta.color` in a section with its own state path)
     * @param  string|null  $theirName  who saved last, when Co-Edit knows
     */
    public function __construct(
        public readonly Model $record,
        public readonly ?Authenticatable $user,
        public readonly array $fields,
        public readonly ?string $theirName = null,
    ) {}
}
