<?php

declare(strict_types=1);

namespace App\Module\User\Repository;

use App\Module\User\DTO\CreateUserRequest;
use App\Module\User\Entity\AccessCode;
use App\Module\User\Entity\User;
use App\Module\User\Exception\DuplicateUsernameException;
use PDO;

/**
 * Data access for usuarios_notaria. Never selects contrasenia or password_hash (ADR-006):
 * the only password data that touches this class is the hash passed to create().
 */
class UserRepository
{
    private const MYSQL_DUP_ENTRY = 1062;

    private const COLUMNS = 'id_usuario, id_notaria, usuario, nombre, apellido_paterno, apellido_materno, '
        . 'user_access, id_estatus, ultimo_acceso';

    // Columns are utf8mb3_bin; convert to get case- and accent-insensitive sorting.
    private const ORDER_BY = 'ORDER BY CONVERT(apellido_paterno USING utf8mb4) COLLATE utf8mb4_unicode_ci, '
        . 'CONVERT(apellido_materno USING utf8mb4) COLLATE utf8mb4_unicode_ci, '
        . 'CONVERT(nombre USING utf8mb4) COLLATE utf8mb4_unicode_ci, id_usuario';

    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Status 2 includes every non-active value (2, 0, NULL) to match User::status().
     *
     * @return User[]
     */
    public function findAllByNotary(int $notaryId, ?int $status): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM usuarios_notaria WHERE id_notaria = :notaryId';

        if ($status === User::STATUS_ACTIVE) {
            $sql .= ' AND id_estatus = ' . User::STATUS_ACTIVE;
        } elseif ($status === User::STATUS_INACTIVE) {
            $sql .= ' AND (id_estatus IS NULL OR id_estatus <> ' . User::STATUS_ACTIVE . ')';
        }

        $stmt = $this->db->prepare($sql . ' ' . self::ORDER_BY);
        $stmt->execute(['notaryId' => $notaryId]);

        $users = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $users[] = User::fromRow($row);
        }

        return $users;
    }

    public function findByIdAndNotary(int $id, int $notaryId): ?User
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM usuarios_notaria WHERE id_usuario = :id AND id_notaria = :notaryId';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id, 'notaryId' => $notaryId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? User::fromRow($row) : null;
    }

    /**
     * Global (no notary filter, the login has no notary context) and case-insensitive.
     */
    public function usernameExists(string $username, ?int $excludeId = null): bool
    {
        $sql = 'SELECT 1 FROM usuarios_notaria WHERE LOWER(usuario) = LOWER(:username)';
        $params = ['username' => $username];

        if ($excludeId !== null) {
            $sql .= ' AND id_usuario <> :excludeId';
            $params['excludeId'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @throws DuplicateUsernameException when usuario already exists (exact match)
     */
    public function create(int $notaryId, CreateUserRequest $dto, string $passwordHash): int
    {
        $sql = 'INSERT INTO usuarios_notaria
                    (id_notaria, nombre, apellido_paterno, apellido_materno, usuario,
                     contrasenia, password_hash, ultimo_acceso, id_estatus, user_access)
                VALUES
                    (:notaryId, :firstName, :paternalLastName, :maternalLastName, :username,
                     NULL, :passwordHash, NULL, :status, :access)';

        try {
            $this->db->prepare($sql)->execute([
                'notaryId' => $notaryId,
                'firstName' => $dto->firstName,
                'paternalLastName' => $dto->paternalLastName,
                'maternalLastName' => $dto->maternalLastName,
                'username' => $dto->username,
                'passwordHash' => $passwordHash,
                'status' => $dto->status,
                'access' => AccessCode::toCsv($dto->access),
            ]);
        } catch (\PDOException $e) {
            $this->rethrowDuplicate($e);
        }

        return (int) $this->db->lastInsertId();
    }

    /**
     * Does not touch passwords, ultimo_acceso or id_notaria.
     * No rowCount() check: it is 0 when the values do not change.
     *
     * @throws DuplicateUsernameException when usuario already exists (exact match)
     */
    public function update(User $user): void
    {
        $sql = 'UPDATE usuarios_notaria
                SET usuario = :username, nombre = :firstName, apellido_paterno = :paternalLastName,
                    apellido_materno = :maternalLastName, user_access = :access, id_estatus = :status
                WHERE id_usuario = :id AND id_notaria = :notaryId';

        try {
            $this->db->prepare($sql)->execute([
                'id' => $user->id,
                'notaryId' => $user->notaryId,
                'username' => $user->username,
                'firstName' => $user->firstName,
                'paternalLastName' => $user->paternalLastName,
                'maternalLastName' => $user->maternalLastName,
                'access' => AccessCode::toCsv($user->access),
                'status' => $user->statusId,
            ]);
        } catch (\PDOException $e) {
            $this->rethrowDuplicate($e);
        }
    }

    private function rethrowDuplicate(\PDOException $e): never
    {
        // errorInfo[1] is the driver code; getCode() is the SQLSTATE 23000 shared by other integrity errors
        if (($e->errorInfo[1] ?? null) === self::MYSQL_DUP_ENTRY) {
            throw new DuplicateUsernameException($e);
        }

        throw $e;
    }
}
