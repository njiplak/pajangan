<?php

namespace App\Service\Banner;

use App\Contract\Banner\BannerContract;
use App\Models\Banner;
use App\Service\BaseService;

class BannerService extends BaseService implements BannerContract
{
    protected array $fileKeys = ['image'];

    public function __construct(Banner $model)
    {
        parent::__construct($model);
    }
}
