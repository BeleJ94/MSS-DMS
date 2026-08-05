ALTER TABLE drivers ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id;
ALTER TABLE drivers ADD UNIQUE KEY uq_drivers_user (user_id);
ALTER TABLE drivers ADD CONSTRAINT fk_drivers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL;

INSERT IGNORE INTO permissions (name, description) VALUES ('driver_app.access', 'Accéder à l’application mobile chauffeur');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON p.name='driver_app.access' WHERE r.slug IN ('administrateur','chauffeur');
