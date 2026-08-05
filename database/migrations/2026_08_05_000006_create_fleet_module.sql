CREATE TABLE IF NOT EXISTS vehicles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(30) NOT NULL,
    registration_number VARCHAR(50) NOT NULL,
    brand VARCHAR(100) NOT NULL,
    model VARCHAR(100) NOT NULL,
    vehicle_type VARCHAR(80) NOT NULL,
    manufacture_year SMALLINT UNSIGNED NULL,
    color VARCHAR(50) NULL,
    capacity_value DECIMAL(12,2) NOT NULL,
    capacity_unit VARCHAR(30) NOT NULL DEFAULT 'tonnes',
    mileage_km DECIMAL(12,1) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'Disponible',
    assigned_driver_id BIGINT UNSIGNED NULL,
    available_from DATETIME NULL,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_vehicles_code (code),
    UNIQUE KEY uq_vehicles_registration (registration_number),
    KEY idx_vehicles_status (status, is_active),
    KEY idx_vehicles_type (vehicle_type),
    KEY idx_vehicles_driver (assigned_driver_id),
    CONSTRAINT fk_vehicles_driver FOREIGN KEY (assigned_driver_id) REFERENCES drivers (id) ON DELETE SET NULL,
    CONSTRAINT fk_vehicles_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_vehicles_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vehicle_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    document_type VARCHAR(80) NOT NULL,
    document_number VARCHAR(100) NULL,
    issued_at DATE NULL,
    expires_at DATE NULL,
    file_name VARCHAR(190) NOT NULL,
    file_mime VARCHAR(80) NOT NULL,
    file_data LONGBLOB NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    uploaded_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_vehicle_documents_vehicle (vehicle_id),
    KEY idx_vehicle_documents_expiry (expires_at),
    CONSTRAINT fk_vehicle_documents_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id) ON DELETE CASCADE,
    CONSTRAINT fk_vehicle_documents_user FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vehicle_delivery_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    delivery_reference VARCHAR(60) NOT NULL,
    client_name VARCHAR(180) NOT NULL,
    origin VARCHAR(160) NOT NULL,
    destination VARCHAR(160) NOT NULL,
    driver_name VARCHAR(160) NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    distance_km DECIMAL(10,2) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'Planifiée',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_vehicle_deliveries_vehicle (vehicle_id, started_at),
    CONSTRAINT fk_vehicle_deliveries_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vehicle_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(40) NOT NULL,
    description VARCHAR(255) NOT NULL,
    changes_json LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_vehicle_history_vehicle (vehicle_id, created_at),
    CONSTRAINT fk_vehicle_history_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id) ON DELETE CASCADE,
    CONSTRAINT fk_vehicle_history_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (name, description) VALUES
('fleet.view', 'Consulter la flotte'),
('fleet.manage', 'Créer et modifier les véhicules et documents');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON p.name IN ('fleet.view','fleet.manage')
WHERE r.slug IN ('administrateur','responsable-logistique');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON p.name='fleet.view'
WHERE r.slug IN ('direction','dispatcher','agent-logistique','chauffeur','consultation');

