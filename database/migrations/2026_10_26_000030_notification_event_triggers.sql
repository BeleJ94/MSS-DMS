-- Aucun rattrapage historique : seuls les nouveaux changements sont notifiés.
CREATE TABLE IF NOT EXISTS notification_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    delivery_id BIGINT UNSIGNED NOT NULL,
    destination_id BIGINT UNSIGNED NULL,
    destination_key BIGINT UNSIGNED NOT NULL DEFAULT 0,
    event VARCHAR(20) NOT NULL,
    occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL DEFAULT NULL,
    last_error VARCHAR(500) NULL,
    UNIQUE KEY uq_notification_event (delivery_id,destination_key,event),
    KEY idx_notification_events_pending (status,available_at),
    CONSTRAINT fk_notification_event_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE TRIGGER notification_delivery_updated AFTER UPDATE ON deliveries
FOR EACH ROW
INSERT IGNORE INTO notification_events(delivery_id,destination_id,destination_key,event)
SELECT NEW.id,NULL,0,'dispatch'
WHERE NOT (OLD.status <=> NEW.status)
  AND (NEW.status='Partie' OR (NEW.status='En transit' AND OLD.status IN ('Brouillon','Affectée','À préparer','Prête','Chargement','Chargée')))
UNION ALL
SELECT NEW.id,dd.id,dd.id,'arrival'
FROM delivery_destinations dd
WHERE NOT (OLD.status <=> NEW.status) AND NEW.status='Arrivée'
  AND dd.delivery_id=NEW.id
  AND dd.id=(SELECT next_stop.id FROM delivery_destinations next_stop WHERE next_stop.delivery_id=NEW.id AND next_stop.status NOT IN ('Livrée','Annulée') ORDER BY next_stop.stop_order,next_stop.id LIMIT 1)
UNION ALL
SELECT NEW.id,dd.id,dd.id,'delivery'
FROM delivery_destinations dd
WHERE NOT (OLD.status <=> NEW.status) AND NEW.status='Livrée'
  AND dd.delivery_id=NEW.id AND dd.status<>'Annulée';

CREATE OR REPLACE TRIGGER notification_destination_updated AFTER UPDATE ON delivery_destinations
FOR EACH ROW
INSERT IGNORE INTO notification_events(delivery_id,destination_id,destination_key,event)
SELECT NEW.delivery_id,NEW.id,NEW.id,IF(NEW.status='Arrivée','arrival','delivery')
WHERE NOT (OLD.status <=> NEW.status) AND NEW.status IN ('Arrivée','Livrée');
