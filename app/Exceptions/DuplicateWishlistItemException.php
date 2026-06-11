<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A duplicate wishlist add is an expected client error (409), not a server fault.
 * Implementing ShouldntReport keeps it out of the logs, so the 409 response is also
 * independent of log-file writability.
 */
class DuplicateWishlistItemException extends Exception implements ShouldntReport
{
    /**
     * Self-render to a 409 for this phase; central error normalization lands in Phase 5.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json(
            ['message' => 'Product is already in your wishlist.'],
            409,
        );
    }
}
