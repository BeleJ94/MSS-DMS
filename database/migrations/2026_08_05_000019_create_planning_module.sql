ALTER TABLE deliveries
    ADD COLUMN planning_duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 120 AFTER scheduled_at,
    ADD KEY idx_deliveries_driver_schedule (driver_id, scheduled_at, status),
    ADD KEY idx_deliveries_vehicle_schedule (vehicle_id, scheduled_at, status);

CREATE TABLE IF NOT EXISTS delivery_planning_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    delivery_id BIGINT UNSIGNED NOT NULL,
    previous_scheduled_at DATETIME NOT NULL,
    scheduled_at DATETIME NOT NULL,
    previous_duration_minutes SMALLINT UNSIGNED NOT NULL,
    duration_minutes SMALLINT UNSIGNED NOT NULL,
    previous_driver_id BIGINT UNSIGNED NULL,
    driver_id BIGINT UNSIGNED NULL,
    previous_vehicle_id BIGINT UNSIGNED NULL,
    vehicle_id BIGINT UNSIGNED NULL,
    change_type VARCHAR(30) NOT NULL,
    comment VARCHAR(500) NULL,
    changed_by BIGINT UNSIGNED NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_planning_history_delivery (delivery_id, changed_at),
    CONSTRAINT fk_planning_history_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries (id) ON DELETE CASCADE,
    CONSTRAINT fk_planning_history_previous_driver FOREIGN KEY (previous_driver_id) REFERENCES drivers (id) ON DELETE SET NULL,
    CONSTRAINT fk_planning_history_driver FOREIGN KEY (driver_id) REFERENCES drivers (id) ON DELETE SET NULL,
    CONSTRAINT fk_planning_history_previous_vehicle FOREIGN KEY (previous_vehicle_id) REFERENCES vehicles (id) ON DELETE SET NULL,
    CONSTRAINT fk_planning_history_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id) ON DELETE SET NULL,
    CONSTRAINT fk_planning_history_user FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (name, description) VALUES
('planning.view', 'Consulter le planning des livraisons'),
('planning.manage', 'Planifier et affecter les livraisons');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON p.name IN ('planning.view','planning.manage')
WHERE r.slug IN ('administrateur','responsable-logistique','dispatcher');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON p.name='planning.view'
WHERE r.slug IN ('direction','agent-logistique','consultation');
