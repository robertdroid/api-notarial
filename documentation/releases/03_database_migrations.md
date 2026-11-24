# 03 · Base de datos y migraciones

Las migraciones **no** forman parte del pipeline (`.db/` está excluido del deploy) y se aplican **a mano** en producción, en la **etapa 2** (día de migraciones), cuando el frontend esté listo. El deploy de la API (etapa 1) se hace antes y no las necesita. Convención del proyecto: [`.db/README.md`](../../.db/README.md).

## 1. Punto de partida  *(hallazgos B4 y B5)*

**El repo no trae el esquema base.** `docker/mysql/init/` está vacío, `*.sql` está en `.gitignore` salvo `.db/**`, y las 13 migraciones solo hacen `ALTER`/`CREATE INDEX`/`UPDATE`/`DELETE` sobre tablas que ya existen. Las tablas `payment_*` / `payments_*` solo tienen su DDL descrito dentro de los specs.

Consecuencias:

- Las migraciones están pensadas para aplicarse sobre **la BD legacy de la v1** (tablas `escrituras`, `usuarios_notaria`, `notarias`, `actos_notaria`, etc.), no sobre una BD vacía.
- **La v1 sigue escribiendo en esas tablas** mientras la v3 convive con ella (lo dicen las propias migraciones de `deeds`, `payments_transactions` y `payments_payments`).
- **Confirmado:** producción usa la BD legacy de la v1 (`devrobne_sisnot2018`, MariaDB 11.4.13), con las 15 tablas de la API ya creadas como MyISAM. No hace falta esquema base.

## 2. Antes de tocar nada

1. **Backup completo** y descargarlo fuera del servidor (cPanel → Backup → Download a MySQL Database Backup, o `mysqldump --single-transaction`).
2. **Versión del servidor**: `SELECT VERSION();` (local: MariaDB 11.4).
3. **Motores actuales** (varias migraciones convierten MyISAM → InnoDB):
   ```sql
   SELECT table_name, engine, table_rows
   FROM information_schema.tables
   WHERE table_schema = DATABASE()
   ORDER BY engine, table_name;
   ```
4. **Tarea 0.1** (specs [11](../../specs/11_payment_transactions/arch/developer_notes.md) y [17](../../specs/17_payments_payments_crud/)): confirmar cómo inserta la v1 en `payments_transactions` y `payments_payments`. Si la v1 **no genera `uuid`**, dos inserciones con `uuid=''` chocarán con el índice único: aplicar esas migraciones solo cuando la v1 deje de escribir ahí.
5. Elegir una **ventana de baja actividad**: los `ALTER TABLE … ENGINE=InnoDB` reconstruyen la tabla y la bloquean para escritura mientras dura.

## 3. Migraciones, en orden

Aplicar de arriba a abajo (el timestamp del nombre define el orden). Todas están en `.db/applied/`.

| # | Archivo | Tablas | Tipo | Riesgo | Pre-check |
|---|---------|--------|------|--------|-----------|
| 1 | `20261109_112050_add_password_hash_to_usuarios` | `usuarios_notaria` | `ADD COLUMN password_hash` | Bajo | — |
| 2 | `20261109_120000_add_unique_index_actos_notaria` | `actos_notaria` | `UNIQUE (id_notaria, nombre_acto)` | **Medio** | A (abajo) |
| 3 | `20261110_100000_add_search_indexes_to_escrituras` | `escrituras`, `participantes_escritura` | 2 índices | Bajo | — |
| 4 | `20261110_120000_add_notary_status_index_to_payment_taxes` | `payment_taxes` | índice | Bajo | — |
| 5 | `20261110_150000_add_notary_status_index_to_payment_procedures` | `payment_procedures` | índice | Bajo | — |
| 6 | `20261110_160000_add_notary_status_index_to_payments_clients` | `payments_clients` | índice | Bajo | — |
| 7 | `20261110_170000_add_unique_procedure_tax_to_payment_procedure_taxes` | `payment_procedure_taxes` | `UNIQUE (id_procedure, id_tax)` | **Medio** | B |
| 8 | `20261111_100000_dedupe_payments_transactions_uuid` | `payments_transactions`, `payments_transaction_details` | **`DELETE`** de duplicados | **Alto, destructivo** | C |
| 9 | `20261111_100100_payments_transactions_innodb_and_indexes` | ídem | MyISAM→InnoDB + UNIQUE + PK | **Alto** | C + D |
| 10 | `20261111_120000_add_unique_username_to_usuarios_notaria` | `usuarios_notaria` | `UNIQUE (usuario)` + índice | **Medio** | E |
| 11 | `20261112_100000_deeds_innodb_and_indexes` | `escrituras`, `participantes_escritura`, `inscripciones_escritura` | MyISAM→InnoDB + índice | **Alto** (v1 escribe) | F |
| 12 | `20261112_100100_normalize_participant_status` | `participantes_escritura` | `UPDATE` 0→2 | Bajo, no reversible | — |
| 13 | `20261118_100000_payments_payments_innodb_and_indexes` | `payments_payments` | MyISAM→InnoDB + UNIQUE | **Alto** | G |

