<?php

declare(strict_types=1);

namespace App\Module\PaymentStatement\Entity;

enum LedgerRowKind: string
{
    /** "Saldo anterior": the balance before the "from" date. */
    case Opening = 'opening';
    case Charge = 'charge';
    case Payment = 'payment';
}
