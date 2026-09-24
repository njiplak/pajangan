<?php

namespace App\Http\Controllers\Product;

use App\Contract\Product\ProductContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Models\Category;
use App\Models\Producer;
use App\Models\Product;
use App\Utils\WebResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProductController extends Controller
{
    protected ProductContract $service;

    public function __construct(ProductContract $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        return Inertia::render('product/index');
    }

    public function fetch()
    {
        $data = $this->service->all(
            allowedFilters: [],
            allowedSorts: [],
            withPaginate: true,
            perPage: request()->get('per_page', 10)
        );

        return response()->json($data);
    }

    public function create()
    {
        return Inertia::render('product/form', [
            'componentOptions' => $this->componentOptions(),
            'producerOptions' => $this->producerOptions(),
            'categoryOptions' => Category::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])->toArray(),
        ]);
    }

    public function store(ProductRequest $request)
    {
        $data = $this->service->create($request->validated());

        return WebResponse::response($data, 'backoffice.product.index');
    }

    public function show($id)
    {
        $product = $this->service->find($id);

        // find() reports a missing record by returning the exception.
        if ($product instanceof \Exception) {
            abort(404);
        }

        return Inertia::render('product/form', [
            'product' => $this->transform($product),
            'componentOptions' => $this->componentOptions((int) $id),
            'producerOptions' => $this->producerOptions(),
            'categoryOptions' => Category::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])->toArray(),
        ]);
    }

    public function update(ProductRequest $request, $id)
    {
        $product = Product::findOrFail($id);

        if ($request->filled('removed_images')) {
            $product->media()
                ->whereIn('id', $request->input('removed_images'))
                ->get()
                ->each->delete();
        }

        $data = $this->service->update($id, $request->validated());

        return WebResponse::response($data, 'backoffice.product.index');
    }

    public function destroy($id)
    {
        $data = $this->service->destroy($id);

        return WebResponse::response($data, 'backoffice.product.index');
    }

    public function destroy_bulk(Request $request)
    {
        $data = $this->service->bulkDeleteByIds($request->ids ?? []);

        return WebResponse::response($data, 'backoffice.product.index');
    }

    private function transform(Product $product): array
    {
        return array_merge($product->toArray(), [
            'images' => $product->getMedia('images')->map(fn ($media) => [
                'id' => $media->id,
                'collection_name' => $media->collection_name,
                'file_name' => $media->file_name,
                'original_url' => $media->getUrl(),
            ])->values(),
            'bundle_items' => $product->bundleItems->map(fn ($item) => [
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
            ])->values(),
        ]);
    }

    private function producerOptions(): array
    {
        return Producer::query()->orderBy('name')->get(['id', 'name', 'region'])->toArray();
    }

    /**
     * Products that may be put inside a bundle. Bundles are excluded so the
     * composition stays one level deep, and the product being edited cannot
     * be offered as its own component.
     */
    private function componentOptions(?int $excludeId = null): array
    {
        return Product::query()
            ->where('is_bundle', false)
            ->when($excludeId, fn ($query) => $query->where('id', '!=', $excludeId))
            ->orderBy('name')
            ->get(['id', 'name', 'price', 'discount_percent', 'stock', 'weight_gram'])
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'effective_price' => $product->effectivePrice(),
                'stock' => $product->stock,
                'weight_gram' => $product->weight_gram,
            ])->all();
    }
}
