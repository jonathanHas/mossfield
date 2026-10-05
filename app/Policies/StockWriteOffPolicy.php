<?php

namespace App\Policies;

/**
 * Stock write-offs follow the CRUD baseline: admin all (via before()), office
 * write, factory view-only, driver denied.
 */
class StockWriteOffPolicy extends BasePolicy {}
