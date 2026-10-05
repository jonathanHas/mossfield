<?php

namespace App\Services;

/**
 * A stock refusal the operator should read as a flash message, not a 500 —
 * "only N can be written off", "already reversed".
 */
class StockException extends \RuntimeException {}
