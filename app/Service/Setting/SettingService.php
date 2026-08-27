<?php

namespace App\Service\Setting;

use App\Contract\Setting\SettingContract;
use App\Models\Setting;
use App\Service\BaseService;

class SettingService extends BaseService implements SettingContract
{
    public function __construct(Setting $model)
    {
        parent::__construct($model);
    }

    public function allAsKeyValue(): array
    {
        return $this->model->newQuery()->pluck('value', 'key')->all();
    }
}
