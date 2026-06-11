<?php

namespace Tests\Unit;

use App\Exceptions\DuplicateWishlistItemException;
use App\Models\Product;
use App\Models\User;
use App\Models\Wishlist;
use App\Services\WishlistService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WishlistServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): WishlistService
    {
        return new WishlistService();
    }

    public function test_add_persists_and_returns_a_wishlist_scoped_to_the_user(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        $item = $this->service()->add($user, $product->id);

        $this->assertInstanceOf(Wishlist::class, $item);
        $this->assertTrue($item->exists);
        $this->assertSame($user->id, $item->user_id);
        $this->assertSame($product->id, $item->product_id);
    }

    public function test_add_duplicate_throws_duplicate_exception(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $this->service()->add($user, $product->id);

        $this->expectException(DuplicateWishlistItemException::class);
        $this->service()->add($user, $product->id);
    }

    public function test_remove_of_an_unowned_item_throws_model_not_found(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $product = Product::factory()->create();
        Wishlist::factory()->create(['user_id' => $other->id, 'product_id' => $product->id]);

        $this->expectException(ModelNotFoundException::class);
        // Scoped to $me: the other user's item is invisible.
        $this->service()->remove($me, $product->id);
    }

    public function test_list_is_scoped_to_the_user_and_eager_loads_product(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $mine = Product::factory()->create();
        Wishlist::factory()->create(['user_id' => $me->id, 'product_id' => $mine->id]);
        Wishlist::factory()->create(['user_id' => $other->id, 'product_id' => Product::factory()->create()->id]);

        $list = $this->service()->list($me);

        $this->assertCount(1, $list);
        $this->assertSame($mine->id, $list->first()->product_id);
        // Eager-loaded, not lazy: no N+1.
        $this->assertTrue($list->first()->relationLoaded('product'));
    }
}
