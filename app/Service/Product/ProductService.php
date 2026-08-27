<?php

namespace App\Service\Product;

use App\Contract\Product\ProductContract;
use App\Models\Product;
use App\Service\BaseService;

class ProductService extends BaseService implements ProductContract
{
    protected array $fileKeys = ['images'];

    public function __construct(Product $model)
    {
        parent::__construct($model);
    }
}
