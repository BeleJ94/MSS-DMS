CREATE TABLE IF NOT EXISTS drivers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(30) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    date_of_birth DATE NULL,
    gender VARCHAR(20) NULL,
    photo_path VARCHAR(255) NULL,
    phone VARCHAR(50) NOT NULL,
    alternate_phone VARCHAR(50) NULL,
    email VARCHAR(190) NULL,
    address VARCHAR(255) NULL,
    city VARCHAR(100) NULL,
    license_number VARCHAR(100) NOT NULL,
    license_category VARCHAR(30) NOT NULL,
    license_issued_at DATE NULL,
    license_expires_at DATE NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'Disponible',
    available_from DATETIME NULL,
    emergency_contact_name VARCHAR(160) NULL,
    emergency_contact_phone VARCHAR(50) NULL,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_drivers_code (code),
    UNIQUE KEY uq_drivers_license (license_number),
    KEY idx_drivers_name (last_name, first_name),
    KEY idx_drivers_status (status, is_active),
    KEY idx_drivers_license_expiry (license_expires_at),
    CONSTRAINT fk_drivers_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_drivers_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver_missions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    driver_id BIGINT UNSIGNED NOT NULL,
    mission_reference VARCHAR(60) NOT NULL,
    origin VARCHAR(160) NOT NULL,
    destination VARCHAR(160) NOT NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    distance_km DECIMAL(10,2) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'Planifiée',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_driver_missions_driver (driver_id, started_at),
    CONSTRAINT fk_driver_missions_driver FOREIGN KEY (driver_id) REFERENCES drivers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver_incidents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    driver_id BIGINT UNSIGNED NOT NULL,
    incident_reference VARCHAR(60) NOT NULL,
    occurred_at DATETIME NOT NULL,
    severity VARCHAR(20) NOT NULL DEFAULT 'Mineur',
    status VARCHAR(30) NOT NULL DEFAULT 'Ouvert',
    description TEXT NOT NULL,
    resolution TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_driver_incidents_driver (driver_id, occurred_at),
    CONSTRAINT fk_driver_incidents_driver FOREIGN KEY (driver_id) REFERENCES drivers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    driver_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(40) NOT NULL,
    description VARCHAR(255) NOT NULL,
    changes_json LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_driver_history_driver (driver_id, created_at),
    CONSTRAINT fk_driver_history_driver FOREIGN KEY (driver_id) REFERENCES drivers (id) ON DELETE CASCADE,
    CONSTRAINT fk_driver_history_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (name, description) VALUES
('drivers.view', 'Consulter les chauffeurs'),
('drivers.manage', 'Créer et modifier les chauffeurs');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN ('drivers.view','drivers.manage')
WHERE r.slug IN ('administrateur','responsable-logistique');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name='drivers.view'
WHERE r.slug IN ('direction','dispatcher','agent-logistique','chauffeur','consultation');

