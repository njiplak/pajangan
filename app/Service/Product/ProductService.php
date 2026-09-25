<?php

namespace App\Service\Product;

use App\Contract\Product\ProductContract;
use App\Models\BundleItem;
use App\Models\Producer;
use App\Models\Product;
use App\Models\StockMovement;
use App\Service\BaseService;
use App\Service\Stock\StockLedger;
use Exception;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductService extends BaseService implements ProductContract
{
    protected array $fileKeys = ['images'];

    protected array $relation = ['bundleItems.product'];

    public function __construct(Product $model, private readonly StockLedger $ledger)
    {
        parent::__construct($model);
    }

    public function create($payloads)
    {
        $this->applyProducer($payloads);
        $bundleItems = $this->extractBundleItems($payloads);
        unset($payloads['stock_seen']);

        try {
            return DB::transaction(function () use ($payloads, $bundleItems) {
                $product = parent::create($payloads);

                // BaseService reports failure by returning the exception
                // rather than throwing, so rethrow to roll the outer
                // transaction back instead of saving a half-built bundle.
                if ($product instanceof Exception) {
                    throw $product;
                }

                $this->syncBundleItems($product, $bundleItems);
                $this->ledger->recordOpening($product, auth('web')->id());

                return $product->fresh($this->relation);
            });
        } catch (Exception $e) {
            return $e;
        }
    }

    public function update($id, $payloads)
    {
        $this->applyProducer($payloads);
        $bundleItems = $this->extractBundleItems($payloads);

        // Stock never goes through the plain column update: it is changed
        // below, relative to the live value, so concurrent sales survive.
        $typedStock = array_key_exists('stock', $payloads) ? (int) $payloads['stock'] : null;
        $seenStock = array_key_exists('stock_seen', $payloads) ? (int) $payloads['stock_seen'] : null;
        unset($payloads['stock'], $payloads['stock_seen']);

        try {
            return DB::transaction(function () use ($id, $payloads, $bundleItems, $typedStock, $seenStock) {
                $product = parent::update($id, $payloads);

                if ($product instanceof Exception) {
                    throw $product;
                }

                $this->syncBundleItems($product, $bundleItems);

                if ($bundleItems !== null) {
                    // A bundle holds no stock of its own; whatever it had leaves.
                    $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                    $this->ledger->move($locked, -(int) $locked->stock, StockMovement::REASON_ADJUSTMENT, null, auth('web')->id(), 'Dijadikan paket');
                } elseif ($typedStock !== null && $seenStock !== null && $typedStock !== $seenStock) {
                    $this->ledger->adjust($product->id, $typedStock - $seenStock, auth('web')->id());
                }

                return $product->fresh($this->relation);
            });
        } catch (Exception $e) {
            return $e;
        }
    }

    public function destroy($id)
    {
        if ($blocker = $this->bundlesUsing([(int) $id])) {
            return new RuntimeException($blocker);
        }

        return parent::destroy($id);
    }

    public function bulkDeleteByIds(array $ids)
    {
        if ($blocker = $this->bundlesUsing(array_map('intval', $ids))) {
            return new RuntimeException($blocker);
        }

        return parent::bulkDeleteByIds($ids);
    }

    /**
     * The product's producer_name/producer_region are a cache of its
     * producer, so they are always derived here rather than taken from the
     * request — they cannot drift from the producer they claim.
     */
    private function applyProducer(array &$payloads): void
    {
        if (! array_key_exists('producer_id', $payloads)) {
            return;
        }

        $producer = $payloads['producer_id'] ? Producer::find($payloads['producer_id']) : null;

        $payloads['producer_id'] = $producer?->id;
        $payloads['producer_name'] = $producer?->name;
        $payloads['producer_region'] = $producer?->region;
    }

    /**
     * Pulls `bundle_items` out of the payload and normalises the columns a
     * bundle must not carry. Returns the component rows to persist, or null
     * when this product is not a bundle (which clears any it used to have).
     */
    private function extractBundleItems(array &$payloads): ?array
    {
        $items = $payloads['bundle_items'] ?? null;
        unset($payloads['bundle_items']);

        $isBundle = filter_var($payloads['is_bundle'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (! $isBundle) {
            return null;
        }

        // Both are derived from the components; storing anything else here
        // would leave a stale number for a reader that skips the accessors.
        $payloads['stock'] = 0;
        $payloads['weight_gram'] = 0;

        return is_array($items) ? array_values($items) : [];
    }

    private function syncBundleItems(Product $product, ?array $items): void
    {
        // Replace wholesale: the set is small and this keeps removals,
        // additions and quantity edits on one path.
        $product->bundleItems()->delete();

        if ($items === null) {
            return;
        }

        foreach ($items as $item) {
            BundleItem::create([
                'bundle_id' => $product->id,
                'product_id' => (int) $item['product_id'],
                'quantity' => (int) $item['quantity'],
            ]);
        }
    }

    /**
     * Message naming the bundles that still sell any of these products, or
     * null when none do. Deleting such a product would otherwise either be
     * refused by the database with an opaque error or silently gut a bundle.
     */
    private function bundlesUsing(array $productIds): ?string
    {
        $bundles = BundleItem::query()
            ->whereIn('product_id', $productIds)
            ->with('bundle:id,name')
            ->get()
            ->pluck('bundle.name')
            ->filter()
            ->unique()
            ->values();

        if ($bundles->isEmpty()) {
            return null;
        }

        return 'Produk ini masih menjadi isi paket: '.$bundles->join(', ')
            .'. Hapus produk dari paket tersebut terlebih dahulu.';
    }
}
