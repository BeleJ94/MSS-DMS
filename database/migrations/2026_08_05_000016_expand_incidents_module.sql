ALTER TABLE driver_incidents
    ADD COLUMN delivery_id BIGINT UNSIGNED NULL AFTER driver_id,
    ADD COLUMN incident_type VARCHAR(50) NOT NULL DEFAULT 'autre' AFTER incident_reference,
    ADD COLUMN latitude DECIMAL(10,7) NULL AFTER description,
    ADD COLUMN longitude DECIMAL(11,7) NULL AFTER latitude,
    ADD COLUMN accuracy_m DECIMAL(10,2) NULL AFTER longitude,
    ADD COLUMN responsible_user_id BIGINT UNSIGNED NULL AFTER accuracy_m,
    ADD COLUMN corrective_action TEXT NULL AFTER responsible_user_id,
    ADD COLUMN resolved_at DATETIME NULL AFTER resolution,
    ADD COLUMN resolved_by BIGINT UNSIGNED NULL AFTER resolved_at,
    ADD COLUMN reported_by BIGINT UNSIGNED NULL AFTER resolved_by,
    ADD KEY idx_incidents_delivery (delivery_id),
    ADD KEY idx_incidents_status (status, occurred_at),
    ADD KEY idx_incidents_responsible (responsible_user_id),
    ADD CONSTRAINT fk_incidents_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_incidents_responsible FOREIGN KEY (responsible_user_id) REFERENCES users (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_incidents_resolved_by FOREIGN KEY (resolved_by) REFERENCES users (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_incidents_reported_by FOREIGN KEY (reported_by) REFERENCES users (id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS incident_photos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    incident_id BIGINT UNSIGNED NOT NULL,
    photo_mime VARCHAR(40) NOT NULL DEFAULT 'image/jpeg',
    photo_data MEDIUMBLOB NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_incident_photos_incident (incident_id),
    CONSTRAINT fk_incident_photos_incident FOREIGN KEY (incident_id) REFERENCES driver_incidents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (name, description) VALUES
('incidents.view', 'Consulter les incidents opérationnels'),
('incidents.manage', 'Affecter et traiter les incidents'),
('incidents.resolve', 'Résoudre les incidents');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON p.name IN ('incidents.view','incidents.manage','incidents.resolve')
WHERE r.slug IN ('administrateur','responsable-logistique');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON p.name IN ('incidents.view','incidents.manage')
WHERE r.slug IN ('dispatcher','agent-logistique');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON p.name='incidents.view'
WHERE r.slug IN ('direction','consultation');
