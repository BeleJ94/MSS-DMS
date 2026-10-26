-- File d'attente des notifications client (départ, arrivée, livraison).
-- Les messages sont préparés dans la transaction métier puis envoyés par scripts/send_notifications.php.
CREATE TABLE IF NOT EXISTS notification_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    delivery_id BIGINT UNSIGNED NOT NULL,
    destination_id BIGINT UNSIGNED NULL,
    destination_key BIGINT UNSIGNED NOT NULL DEFAULT 0,
    event VARCHAR(20) NOT NULL,
    channel VARCHAR(20) NOT NULL DEFAULT 'email',
    recipient_name VARCHAR(160) NOT NULL,
    recipient_address VARCHAR(190) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    pod_id BIGINT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(500) NULL,
    available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notification_once (delivery_id, destination_key, event, channel, recipient_address),
    KEY idx_notification_queue (status, available_at),
    CONSTRAINT fk_notification_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
