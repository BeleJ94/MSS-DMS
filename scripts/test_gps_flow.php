<?php

declare(strict_types=1);

/** @var App\Core\Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Database;
use App\Core\Session;
use App\Models\GpsTracking;
use App\Models\LiveTracking;
use App\Models\DeliveryRouteHistory;

$pdo = Database::connection();
$deliveryId = null;
$reference = 'TEST-GPS-' . date('YmdHis');

function expectGps(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "OK - {$message}\n";
}

try {
    $resource = $pdo->query(
        'SELECT dr.id driver_id, dr.user_id, v.id vehicle_id,
                d.client_id, d.client_site_id, d.client_contact_id
         FROM deliveries d
         JOIN drivers dr ON dr.id = d.driver_id
         JOIN vehicles v ON v.id = d.vehicle_id
         WHERE dr.user_id IS NOT NULL
         LIMIT 1'
    )->fetch();
    if (!$resource) {
        throw new RuntimeException('Aucune affectation chauffeur/véhicule disponible pour le test GPS.');
    }

    Session::put('auth_user_id', (int) $resource['user_id']);
    $insert = $pdo->prepare(
        'INSERT INTO deliveries
         (reference,client_id,client_site_id,client_contact_id,scheduled_at,priority,driver_id,vehicle_id,status,created_by,updated_by)
         VALUES (:reference,:client,:site,:contact,NOW(),"Normale",:driver,:vehicle,"En transit",:created_user,:updated_user)'
    );
    $insert->execute([
        'reference' => $reference,
        'client' => $resource['client_id'],
        'site' => $resource['client_site_id'],
        'contact' => $resource['client_contact_id'],
        'driver' => $resource['driver_id'],
        'vehicle' => $resource['vehicle_id'],
        'created_user' => $resource['user_id'],
        'updated_user' => $resource['user_id'],
    ]);
    $deliveryId = (int) $pdo->lastInsertId();

    $positionId = 'audit-' . bin2hex(random_bytes(8));
    $position = [
        'position_id' => $positionId,
        'latitude' => -11.6647,
        'longitude' => 27.4794,
        'accuracy' => 8.4,
        'altitude' => 1250.2,
        'speed' => 3.25,
        'heading' => 91.5,
        'captured_at' => gmdate('c'),
    ];
    $first = GpsTracking::recordBatch($deliveryId, [$position]);
    expectGps($first['accepted'] === 1, 'la première position est acceptée');
    $duplicate = GpsTracking::recordBatch($deliveryId, [$position]);
    expectGps($duplicate['duplicates'] === 1, 'une retransmission hors ligne est dédupliquée');
    $stored = $pdo->query('SELECT * FROM delivery_gps_positions WHERE delivery_id=' . $deliveryId)->fetch();
    expectGps($stored && abs((float) $stored['latitude'] - (-11.6647)) < 0.00001, 'les coordonnées GPS sont persistées');
    expectGps($stored && $stored['source'] === 'pwa', 'la source PWA est conservée');
    $visible = null;
    foreach (LiveTracking::positions() as $trackedDelivery) {
        if ((int) $trackedDelivery['id'] === $deliveryId) {
            $visible = $trackedDelivery;
            break;
        }
    }
    expectGps($visible !== null, 'la mission apparaît dans le suivi en direct administrateur');
    expectGps(abs((float) $visible['longitude'] - 27.4794) < 0.00001, 'le suivi en direct restitue la dernière position enregistrée');

    $secondPosition = array_merge($position, [
        'position_id' => $positionId . '-second',
        'latitude' => -11.6589,
        'longitude' => 27.4862,
        'captured_at' => gmdate('c', time() + 60),
    ]);
    GpsTracking::recordBatch($deliveryId, [$secondPosition]);
    $route = DeliveryRouteHistory::forDelivery($deliveryId);
    expectGps($route !== null && $route['summary']['position_count'] === 2, 'l’historique restitue toutes les positions du trajet');
    expectGps($route['summary']['distance_km'] > 0, 'la distance historique est calculée');
    expectGps(count($route['points']) === 2, 'les points cartographiques sont ordonnés et disponibles');

    $pdo->prepare('UPDATE deliveries SET status="Livrée" WHERE id=:id')->execute(['id' => $deliveryId]);
    $closedRoute = DeliveryRouteHistory::forDelivery($deliveryId);
    expectGps($closedRoute !== null && $closedRoute['summary']['position_count'] === 2, 'le trajet reste consultable après la livraison');
    try {
        GpsTracking::recordBatch($deliveryId, [array_merge($position, ['position_id' => $positionId . '-closed'])]);
        throw new RuntimeException('Le tracking aurait dû être refusé après la mission.');
    } catch (RuntimeException $exception) {
        expectGps(strpos($exception->getMessage(), 'tracking est fermé') !== false, 'le tracking est refusé hors mission active');
    }
    echo "GPS_FLOW_OK\n";
} finally {
    if ($deliveryId) {
        $pdo->prepare('DELETE FROM deliveries WHERE id=:id')->execute(['id' => $deliveryId]);
    }
}
