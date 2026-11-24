-- Migration: deeds_innodb_and_indexes
-- Description: Moves escrituras, participantes_escritura and inscripciones_escritura to InnoDB
--              (atomic deed + participants + registrations writes, SELECT ... FOR UPDATE) and
--              indexes inscripciones_escritura by deed.
--              NO unique index on (id_notaria, num_escritura): duplicated numbers are legitimate (ADR-002).
-- Author: Roberto Trejo
-- Date: 2026-11-12

-- PRE-DEPLOY (production): task 0.1 answered; backup of the 3 tables; low-usage window (v1 still writes here).

-- UP
ALTER TABLE escrituras ENGINE=InnoDB;
ALTER TABLE participantes_escritura ENGINE=InnoDB;
ALTER TABLE inscripciones_escritura ENGINE=InnoDB;

ALTER TABLE inscripciones_escritura
    ADD INDEX idx_inscripciones_escritura (id_escritura);

-- DOWN (rollback)
-- ALTER TABLE inscripciones_escritura DROP INDEX idx_inscripciones_escritura;
-- ALTER TABLE inscripciones_escritura ENGINE=MyISAM;
-- ALTER TABLE participantes_escritura ENGINE=MyISAM;
-- ALTER TABLE escrituras ENGINE=MyISAM;
