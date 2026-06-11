<?php

namespace App\Services;

use App\Exceptions\DuplicateWishlistItemException;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;

class WishlistService
{
    /**
     * The caller's wishlist, newest first, with the product eager-loaded (no N+1).
     *
     * @return Collection<int, Wishlist>
     */
    public function list(User $user): Collection
    {
        return $user->wishlists()->with('product')->latest()->get();
    }

    /**
     * Add a product to the caller's wishlist.
     *
     * The DB unique index on (user_id, product_id) is the source of truth: a bare scoped
     * INSERT, no SELECT-then-INSERT pre-check and no transaction. A concurrent/duplicate
     * insert surfaces as UniqueConstraintViolationException (the framework does the
     * engine-specific detection), which we translate to a clean 409 — never a 500.
     */
    public function add(User $user, int $productId): Wishlist
    {
        try {
            return $user->wishlists()->create(['product_id' => $productId]);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateWishlistItemException();
        }
    }

    /**
     * Remove a product from the caller's wishlist.
     *
     * Scoped through $user->wishlists(): an item that is missing or belongs to another
     * user is an identical ModelNotFoundException (404) — no existence leak, no IDOR.
     */
    public function remove(User $user, int $productId): void
    {
        $user->wishlists()->where('product_id', $productId)->firstOrFail()->delete();
    }
}
