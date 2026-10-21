<?php

namespace Packstub\CoEdit\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched while a save is being merged: fields only the other person changed were kept as they saved them.
 */
class ChangesMerged
{
    use Dispatchable;

    /**
     * @param  list<string>  $fields  top-level form field names
     * @param  string|null  $theirName  who saved last, when Co-Edit knows
     */
    public function __construct(
        public readonly Model $record,
        public readonly ?Authenticatable $user,
        public readonly array $fields,
        public readonly ?string $theirName = null,
    ) {}
}
