<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWishlistRequest;
use App\Http\Resources\WishlistResource;
use App\Services\WishlistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WishlistController extends Controller
{
    public function __construct(private readonly WishlistService $wishlist)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return WishlistResource::collection($this->wishlist->list($request->user()));
    }

    public function store(StoreWishlistRequest $request): JsonResponse
    {
        $item = $this->wishlist->add($request->user(), $request->integer('product_id'));

        return (new WishlistResource($item->load('product')))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, int $product): JsonResponse
    {
        $this->wishlist->remove($request->user(), $product);

        return response()->json(['message' => 'Product removed from wishlist.']);
    }
}
