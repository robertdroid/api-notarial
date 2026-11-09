<?php

declare(strict_types=1);

namespace App\Module\Auth\Repository;

use App\Module\Auth\DTO\AuthUser;
use PDO;

class UserAuthRepository
{
    public function __construct(
        private readonly PDO $db
    ) {}

    public function findByUsername(string $username): ?AuthUser
    {
        $sql = "
            SELECT
                u.id_usuario as id,
                u.usuario as username,
                u.password_hash,
                u.contrasenia as password_md5,
                u.id_notaria as notary_id,
                u.id_estatus as user_status,
                u.user_access,
                n.id_estatus as notary_status
            FROM usuarios_notaria u
            INNER JOIN notarias n ON n.id_notaria = u.id_notaria
            WHERE u.usuario = :username
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['username' => $username]);

        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return new AuthUser(
            id: (int) $row['id'],
            username: $row['username'],
            passwordHash: $row['password_hash'],
            passwordMd5: $row['password_md5'],
            notaryId: (int) $row['notary_id'],
            userStatus: (int) $row['user_status'],
            notaryStatus: (int) $row['notary_status'],
            userAccess: $row['user_access'],
        );
    }

    public function findActiveById(int $userId): ?AuthUser
    {
        $sql = "
            SELECT
                u.id_usuario as id,
                u.usuario as username,
                u.password_hash,
                u.contrasenia as password_md5,
                u.id_notaria as notary_id,
                u.id_estatus as user_status,
                u.user_access,
                n.id_estatus as notary_status
            FROM usuarios_notaria u
            INNER JOIN notarias n ON n.id_notaria = u.id_notaria
            WHERE u.id_usuario = :userId
              AND u.id_estatus = 1
              AND n.id_estatus = 1
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['userId' => $userId]);

        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return new AuthUser(
            id: (int) $row['id'],
            username: $row['username'],
            passwordHash: $row['password_hash'],
            passwordMd5: $row['password_md5'],
            notaryId: (int) $row['notary_id'],
            userStatus: (int) $row['user_status'],
            notaryStatus: (int) $row['notary_status'],
            userAccess: $row['user_access'],
        );
    }

    public function updatePasswordHash(int $userId, string $hash): void
    {
        $sql = "UPDATE usuarios_notaria SET password_hash = :hash WHERE id_usuario = :userId";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'hash' => $hash,
            'userId' => $userId,
        ]);
    }
}
