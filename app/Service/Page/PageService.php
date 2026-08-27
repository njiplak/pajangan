<?php

namespace App\Service\Page;

use App\Contract\Page\PageContract;
use App\Models\Page;
use App\Service\BaseService;

class PageService extends BaseService implements PageContract
{
    public function __construct(Page $model)
    {
        parent::__construct($model);
    }
}
