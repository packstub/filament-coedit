<?php

namespace Packstub\CoEdit\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched when the person settles a conflict, before the save it unblocks.
 */
class ConflictResolved
{
    use Dispatchable;

    /**
     * @param  array<string, 'mine'|'theirs'>  $choices  field name => the version kept
     */
    public function __construct(
        public readonly Model $record,
        public readonly ?Authenticatable $user,
        public readonly array $choices,
    ) {}
}