**Dependencias de orden:** la 8 debe ir antes de la 9 (el `UNIQUE (id_notary, uuid)` falla si quedan duplicados). El resto es independiente, pero respeta el orden por simplicidad.

### Pre-checks

Todos deben devolver **0 filas** (salvo donde se indica). Si no, **detente**: no resuelvas datos de producción por tu cuenta.

```sql
-- A. actos_notaria: nombre de acto duplicado por notaría (migración 2; el archivo no trae pre-check)
SELECT id_notaria, nombre_acto, COUNT(*) c
FROM actos_notaria GROUP BY id_notaria, nombre_acto HAVING c > 1;

-- B. payment_procedure_taxes: asignaciones duplicadas (migración 7)
SELECT id_procedure, id_tax, COUNT(*) c, GROUP_CONCAT(id_status) statuses
FROM payment_procedure_taxes GROUP BY id_procedure, id_tax HAVING c > 1;

-- C. payments_transactions: uuid duplicado por notaría (migraciones 8 y 9)
SELECT id_notary, uuid, COUNT(*) c, GROUP_CONCAT(id_transaction ORDER BY id_transaction) ids
FROM payments_transactions GROUP BY id_notary, uuid HAVING c > 1;
--   Para cada grupo devuelto: ¿las filas son idénticas y las que sobran tienen detalles?
SELECT t.*, (SELECT COUNT(*) FROM payments_transaction_details d
             WHERE d.id_transaction = t.id_transaction) AS n_details
FROM payments_transactions t
WHERE (t.id_notary, t.uuid) IN (
    SELECT id_notary, uuid FROM payments_transactions GROUP BY id_notary, uuid HAVING COUNT(*) > 1)
ORDER BY t.id_notary, t.uuid, t.id_transaction;
--   La migración 8 solo es segura si TODAS las filas duplicadas son idénticas y las de id mayor NO tienen detalles.
--   Además, uuid vacío (más de uno por notaría colisiona con el UNIQUE):
SELECT id_notary, COUNT(*) c FROM payments_transactions WHERE uuid = '' GROUP BY id_notary HAVING c > 1;

-- D. payments_transaction_details: pares duplicados (rompen la nueva PK) y huérfanos (migración 9)
SELECT id_transaction, id_tax, COUNT(*) c
FROM payments_transaction_details GROUP BY id_transaction, id_tax HAVING c > 1;
SELECT COUNT(*) AS huerfanos FROM payments_transaction_details d
LEFT JOIN payments_transactions t ON t.id_transaction = d.id_transaction
WHERE t.id_transaction IS NULL;

-- E. usuarios_notaria: usuario duplicado (migración 10). Si devuelve filas: PARAR y escalar,
--    negocio debe decidir a quién renombrar
SELECT usuario, COUNT(*) c, GROUP_CONCAT(id_usuario) ids
FROM usuarios_notaria GROUP BY usuario HAVING c > 1;
--    Informativo (no bloquea; reportar al PO si hay filas):
SELECT LOWER(usuario) u, COUNT(*) c, GROUP_CONCAT(id_usuario) ids
FROM usuarios_notaria GROUP BY LOWER(usuario) HAVING c > 1;

-- F. Migración 11: confirmar que la v1 no depende de características de MyISAM
--    (FULLTEXT, tablas MERGE, etc.) en escrituras, participantes_escritura, inscripciones_escritura
SHOW CREATE TABLE escrituras;
SHOW CREATE TABLE participantes_escritura;
SHOW CREATE TABLE inscripciones_escritura;

-- G. payments_payments (migración 13)
SELECT id_notary, uuid, COUNT(*) c FROM payments_payments GROUP BY id_notary, uuid HAVING c > 1;
SELECT id_payment FROM payments_payments WHERE uuid = '';
SELECT id_payment, uuid FROM payments_payments WHERE uuid NOT REGEXP '^[A-Za-z0-9_-]{1,20}$';
```

> Los pre-checks A–G vienen de los comentarios `PRE-CHECK` de cada archivo y del `developer_notes.md` del spec 11. El **A** lo agregué yo: la migración 2 crea un `UNIQUE` y su archivo no trae pre-check.

## 4. Datos de producción ya identificados

Resultado de los pre-checks sobre `devrobne_sisnot2018` (24-nov-2026). Todos los demás pre-checks dieron 0 filas y no hay `FULLTEXT`. **Vuelve a correr los pre-checks el día de migrar**: la v1 sigue escribiendo y pudo crear duplicados nuevos.

### A · Actos duplicados (bloquea la migración 2)

Los dos grupos son el mismo acto, `Cancelacion de reserva de dominio`, ambos con estatus 1:

| Notaría | `id_acto` | Escrituras que lo usan |
|---------|-----------|------------------------|
| 1 | 40 | 55 |
| 1 | 45 | 3 |
| 3 | 99 | 26 |
| 3 | 104 | 0 |

**Solución recomendada: renombrar, no borrar ni reasignar.** No toca las escrituras (las 3 de la notaría 1 siguen apuntando a su `id_acto`), no pierde datos y se revierte con un `UPDATE`.

