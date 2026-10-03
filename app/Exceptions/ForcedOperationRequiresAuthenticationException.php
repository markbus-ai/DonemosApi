<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;

class ForcedOperationRequiresAuthenticationException extends RuntimeException
{
    public function render(Request $request)
    {
        return response()->json([
            'message' => 'A forced operation requires an authenticated staff user.',
        ], 403);
    }
}
