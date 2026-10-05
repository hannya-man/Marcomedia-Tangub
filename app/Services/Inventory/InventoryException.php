<?php
namespace App\Services\Inventory;

use RuntimeException;

/**
 * A stock rule was broken (not enough stock, wrong batch status, missing reason).
 * The message is written for staff, so controllers can show it as-is.
 * Extends RuntimeException, so POSController's existing catch block handles it.
 */
class InventoryException extends RuntimeException
{
}
