ALTER TABLE client_sites ADD COLUMN IF NOT EXISTS notification_emails TEXT NULL;
ALTER TABLE delivery_destinations ADD COLUMN IF NOT EXISTS client_site_id BIGINT UNSIGNED NULL;
ALTER TABLE delivery_destinations ADD COLUMN IF NOT EXISTS notification_emails TEXT NULL;
ALTER TABLE delivery_destinations ADD INDEX IF NOT EXISTS idx_destination_client_site (client_site_id);

-- Recover only exact, unambiguous matches for existing free-form destinations.
UPDATE delivery_destinations dd
JOIN deliveries d ON d.id=dd.delivery_id
JOIN (SELECT client_id,address_line1,COALESCE(city,'') city_key,MIN(id) site_id,COUNT(*) matches
      FROM client_sites GROUP BY client_id,address_line1,COALESCE(city,'')) s
  ON s.client_id=d.client_id AND s.address_line1=dd.address_line
 AND s.city_key=COALESCE(dd.city,'') AND s.matches=1
SET dd.client_site_id=s.site_id
WHERE dd.client_site_id IS NULL;
