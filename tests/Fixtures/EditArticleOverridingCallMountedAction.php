<?php

namespace Packstub\CoEdit\Tests\Fixtures;

/** A page with its own callMountedAction(), as some apps add for logging. */
class EditArticleOverridingCallMountedAction extends EditArticleWithNestedLayouts
{
    public static int $calls = 0;

    public function callMountedAction(array $arguments = []): mixed
    {
        static::$calls++;

        return parent::callMountedAction($arguments);
    }
}
