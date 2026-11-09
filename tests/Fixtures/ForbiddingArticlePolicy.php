<?php

namespace Packstub\CoEdit\Tests\Fixtures;

/** Every ability refused: registered mid-test to revoke access to a page that is already open. */
class ForbiddingArticlePolicy
{
    public function view(User $user, Article $article): bool
    {
        return false;
    }

    public function update(User $user, Article $article): bool
    {
        return false;
    }
}
