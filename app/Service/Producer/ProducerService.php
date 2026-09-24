<?php

namespace App\Service\Producer;

use App\Contract\Producer\ProducerContract;
use App\Models\Producer;
use App\Models\Product;
use App\Service\BaseService;
use Exception;
use Illuminate\Support\Facades\DB;

class ProducerService extends BaseService implements ProducerContract
{
    protected array $fileKeys = ['photo'];

    public function __construct(Producer $model)
    {
        parent::__construct($model);
    }

    /**
     * Products cache the producer's name and region; a rename must reach
     * every product card, cart line and email that reads the cache.
     */
    public function update($id, $payloads)
    {
        $result = parent::update($id, $payloads);

        if ($result instanceof Exception) {
            return $result;
        }

        Product::query()->where('producer_id', $result->id)->update([
            'producer_name' => $result->name,
            'producer_region' => $result->region,
        ]);

        return $result;
    }

    public function destroy($id)
    {
        try {
            return DB::transaction(function () use ($id) {
                // The FK nulls producer_id, but the cached text would keep
                // naming a producer that no longer exists.
                Product::query()->where('producer_id', $id)->update([
                    'producer_name' => null,
                    'producer_region' => null,
                ]);

                $result = parent::destroy($id);

                if ($result instanceof Exception) {
                    throw $result;
                }

                return $result;
            });
        } catch (Exception $e) {
            return $e;
        }
    }

    public function bulkDeleteByIds(array $ids)
    {
        try {
            return DB::transaction(function () use ($ids) {
                Product::query()->whereIn('producer_id', $ids)->update([
                    'producer_name' => null,
                    'producer_region' => null,
                ]);

                $result = parent::bulkDeleteByIds($ids);

                if ($result instanceof Exception) {
                    throw $result;
                }

                return $result;
            });
        } catch (Exception $e) {
            return $e;
        }
    }
}
