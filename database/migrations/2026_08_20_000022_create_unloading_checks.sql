-- Compléter un contrôle de déchargement installé partiellement sans écraser
-- les quantités, anomalies et notes déjà enregistrées.
SET @migration_schema_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'delivery_goods'
          AND column_name = 'delivered_quantity'),
    'DO 0',
    'ALTER TABLE delivery_goods ADD COLUMN delivered_quantity DECIMAL(12,3) NULL AFTER quantity'
);
PREPARE migration_schema_statement FROM @migration_schema_sql;
EXECUTE migration_schema_statement;
DEALLOCATE PREPARE migration_schema_statement;

SET @migration_schema_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'delivery_goods'
          AND column_name = 'delivery_condition'),
    'DO 0',
    'ALTER TABLE delivery_goods ADD COLUMN delivery_condition VARCHAR(30) NULL AFTER delivered_quantity'
);
PREPARE migration_schema_statement FROM @migration_schema_sql;
EXECUTE migration_schema_statement;
DEALLOCATE PREPARE migration_schema_statement;

SET @migration_schema_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'delivery_goods'
          AND column_name = 'driver_note'),
    'DO 0',
    'ALTER TABLE delivery_goods ADD COLUMN driver_note VARCHAR(500) NULL AFTER delivery_condition'
);
PREPARE migration_schema_statement FROM @migration_schema_sql;
EXECUTE migration_schema_statement;
DEALLOCATE PREPARE migration_schema_statement;

SET @migration_schema_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'delivery_goods'
          AND column_name = 'checked_at'),
    'DO 0',
    'ALTER TABLE delivery_goods ADD COLUMN checked_at DATETIME NULL AFTER driver_note'
);
PREPARE migration_schema_statement FROM @migration_schema_sql;
EXECUTE migration_schema_statement;
DEALLOCATE PREPARE migration_schema_statement;

SET @migration_schema_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'delivery_goods'
          AND column_name = 'checked_by'),
    'DO 0',
    'ALTER TABLE delivery_goods ADD COLUMN checked_by BIGINT UNSIGNED NULL AFTER checked_at'
);
PREPARE migration_schema_statement FROM @migration_schema_sql;
EXECUTE migration_schema_statement;
DEALLOCATE PREPARE migration_schema_statement;

SET @migration_schema_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'delivery_goods'
          AND index_name = 'idx_delivery_goods_check'),
    'DO 0',
    'ALTER TABLE delivery_goods ADD KEY idx_delivery_goods_check (delivery_id, checked_at)'
);
PREPARE migration_schema_statement FROM @migration_schema_sql;
EXECUTE migration_schema_statement;
DEALLOCATE PREPARE migration_schema_statement;

SET @migration_schema_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.table_constraints
        WHERE table_schema = DATABASE() AND table_name = 'delivery_goods'
          AND constraint_name = 'fk_delivery_goods_checked_by'),
    'DO 0',
    'ALTER TABLE delivery_goods ADD CONSTRAINT fk_delivery_goods_checked_by FOREIGN KEY (checked_by) REFERENCES users (id) ON DELETE SET NULL'
);
PREPARE migration_schema_statement FROM @migration_schema_sql;
EXECUTE migration_schema_statement;
DEALLOCATE PREPARE migration_schema_statement;
