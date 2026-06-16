<?php

namespace App\Policies;

/**
 * Delivery runs follow the CRUD baseline: admin all (via before()), office
 * full CRUD (run management UI), factory view-only (the chilled run sheet),
 * driver denied.
 */
class DeliveryRunPolicy extends BasePolicy {}
