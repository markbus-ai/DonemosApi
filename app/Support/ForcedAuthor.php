<?php

namespace App\Support;

use App\Exceptions\ForcedOperationRequiresAuthenticationException;

final class ForcedAuthor
{
    /**
     * Resolve the authenticated staff operator for a forced operation.
     *
     * Fails closed: an unauthenticated override is rejected instead of being
     * attributed to an arbitrary user.
     */
    public static function idOrFail(): int
    {
        $id = auth('staff')->id();

        if ($id === null) {
            throw new ForcedOperationRequiresAuthenticationException();
        }

        return (int) $id;
    }
}
