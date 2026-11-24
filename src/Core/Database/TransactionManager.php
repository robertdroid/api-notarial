<?php

declare(strict_types=1);

namespace App\Core\Database;

use PDO;

/**
 * Runs a unit of work inside a database transaction.
 * The Service decides WHAT is atomic; this class knows HOW.
 */
class TransactionManager
{
    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Commits and returns the operation result; rolls back and rethrows on any Throwable.
     * Nested calls join the already open transaction.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transactional(callable $operation): mixed
    {
        if ($this->db->inTransaction()) {
            return $operation();
        }

        $this->db->beginTransaction();

        try {
            $result = $operation();
            $this->db->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }
}