```sql
-- Antes: confirmar que solo son esos 2 ids y que el campo admite 4 caracteres más
SELECT id_acto, id_notaria, nombre_acto, CHAR_LENGTH(nombre_acto) AS len
FROM actos_notaria WHERE id_acto IN (40, 45, 99, 104);

UPDATE actos_notaria
SET nombre_acto = CONCAT(nombre_acto, ' (2)')
WHERE id_acto IN (45, 104);

-- Después: el pre-check A debe devolver 0 filas
SELECT id_notaria, nombre_acto, COUNT(*) c
FROM actos_notaria GROUP BY id_notaria, nombre_acto HAVING c > 1;

-- Revertir (si hiciera falta)
-- UPDATE actos_notaria SET nombre_acto = REPLACE(nombre_acto, ' (2)', '') WHERE id_acto IN (45, 104);
```

Alternativa (más limpia, pero toca `escrituras`): reasignar las 3 escrituras del acto 45 al 40 y luego renombrar el 45. Solo si negocio prefiere un único acto por notaría; anota los `id_escritura` afectados antes.

> Si el día de migrar los ids o los grupos ya no coinciden, ajusta el `IN (...)` a lo que devuelva el pre-check. Siempre conserva el acto con más escrituras sin renombrar.

### C · Transacción duplicada (migración 8)

`id_transaction` 16 y 17: notaría 1, `uuid` `NdARZM`, cliente 9, trámite 7, referencia `21099`, 5-dic-2023, `fee` 3000, total 3000. Son **idénticas** y **ninguna tiene detalles** (`n_details = 0`).

La migración 8 conserva el menor `id_transaction` (**16**) y borra el 17. Se cumple la condición de seguridad del archivo (filas idénticas, la que se borra no tiene detalles), así que **se puede aplicar tal cual**, sin preparar nada. Después de aplicarla:

```sql
SELECT id_transaction FROM payments_transactions WHERE uuid = 'NdARZM' AND id_notary = 1;  -- solo 16
```

Si el día de migrar aparece otro grupo, repite el análisis de 03 §3 (pre-check C) para ese grupo.

## 5. Cómo aplicarlas

- **phpMyAdmin** (cPanel) → seleccionar la BD → pestaña **SQL** → pegar **solo la sección `-- UP`** de cada archivo → ejecutar. Una migración a la vez; verificar antes de seguir.
- Los archivos traen el `-- DOWN` comentado: no lo pegues.
- Las que cambian de motor (9, 11, 13) pueden tardar según el tamaño de la tabla.
- Anota la hora de inicio y fin de cada una.

## 6. Verificación posterior

```sql
-- Columna del login
SHOW COLUMNS FROM usuarios_notaria LIKE 'password_hash';

-- Motores: deben ser InnoDB
SELECT table_name, engine FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name IN
 ('escrituras','participantes_escritura','inscripciones_escritura',
  'payments_transactions','payments_transaction_details','payments_payments');

-- Índices únicos creados
SHOW INDEX FROM actos_notaria              WHERE Key_name = 'idx_actos_nombre_notaria';
SHOW INDEX FROM payment_procedure_taxes    WHERE Key_name = 'uq_payment_procedure_taxes_procedure_tax';
SHOW INDEX FROM payments_transactions      WHERE Key_name = 'uq_payments_transactions_notary_uuid';
SHOW INDEX FROM usuarios_notaria           WHERE Key_name = 'uq_usuarios_notaria_usuario';
SHOW INDEX FROM payments_payments          WHERE Key_name = 'uq_payments_payments_notary_uuid';

-- Sin participantes con estatus 0
SELECT COUNT(*) FROM participantes_escritura WHERE id_estatus = 0;   -- 0 (la v1 puede volver a escribir 0)
```

Luego, desde la API (después del deploy): login real, listado de escrituras, creación de una transacción de pago. El smoke test del pipeline también prueba que `usuarios_notaria.password_hash` existe.

## 7. Contraseñas: MD5 → bcrypt

Tras la migración 1, `password_hash` queda en `NULL` para todos los usuarios. **No hace falta migrar nada a mano**: en el primer login correcto de cada usuario, `AuthService` verifica el `md5` de la columna `contrasenia` y escribe el hash bcrypt en `password_hash`. Desde ahí se valida solo con bcrypt.

Importante: `contrasenia` (MD5) **se conserva** para que la v1 siga funcionando durante la convivencia.

## 8. Rollback

| Escenario | Acción |
|-----------|--------|
| Migración falla a medias | Restaurar el backup (los `ALTER` de MySQL no son transaccionales) |
| Migraciones 1–7, 9–11, 13 | Cada archivo trae su `DOWN` comentado (drop de índice / vuelta a MyISAM) |
| Migración 8 (`DELETE`) y 12 (`UPDATE`) | **No reversibles por SQL.** Solo el backup |

## 9. Después del release

Según `.db/README.md`, mover las migraciones ejecutadas: `.db/applied/*.sql` → `.db/executed/v1.0.0/` y commitearlo (PR normal).
