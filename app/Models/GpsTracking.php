<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use DateTime;
use DateTimeZone;
use RuntimeException;

final class GpsTracking
{
    public const ACTIVE_STATUSES=['Partie','En transit','Arrivée','Incident'];

    public static function activeMissionId(): ?int
    {
        $placeholders=implode(',',array_fill(0,count(self::ACTIVE_STATUSES),'?'));$s=Database::connection()->prepare('SELECT d.id FROM deliveries d JOIN drivers dr ON dr.id=d.driver_id WHERE dr.user_id=? AND d.status IN ('.$placeholders.') ORDER BY d.updated_at DESC LIMIT 1');$s->execute(array_merge([Auth::id()],self::ACTIVE_STATUSES));$id=$s->fetchColumn();return $id?(int)$id:null;
    }

    public static function recordBatch(int $deliveryId,array $positions,string $source='pwa'): array
    {
        if($positions===[]||count($positions)>100){throw new RuntimeException('Le lot doit contenir entre 1 et 100 positions.');}$pdo=Database::connection();$s=$pdo->prepare('SELECT d.id,d.driver_id,d.status FROM deliveries d JOIN drivers dr ON dr.id=d.driver_id WHERE d.id=:delivery AND dr.user_id=:user');$s->execute(['delivery'=>$deliveryId,'user'=>Auth::id()]);$mission=$s->fetch();if(!$mission){throw new RuntimeException('Mission introuvable ou non autorisée.');}if(!in_array($mission['status'],self::ACTIVE_STATUSES,true)){throw new RuntimeException('Le tracking est fermé pour cette mission.');}
        $insert=$pdo->prepare('INSERT IGNORE INTO delivery_gps_positions (delivery_id,driver_id,device_position_id,latitude,longitude,accuracy_m,altitude_m,speed_mps,heading_deg,captured_at,source) VALUES (:delivery,:driver,:position_id,:latitude,:longitude,:accuracy,:altitude,:speed,:heading,:captured_at,:source)');$accepted=0;$duplicates=0;$pdo->beginTransaction();try{foreach($positions as $position){$p=self::validate($position);$insert->execute(['delivery'=>$deliveryId,'driver'=>$mission['driver_id'],'position_id'=>$p['position_id'],'latitude'=>$p['latitude'],'longitude'=>$p['longitude'],'accuracy'=>$p['accuracy'],'altitude'=>$p['altitude'],'speed'=>$p['speed'],'heading'=>$p['heading'],'captured_at'=>$p['captured_at'],'source'=>in_array($source,['pwa','native-ios','native-android'],true)?$source:'pwa']);if($insert->rowCount()){$accepted++;}else{$duplicates++;}}$pdo->commit();return ['accepted'=>$accepted,'duplicates'=>$duplicates,'status'=>$mission['status']];}catch(\Throwable $e){if($pdo->inTransaction()){$pdo->rollBack();}throw $e;}
    }

    private static function validate(array $p): array
    {
        $id=trim((string)($p['position_id']??''));$lat=$p['latitude']??null;$lng=$p['longitude']??null;$accuracy=$p['accuracy']??null;if($id===''||strlen($id)>80||!is_numeric($lat)||!is_numeric($lng)||!is_numeric($accuracy)||(float)$lat < -90||(float)$lat > 90||(float)$lng < -180||(float)$lng > 180||(float)$accuracy < 0||(float)$accuracy > 100000){throw new RuntimeException('Une position GPS est invalide.');}$captured=DateTime::createFromFormat(DateTime::ATOM,(string)($p['captured_at']??''));if(!$captured){try{$captured=new DateTime((string)($p['captured_at']??''));}catch(\Throwable $e){throw new RuntimeException('Horodatage GPS invalide.');}}$now=new DateTime('now',new DateTimeZone('UTC'));$captured->setTimezone(new DateTimeZone('UTC'));if($captured->getTimestamp()>$now->getTimestamp()+300||$captured->getTimestamp()<$now->getTimestamp()-604800){throw new RuntimeException('Horodatage GPS hors limites.');}
        return ['position_id'=>$id,'latitude'=>(float)$lat,'longitude'=>(float)$lng,'accuracy'=>(float)$accuracy,'altitude'=>self::numberOrNull($p['altitude']??null),'speed'=>self::numberOrNull($p['speed']??null),'heading'=>self::numberOrNull($p['heading']??null),'captured_at'=>$captured->format('Y-m-d H:i:s.v')];
    }
    private static function numberOrNull($value): ?float{return $value===null||$value===''||!is_numeric($value)?null:(float)$value;}
}
