CREATE TABLE IF NOT EXISTS delivery_destinations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    delivery_id BIGINT UNSIGNED NOT NULL,
    stop_order SMALLINT UNSIGNED NOT NULL,
    label VARCHAR(160) NOT NULL,
    address_line VARCHAR(255) NOT NULL,
    city VARCHAR(120) NULL,
    contact_name VARCHAR(160) NULL,
    contact_phone VARCHAR(60) NULL,
    instructions TEXT NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(11,7) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'À livrer',
    arrived_at DATETIME NULL,
    delivered_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_delivery_destination_order (delivery_id, stop_order),
    KEY idx_delivery_destination_status (delivery_id, status),
    CONSTRAINT fk_destination_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO delivery_destinations
    (delivery_id,stop_order,label,address_line,city,contact_name,contact_phone,instructions,latitude,longitude,status,arrived_at,delivered_at)
SELECT d.id,1,s.name,s.address_line1,s.city,ct.full_name,ct.phone,s.delivery_instructions,s.latitude,s.longitude,
       CASE WHEN d.status IN ('Livrée','Clôturée') THEN 'Livrée' WHEN d.status='Arrivée' THEN 'Arrivée' WHEN d.status='Annulée' THEN 'Annulée' ELSE 'À livrer' END,
       CASE WHEN d.status IN ('Arrivée','Livrée','Clôturée') THEN COALESCE(d.delivered_at,d.updated_at) ELSE NULL END,
       CASE WHEN d.status IN ('Livrée','Clôturée') THEN COALESCE(d.delivered_at,d.updated_at) ELSE NULL END
FROM deliveries d
JOIN client_sites s ON s.id=d.client_site_id
LEFT JOIN client_contacts ct ON ct.id=d.client_contact_id
WHERE NOT EXISTS (SELECT 1 FROM delivery_destinations x WHERE x.delivery_id=d.id);

ALTER TABLE deliveries MODIFY client_site_id BIGINT UNSIGNED NULL;

ALTER TABLE delivery_goods ADD COLUMN destination_id BIGINT UNSIGNED NULL AFTER delivery_id;
ALTER TABLE delivery_goods ADD KEY idx_delivery_goods_destination (destination_id);
ALTER TABLE delivery_goods ADD CONSTRAINT fk_delivery_goods_destination FOREIGN KEY (destination_id) REFERENCES delivery_destinations (id) ON DELETE SET NULL;

ALTER TABLE delivery_pods ADD KEY idx_pod_delivery (delivery_id);
ALTER TABLE delivery_pods DROP INDEX uq_delivery_pod;
ALTER TABLE delivery_pods ADD COLUMN destination_id BIGINT UNSIGNED NULL AFTER delivery_id;
UPDATE delivery_pods p SET destination_id=(SELECT dd.id FROM delivery_destinations dd WHERE dd.delivery_id=p.delivery_id ORDER BY dd.stop_order LIMIT 1) WHERE destination_id IS NULL;
ALTER TABLE delivery_pods MODIFY destination_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE delivery_pods ADD UNIQUE KEY uq_destination_pod (destination_id);
ALTER TABLE delivery_pods ADD CONSTRAINT fk_pod_destination FOREIGN KEY (destination_id) REFERENCES delivery_destinations (id) ON DELETE CASCADE;
