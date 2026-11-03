<?php

namespace App\Policies;

use App\Models\User;

/**
 * All HR users have the same permissions (confirmed client requirement).
 * The only restriction is protective: you cannot disable your own account,
 * which also means at least one active account always remains.
 */
class HrUserPolicy
{
    public function viewAny(User $actor): bool
    {
        return ! $actor->isDisabled();
    }

    public function create(User $actor): bool
    {
        return ! $actor->isDisabled();
    }

    public function disable(User $actor, User $target): bool
    {
        return ! $actor->isDisabled() && ! $actor->is($target) && ! $target->isDisabled();
    }

    public function enable(User $actor, User $target): bool
    {
        return ! $actor->isDisabled() && $target->isDisabled();
    }
}
