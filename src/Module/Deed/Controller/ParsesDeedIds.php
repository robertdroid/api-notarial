<?php

declare(strict_types=1);

namespace App\Module\Deed\Controller;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Module\Deed\Exception\DeedError;
use App\Module\Deed\Exception\DeedException;

trait ParsesDeedIds
{
    /**
     * Not an integer -> 400 invalid_id; zero or negative -> 404 of the given resource.
     */
    private function parseId(Request $request, string $param, DeedError $notFound): int|Response
    {
        $id = filter_var($request->getRouteParam($param), FILTER_VALIDATE_INT);

        if ($id === false) {
            return DeedErrorResponse::invalidId();
        }

        if ($id <= 0) {
            return DeedErrorResponse::fromException(new DeedException($notFound));
        }

        return $id;
    }
}
