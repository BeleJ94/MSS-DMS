-- Réparer sans perte de données les anciennes installations où une contrainte
-- UNIQUE trop large limitait une livraison à une seule position GPS.

-- Garantir d'abord les index non uniques nécessaires aux recherches et aux FK.
SET @gps_delivery_index_exists = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'delivery_gps_positions'
      AND non_unique = 1
      AND seq_in_index = 1
      AND column_name = 'delivery_id'
);
SET @gps_delivery_index_sql = IF(
    @gps_delivery_index_exists > 0,
    'SELECT 1',
    'ALTER TABLE delivery_gps_positions ADD INDEX idx_gps_delivery_repair_20260901 (delivery_id,captured_at)'
);
PREPARE gps_delivery_index_statement FROM @gps_delivery_index_sql;
EXECUTE gps_delivery_index_statement;
DEALLOCATE PREPARE gps_delivery_index_statement;

SET @gps_driver_index_exists = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'delivery_gps_positions'
      AND non_unique = 1
      AND seq_in_index = 1
      AND column_name = 'driver_id'
);
SET @gps_driver_index_sql = IF(
    @gps_driver_index_exists > 0,
    'SELECT 1',
    'ALTER TABLE delivery_gps_positions ADD INDEX idx_gps_driver_repair_20260901 (driver_id,captured_at)'
);
PREPARE gps_driver_index_statement FROM @gps_driver_index_sql;
EXECUTE gps_driver_index_statement;
DEALLOCATE PREPARE gps_driver_index_statement;

-- Ajouter l'unicité correcte avant toute suppression. Si des doublons réels
-- existent, cette étape échoue et les anciens index restent intacts.
SET @gps_correct_unique_exists = (
    SELECT COUNT(*)
    FROM (
        SELECT index_name,
               GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS columns_list,
               SUM(sub_part IS NOT NULL) AS prefix_parts
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'delivery_gps_positions'
          AND non_unique = 0
          AND index_name <> 'PRIMARY'
        GROUP BY index_name
    ) AS gps_unique_indexes
    WHERE columns_list = 'delivery_id,driver_id,device_position_id'
      AND prefix_parts = 0
);
SET @gps_add_sql = IF(
    @gps_correct_unique_exists > 0,
    'SELECT 1',
    'ALTER TABLE delivery_gps_positions ADD UNIQUE KEY uq_gps_device_position_fix_20260901 (delivery_id,driver_id,device_position_id)'
);
PREPARE gps_add_statement FROM @gps_add_sql;
EXECUTE gps_add_statement;
DEALLOCATE PREPARE gps_add_statement;

-- Une fois l'index correct garanti, supprimer uniquement les UNIQUE dont les
-- colonnes (ou un préfixe de colonne) ne respectent pas ce contrat.
SET @gps_bad_unique_indexes = (
    SELECT GROUP_CONCAT(CONCAT('DROP INDEX `', REPLACE(index_name, '`', '``'), '`') SEPARATOR ', ')
    FROM (
        SELECT index_name,
               GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS columns_list,
               SUM(sub_part IS NOT NULL) AS prefix_parts
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'delivery_gps_positions'
          AND non_unique = 0
          AND index_name <> 'PRIMARY'
        GROUP BY index_name
    ) AS gps_unique_indexes
    WHERE columns_list <> 'delivery_id,driver_id,device_position_id'
       OR prefix_parts > 0
);
SET @gps_drop_sql = IF(
    @gps_bad_unique_indexes IS NULL OR @gps_bad_unique_indexes = '',
    'SELECT 1',
    CONCAT('ALTER TABLE delivery_gps_positions ', @gps_bad_unique_indexes)
);
PREPARE gps_drop_statement FROM @gps_drop_sql;
EXECUTE gps_drop_statement;
DEALLOCATE PREPARE gps_drop_statement;
