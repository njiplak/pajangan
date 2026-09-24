<?php

namespace App\Service\Category;

use App\Contract\Category\CategoryContract;
use App\Models\Category;
use App\Service\BaseService;

class CategoryService extends BaseService implements CategoryContract
{
    public function __construct(Category $model)
    {
        parent::__construct($model);
    }
}
