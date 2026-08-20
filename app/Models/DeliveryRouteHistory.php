<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class DeliveryRouteHistory
{
    private const MAP_POINT_LIMIT = 1500;

    public static function forDelivery(int $deliveryId): ?array
    {
        $deliveryStatement = Database::connection()->prepare(
            'SELECT d.id,d.reference,d.status,c.company_name,dd.label site_name,dd.city site_city,
                    dd.latitude destination_latitude,dd.longitude destination_longitude,
                    CONCAT(dr.first_name," ",dr.last_name) driver_name,v.registration_number
             FROM deliveries d
             JOIN clients c ON c.id=d.client_id
             LEFT JOIN delivery_destinations dd ON dd.id=(SELECT dx.id FROM delivery_destinations dx WHERE dx.delivery_id=d.id ORDER BY dx.stop_order DESC LIMIT 1)
             LEFT JOIN drivers dr ON dr.id=d.driver_id
             LEFT JOIN vehicles v ON v.id=d.vehicle_id
             WHERE d.id=:id'
        );
        $deliveryStatement->execute(['id' => $deliveryId]);
        $delivery = $deliveryStatement->fetch();
        if (!$delivery) {
            return null;
        }

        $positionStatement = Database::connection()->prepare(
            'SELECT id,latitude,longitude,accuracy_m,altitude_m,speed_mps,heading_deg,captured_at,received_at
             FROM delivery_gps_positions
             WHERE delivery_id=:id
             ORDER BY captured_at,id
             LIMIT 20000'
        );
        $positionStatement->execute(['id' => $deliveryId]);
        $positions = $positionStatement->fetchAll();

        return [
            'delivery' => $delivery,
            'summary' => self::summary($positions),
            'points' => self::mapPoints($positions),
        ];
    }

    private static function summary(array $positions): array
    {
        $count = count($positions);
        $distanceKm = 0.0;
        $gaps = 0;
        $maxGapSeconds = 0;
        $accuracies = [];
        $previous = null;

        foreach ($positions as $position) {
            $accuracy = (float) $position['accuracy_m'];
            if ($accuracy >= 0) {
                $accuracies[] = $accuracy;
            }
            if ($previous !== null) {
                $distanceKm += self::distanceKm(
                    (float) $previous['latitude'],
                    (float) $previous['longitude'],
                    (float) $position['latitude'],
                    (float) $position['longitude']
                );
                $gap = max(0, strtotime((string) $position['captured_at']) - strtotime((string) $previous['captured_at']));
                if ($gap > 180) {
                    $gaps++;
                    $maxGapSeconds = max($maxGapSeconds, $gap);
                }
            }
            $previous = $position;
        }

        $startedAt = $count ? $positions[0]['captured_at'] : null;
        $endedAt = $count ? $positions[$count - 1]['captured_at'] : null;
        $durationSeconds = $count > 1 ? max(0, strtotime((string) $endedAt) - strtotime((string) $startedAt)) : 0;

        return [
            'position_count' => $count,
            'distance_km' => round($distanceKm, 2),
            'duration_seconds' => $durationSeconds,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'transmission_gaps' => $gaps,
            'max_gap_seconds' => $maxGapSeconds,
            'average_accuracy_m' => $accuracies ? round(array_sum($accuracies) / count($accuracies), 1) : null,
        ];
    }

    private static function mapPoints(array $positions): array
    {
        $count = count($positions);
        if ($count <= self::MAP_POINT_LIMIT) {
            return array_map([self::class, 'normalizePoint'], $positions);
        }

        $step = ($count - 1) / (self::MAP_POINT_LIMIT - 1);
        $selected = [];
        for ($index = 0; $index < self::MAP_POINT_LIMIT; $index++) {
            $sourceIndex = min($count - 1, (int) round($index * $step));
            $selected[$sourceIndex] = self::normalizePoint($positions[$sourceIndex]);
        }
        ksort($selected);
        return array_values($selected);
    }

    private static function normalizePoint(array $position): array
    {
        return [
            'latitude' => (float) $position['latitude'],
            'longitude' => (float) $position['longitude'],
            'accuracy_m' => (float) $position['accuracy_m'],
            'altitude_m' => $position['altitude_m'] !== null ? (float) $position['altitude_m'] : null,
            'speed_mps' => $position['speed_mps'] !== null ? (float) $position['speed_mps'] : null,
            'heading_deg' => $position['heading_deg'] !== null ? (float) $position['heading_deg'] : null,
            'captured_at' => $position['captured_at'],
            'received_at' => $position['received_at'],
        ];
    }

    private static function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371.0088;
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;
        return $earthRadius * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }
}
