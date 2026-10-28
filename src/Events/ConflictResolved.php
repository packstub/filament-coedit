<?php

namespace Packstub\CoEdit\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched once the save the person settled a conflict for is committed.
 */
class ConflictResolved
{
    use Dispatchable;

    /**
     * @param  array<string, 'mine'|'theirs'>  $choices  field path => the version kept
     */
    public function __construct(
        public readonly Model $record,
        public readonly ?Authenticatable $user,
        public readonly array $choices,
    ) {}
}
