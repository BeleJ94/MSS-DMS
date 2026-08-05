CREATE TABLE IF NOT EXISTS delivery_assignment_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_id BIGINT UNSIGNED NOT NULL,
    previous_driver_id BIGINT UNSIGNED NULL,
    driver_id BIGINT UNSIGNED NOT NULL,
    previous_vehicle_id BIGINT UNSIGNED NULL,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_assignment_delivery (delivery_id, assigned_at),
    CONSTRAINT fk_assignment_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries (id) ON DELETE CASCADE,
    CONSTRAINT fk_assignment_previous_driver FOREIGN KEY (previous_driver_id) REFERENCES drivers (id) ON DELETE SET NULL,
    CONSTRAINT fk_assignment_driver FOREIGN KEY (driver_id) REFERENCES drivers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_assignment_previous_vehicle FOREIGN KEY (previous_vehicle_id) REFERENCES vehicles (id) ON DELETE SET NULL,
    CONSTRAINT fk_assignment_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id) ON DELETE RESTRICT,
    CONSTRAINT fk_assignment_user FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (name, description) VALUES
('dispatching.view', 'Consulter le dispatching opérationnel'),
('dispatching.manage', 'Affecter les chauffeurs et véhicules');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN ('dispatching.view', 'dispatching.manage')
WHERE r.slug IN ('administrateur', 'responsable-logistique', 'dispatcher');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name = 'dispatching.view'
WHERE r.slug IN ('direction', 'agent-logistique', 'consultation');
