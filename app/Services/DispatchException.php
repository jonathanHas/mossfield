<?php

namespace App\Services;

/**
 * A dispatch refusal the operator should read as a flash — "only ready orders
 * can be dispatched" — never a 500.
 */
class DispatchException extends \RuntimeException {}
