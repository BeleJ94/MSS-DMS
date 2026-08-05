CREATE TABLE IF NOT EXISTS goods (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference VARCHAR(60) NOT NULL,
    designation VARCHAR(180) NOT NULL,
    description TEXT NULL,
    unit VARCHAR(30) NOT NULL,
    unit_weight_kg DECIMAL(12,3) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Actif',
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_goods_reference (reference),
    KEY idx_goods_designation (designation),
    KEY idx_goods_status (status),
    CONSTRAINT fk_goods_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_goods_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_goods (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    delivery_id BIGINT UNSIGNED NOT NULL COMMENT 'Référence vers le futur module Livraisons',
    goods_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(14,3) NOT NULL,
    unit VARCHAR(30) NOT NULL,
    unit_weight_kg DECIMAL(12,3) NULL,
    description_snapshot VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_delivery_goods_line (delivery_id, goods_id),
    KEY idx_delivery_goods_delivery (delivery_id),
    KEY idx_delivery_goods_goods (goods_id),
    CONSTRAINT fk_delivery_goods_goods FOREIGN KEY (goods_id) REFERENCES goods (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (name, description) VALUES
('goods.view', 'Consulter le catalogue des marchandises'),
('goods.manage', 'Créer et modifier les marchandises');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON p.name IN ('goods.view','goods.manage')
WHERE r.slug IN ('administrateur','responsable-logistique');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON p.name='goods.view'
WHERE r.slug IN ('direction','dispatcher','agent-logistique','chauffeur','consultation');

