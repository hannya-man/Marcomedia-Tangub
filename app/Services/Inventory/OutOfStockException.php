<?php
namespace App\Services\Inventory;

/**
 * The store doesn't have enough of something right now. Billing/POS still sells the item: the
 * line goes on order and takes its stock when it comes in (BackorderService). Every other
 * InventoryException still stops the sale, like a size bigger than any board.
 */
class OutOfStockException extends InventoryException
{
}
