INSERT IGNORE INTO permissions (name, description)
VALUES ('tracking.history.view', 'Consulter l’historique GPS des livraisons');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id,p.id
FROM roles r
JOIN permissions p ON p.name='tracking.history.view'
WHERE r.slug IN ('administrateur','direction','responsable-logistique','dispatcher','agent-logistique','consultation');
