<?php

declare(strict_types=1);

namespace App\Module\Deed\Controller;

use App\Core\Http\Response;
use App\Module\Deed\Exception\DeedException;

/**
 * Builds the error bodies of the deeds module: {error, code[, details | errors]} (ADR-005).
 */
final class DeedErrorResponse
{
    public static function fromException(DeedException $e): Response
    {
        $body = ['error' => $e->getMessage(), 'code' => $e->error->value];
        if ($e->details !== []) {
            $body['details'] = $e->details;
        }

        return Response::json($body, $e->error->httpStatus());
    }

    /** @param array<string, string> $errors */
    public static function validation(array $errors): Response
    {
        return Response::json([
            'error' => 'Validation failed',
            'code' => 'validation_failed',
            'errors' => $errors,
        ], 400);
    }

    public static function invalidId(): Response
    {
        return Response::json(['error' => 'Invalid ID format', 'code' => 'invalid_id'], 400);
    }
}
