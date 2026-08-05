CREATE TABLE IF NOT EXISTS delivery_sequences (
    sequence_year SMALLINT UNSIGNED NOT NULL,
    current_value INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (sequence_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference VARCHAR(30) NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    client_site_id BIGINT UNSIGNED NOT NULL,
    client_contact_id BIGINT UNSIGNED NULL,
    scheduled_at DATETIME NOT NULL,
    priority VARCHAR(20) NOT NULL DEFAULT 'Normale',
    driver_id BIGINT UNSIGNED NULL,
    vehicle_id BIGINT UNSIGNED NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'Brouillon',
    status_before_incident VARCHAR(30) NULL,
    observations TEXT NULL,
    cancellation_reason TEXT NULL,
    delivered_at DATETIME NULL,
    closed_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_deliveries_reference (reference),
    KEY idx_deliveries_client (client_id),
    KEY idx_deliveries_scheduled (scheduled_at),
    KEY idx_deliveries_status (status),
    KEY idx_deliveries_driver (driver_id),
    KEY idx_deliveries_vehicle (vehicle_id),
    CONSTRAINT fk_deliveries_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE RESTRICT,
    CONSTRAINT fk_deliveries_site FOREIGN KEY (client_site_id) REFERENCES client_sites (id) ON DELETE RESTRICT,
    CONSTRAINT fk_deliveries_contact FOREIGN KEY (client_contact_id) REFERENCES client_contacts (id) ON DELETE SET NULL,
    CONSTRAINT fk_deliveries_driver FOREIGN KEY (driver_id) REFERENCES drivers (id) ON DELETE SET NULL,
    CONSTRAINT fk_deliveries_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id) ON DELETE SET NULL,
    CONSTRAINT fk_deliveries_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_deliveries_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_status_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    delivery_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(30) NULL,
    to_status VARCHAR(30) NOT NULL,
    comment TEXT NULL,
    changed_by BIGINT UNSIGNED NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_delivery_status_history (delivery_id, changed_at),
    CONSTRAINT fk_delivery_status_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries (id) ON DELETE CASCADE,
    CONSTRAINT fk_delivery_status_user FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE delivery_goods
ADD CONSTRAINT fk_delivery_goods_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries (id) ON DELETE CASCADE;

INSERT IGNORE INTO permissions (name, description) VALUES
('deliveries.view', 'Consulter les livraisons'),
('deliveries.manage', 'Créer et modifier les livraisons'),
('deliveries.status', 'Faire progresser le workflow des livraisons');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON p.name IN ('deliveries.view','deliveries.manage','deliveries.status')
WHERE r.slug IN ('administrateur','responsable-logistique','dispatcher');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON p.name IN ('deliveries.view','deliveries.status')
WHERE r.slug='agent-logistique';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON p.name='deliveries.view'
WHERE r.slug IN ('direction','chauffeur','consultation');

