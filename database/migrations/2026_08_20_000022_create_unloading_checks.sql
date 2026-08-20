ALTER TABLE delivery_goods
    ADD COLUMN delivered_quantity DECIMAL(12,3) NULL AFTER quantity,
    ADD COLUMN delivery_condition VARCHAR(30) NULL AFTER delivered_quantity,
    ADD COLUMN driver_note VARCHAR(500) NULL AFTER delivery_condition,
    ADD COLUMN checked_at DATETIME NULL AFTER driver_note,
    ADD COLUMN checked_by BIGINT UNSIGNED NULL AFTER checked_at,
    ADD KEY idx_delivery_goods_check (delivery_id, checked_at),
    ADD CONSTRAINT fk_delivery_goods_checked_by FOREIGN KEY (checked_by) REFERENCES users (id) ON DELETE SET NULL;
