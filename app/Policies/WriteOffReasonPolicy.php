<?php

namespace App\Policies;

/**
 * Write-off reasons follow the CRUD baseline: admin all (via before()), office
 * write, factory view-only, driver denied.
 */
class WriteOffReasonPolicy extends BasePolicy {}
