<?php

namespace App\Services;

/**
 * A refusal the operator should read as a flash message, not a 500 — "already
 * invoiced", "nothing in that period", "payments attached, can't void".
 */
class InvoicingException extends \RuntimeException {}
