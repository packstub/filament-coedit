<?php

namespace Packstub\CoEdit\Merge;

final readonly class MergeResult
{
    /**
     * @param  array<string, mixed>  $data  the form to save: mine, with every field only they changed taken from theirs
     * @param  list<string>  $taken  fields only they changed, now holding their value
     * @param  list<string>  $conflicts  fields both sides changed to different values
     */
    public function __construct(
        public array $data,
        public array $taken,
        public array $conflicts,
    ) {}

    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }
}
