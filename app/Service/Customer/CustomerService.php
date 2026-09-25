<?php

namespace App\Service\Customer;

use App\Contract\Customer\CustomerContract;
use App\Models\Customer;
use App\Service\BaseService;

class CustomerService extends BaseService implements CustomerContract
{
    public function __construct(Customer $model)
    {
        parent::__construct($model);
    }
}
