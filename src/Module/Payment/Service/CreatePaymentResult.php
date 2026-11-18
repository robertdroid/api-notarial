<?php

declare(strict_types=1);

namespace App\Module\Payment\Service;

use App\Module\Payment\Entity\Payment;

/**
 * Lets the Controller choose 201 or 200 without the Service knowing about HTTP.
 */
final readonly class CreatePaymentResult
{
    public function __construct(
        public Payment $payment,
        public bool $created,
    ) {
    }
}
