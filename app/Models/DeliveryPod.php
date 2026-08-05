<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class DeliveryPod
{
    public static function createOwned(int $deliveryId, array $data, array $signature, array $photo, ?array $signedNote): int
    {
        $recipient = trim((string) ($data['recipient_name'] ?? ''));
        if (mb_strlen($recipient) < 2 || mb_strlen($recipient) > 160) {
            throw new RuntimeException('Renseignez le nom complet du réceptionnaire.');
        }
        $latitude = $data['latitude'] ?? null; $longitude = $data['longitude'] ?? null; $accuracy = $data['accuracy'] ?? null;
        if (!is_numeric($latitude) || (float) $latitude < -90 || (float) $latitude > 90 || !is_numeric($longitude) || (float) $longitude < -180 || (float) $longitude > 180 || !is_numeric($accuracy) || (float) $accuracy < 0 || (float) $accuracy > 10000) {
            throw new RuntimeException('La position GPS de la preuve est invalide.');
        }
        $observations = trim((string) ($data['observations'] ?? ''));
        if (mb_strlen($observations) > 2000) { throw new RuntimeException('Les observations sont trop longues.'); }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('SELECT d.*,dr.user_id FROM deliveries d JOIN drivers dr ON dr.id=d.driver_id WHERE d.id=:id FOR UPDATE');
            $statement->execute(['id' => $deliveryId]);
            $delivery = $statement->fetch();
            if (!$delivery || (int) $delivery['user_id'] !== (int) Auth::id()) { throw new RuntimeException('Mission introuvable ou non autorisée.'); }
            if ($delivery['status'] !== 'Arrivée') { throw new RuntimeException('La preuve peut être saisie uniquement après confirmation de l’arrivée.'); }
            if (!$delivery['vehicle_id']) { throw new RuntimeException('Aucun véhicule n’est affecté à cette mission.'); }

            $sql = 'INSERT INTO delivery_pods (delivery_id,recipient_name,observations,signature_mime,signature_data,delivery_photo_mime,delivery_photo_data,signed_note_mime,signed_note_data,latitude,longitude,accuracy_m,captured_at,driver_id,vehicle_id,created_by) VALUES (:delivery,:recipient,:observations,:signature_mime,:signature_data,:photo_mime,:photo_data,:note_mime,:note_data,:latitude,:longitude,:accuracy,NOW(),:driver,:vehicle,:user)';
            $insert = $pdo->prepare($sql);
            $insert->bindValue(':delivery', $deliveryId, PDO::PARAM_INT);
            $insert->bindValue(':recipient', $recipient);
            $insert->bindValue(':observations', $observations !== '' ? $observations : null);
            $insert->bindValue(':signature_mime', $signature['mime']);
            $insert->bindValue(':signature_data', $signature['data'], PDO::PARAM_LOB);
            $insert->bindValue(':photo_mime', $photo['mime']);
            $insert->bindValue(':photo_data', $photo['data'], PDO::PARAM_LOB);
            $insert->bindValue(':note_mime', $signedNote['mime'] ?? null);
            if ($signedNote) { $insert->bindValue(':note_data', $signedNote['data'], PDO::PARAM_LOB); }
            else { $insert->bindValue(':note_data', null, PDO::PARAM_NULL); }
            $insert->bindValue(':latitude', (float) $latitude);
            $insert->bindValue(':longitude', (float) $longitude);
            $insert->bindValue(':accuracy', (float) $accuracy);
            $insert->bindValue(':driver', (int) $delivery['driver_id'], PDO::PARAM_INT);
            $insert->bindValue(':vehicle', (int) $delivery['vehicle_id'], PDO::PARAM_INT);
            $insert->bindValue(':user', (int) Auth::id(), PDO::PARAM_INT);
            $insert->execute();
            $podId = (int) $pdo->lastInsertId();

            $pdo->prepare('UPDATE deliveries SET status="Livrée",delivered_at=NOW(),status_before_incident=NULL,updated_by=:user WHERE id=:id')->execute(['user' => Auth::id(), 'id' => $deliveryId]);
            $pdo->prepare('INSERT INTO delivery_status_history (delivery_id,from_status,to_status,comment,changed_by) VALUES (:id,"Arrivée","Livrée","Preuve de livraison numérique enregistrée",:user)')->execute(['id' => $deliveryId, 'user' => Auth::id()]);
            $pdo->prepare('UPDATE drivers SET status="Disponible",updated_by=:user WHERE id=:id')->execute(['user' => Auth::id(), 'id' => $delivery['driver_id']]);
            $pdo->prepare('UPDATE vehicles SET status="Disponible",assigned_driver_id=NULL,updated_by=:user WHERE id=:id')->execute(['user' => Auth::id(), 'id' => $delivery['vehicle_id']]);
            $pdo->prepare('UPDATE vehicle_delivery_history SET completed_at=NOW(),status="Livrée" WHERE delivery_reference=:reference')->execute(['reference' => $delivery['reference']]);
            $pdo->prepare('UPDATE driver_missions SET completed_at=NOW(),status="Terminée" WHERE mission_reference=:reference')->execute(['reference' => $delivery['reference']]);
            $pdo->commit();
            return $podId;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            if ((string) $exception->getCode() === '23000') { throw new RuntimeException('Une preuve de livraison existe déjà pour cette mission.'); }
            throw $exception;
        }
    }

    public static function findByDelivery(int $deliveryId): ?array
    {
        $sql = 'SELECT p.*,d.reference,d.scheduled_at,d.delivered_at,c.company_name,s.name site_name,s.address_line1 site_address,s.city site_city,dr.first_name driver_first_name,dr.last_name driver_last_name,dr.phone driver_phone,v.registration_number,v.brand vehicle_brand,v.model vehicle_model FROM delivery_pods p JOIN deliveries d ON d.id=p.delivery_id JOIN clients c ON c.id=d.client_id JOIN client_sites s ON s.id=d.client_site_id JOIN drivers dr ON dr.id=p.driver_id JOIN vehicles v ON v.id=p.vehicle_id WHERE p.delivery_id=:id';
        $statement = Database::connection()->prepare($sql); $statement->execute(['id' => $deliveryId]); $pod = $statement->fetch();
        if (!$pod) { return null; }
        foreach (['signature_data','delivery_photo_data','signed_note_data'] as $field) { if (is_resource($pod[$field] ?? null)) { $pod[$field] = stream_get_contents($pod[$field]); } }
        $goods = Database::connection()->prepare('SELECT description_snapshot,quantity,unit FROM delivery_goods WHERE delivery_id=:id ORDER BY id');
        $goods->execute(['id' => $deliveryId]); $pod['goods'] = $goods->fetchAll();
        return $pod;
    }

    public static function summary(int $deliveryId): ?array
    {
        $statement = Database::connection()->prepare('SELECT id,recipient_name,observations,latitude,longitude,accuracy_m,captured_at,driver_id,vehicle_id,created_at FROM delivery_pods WHERE delivery_id=:id');
        $statement->execute(['id' => $deliveryId]); $row = $statement->fetch(); return $row ?: null;
    }

    public static function canAccess(int $deliveryId): bool
    {
        if (Auth::can('deliveries.view')) { return true; }
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM deliveries d JOIN drivers dr ON dr.id=d.driver_id WHERE d.id=:id AND dr.user_id=:user');
        $statement->execute(['id' => $deliveryId, 'user' => Auth::id()]);
        return (bool) $statement->fetchColumn();
    }
}
