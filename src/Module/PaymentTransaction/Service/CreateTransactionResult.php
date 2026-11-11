<?php

declare(strict_types=1);

namespace App\Module\PaymentTransaction\Service;

use App\Module\PaymentTransaction\Entity\PaymentTransaction;

/**
 * Lets the Controller choose 201 or 200 without the Service knowing about HTTP.
 */
final readonly class CreateTransactionResult
{
    public function __construct(
        public PaymentTransaction $transaction,
        public bool $created,
    ) {
    }
}
