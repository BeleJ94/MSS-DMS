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
        if($positions===[]||count($positions)>100){throw new RuntimeException('Le lot doit contenir entre 1 et 100 positions.');}
        $validated=array_map([self::class,'validate'],$positions);$pdo=Database::connection();$pdo->beginTransaction();
        try{
            $missionStatement=$pdo->prepare('SELECT d.id,d.driver_id,d.status FROM deliveries d JOIN drivers dr ON dr.id=d.driver_id WHERE d.id=:delivery AND dr.user_id=:user FOR UPDATE');
            $missionStatement->execute(['delivery'=>$deliveryId,'user'=>Auth::id()]);$mission=$missionStatement->fetch();
            if(!$mission){throw new RuntimeException('Mission introuvable ou non autorisée.');}
            if(!in_array($mission['status'],self::ACTIVE_STATUSES,true)){throw new RuntimeException('Le tracking est fermé pour cette mission.');}
            $sql='INSERT INTO delivery_gps_positions (delivery_id,driver_id,device_position_id,latitude,longitude,accuracy_m,altitude_m,speed_mps,heading_deg,captured_at,source) VALUES (:delivery,:driver,:position_id,:latitude,:longitude,:accuracy,:altitude,:speed,:heading,:captured_at,:source) ON DUPLICATE KEY UPDATE device_position_id=VALUES(device_position_id)';
            $insert=$pdo->prepare($sql);$accepted=0;$duplicates=0;$recordedIds=[];$duplicateIds=[];$normalizedSource=in_array($source,['pwa','native-ios','native-android'],true)?$source:'pwa';
            foreach($validated as $position){
                $insert->execute(['delivery'=>$deliveryId,'driver'=>$mission['driver_id'],'position_id'=>$position['position_id'],'latitude'=>$position['latitude'],'longitude'=>$position['longitude'],'accuracy'=>$position['accuracy'],'altitude'=>$position['altitude'],'speed'=>$position['speed'],'heading'=>$position['heading'],'captured_at'=>$position['captured_at'],'source'=>$normalizedSource]);
                if($insert->rowCount()===1){$accepted++;$recordedIds[]=$position['position_id'];}else{$duplicates++;$duplicateIds[]=$position['position_id'];}
            }
            $pdo->commit();
            $positionIds=array_column($validated,'position_id');$placeholders=implode(',',array_fill(0,count($positionIds),'?'));
            $verify=$pdo->prepare('SELECT device_position_id FROM delivery_gps_positions WHERE delivery_id=? AND driver_id=? AND device_position_id IN ('.$placeholders.')');$verify->execute(array_merge([$deliveryId,(int)$mission['driver_id']],$positionIds));$persistedIds=array_map('strval',$verify->fetchAll(\PDO::FETCH_COLUMN));
            $count=$pdo->prepare('SELECT COUNT(*) FROM delivery_gps_positions WHERE delivery_id=:delivery');$count->execute(['delivery'=>$deliveryId]);$total=(int)$count->fetchColumn();
            if(count($persistedIds)!==count($positionIds)){throw new RuntimeException('La base n’a pas confirmé toutes les positions après validation de la transaction.');}
            return ['accepted'=>$accepted,'duplicates'=>$duplicates,'total_positions'=>$total,'recorded_ids'=>$recordedIds,'duplicate_ids'=>$duplicateIds,'persisted_ids'=>$persistedIds,'status'=>$mission['status']];
        }catch(\Throwable $e){if($pdo->inTransaction()){$pdo->rollBack();}throw $e;}
    }

    public static function recentOwned(int $deliveryId,int $limit=60): array
    {
        $limit=max(10,min(100,$limit));$pdo=Database::connection();$owned=$pdo->prepare('SELECT COUNT(*) FROM deliveries d JOIN drivers dr ON dr.id=d.driver_id WHERE d.id=:delivery AND dr.user_id=:user');$owned->execute(['delivery'=>$deliveryId,'user'=>Auth::id()]);if(!(int)$owned->fetchColumn()){throw new RuntimeException('Mission introuvable ou non autorisée.');}$count=$pdo->prepare('SELECT COUNT(*) FROM delivery_gps_positions WHERE delivery_id=:delivery');$count->execute(['delivery'=>$deliveryId]);$positions=$pdo->prepare('SELECT device_position_id,latitude,longitude,accuracy_m,captured_at,received_at,source FROM delivery_gps_positions WHERE delivery_id=:delivery ORDER BY captured_at DESC,id DESC LIMIT '.$limit);$positions->execute(['delivery'=>$deliveryId]);return ['total_positions'=>(int)$count->fetchColumn(),'positions'=>$positions->fetchAll(),'server_time'=>gmdate('c')];
    }

    private static function validate(array $p): array
    {
        $id=trim((string)($p['position_id']??''));$lat=$p['latitude']??null;$lng=$p['longitude']??null;$accuracy=$p['accuracy']??null;if($id===''||strlen($id)>80||!is_numeric($lat)||!is_numeric($lng)||!is_numeric($accuracy)||(float)$lat < -90||(float)$lat > 90||(float)$lng < -180||(float)$lng > 180||(float)$accuracy < 0||(float)$accuracy > 100000){throw new RuntimeException('Une position GPS est invalide.');}$captured=DateTime::createFromFormat(DateTime::ATOM,(string)($p['captured_at']??''));if(!$captured){try{$captured=new DateTime((string)($p['captured_at']??''));}catch(\Throwable $e){throw new RuntimeException('Horodatage GPS invalide.');}}$now=new DateTime('now',new DateTimeZone('UTC'));$captured->setTimezone(new DateTimeZone('UTC'));if($captured->getTimestamp()>$now->getTimestamp()+300||$captured->getTimestamp()<$now->getTimestamp()-604800){throw new RuntimeException('Horodatage GPS hors limites.');}
        return ['position_id'=>$id,'latitude'=>(float)$lat,'longitude'=>(float)$lng,'accuracy'=>(float)$accuracy,'altitude'=>self::numberOrNull($p['altitude']??null),'speed'=>self::numberOrNull($p['speed']??null),'heading'=>self::numberOrNull($p['heading']??null),'captured_at'=>$captured->format('Y-m-d H:i:s.v')];
    }
    private static function numberOrNull($value): ?float{return $value===null||$value===''||!is_numeric($value)?null:(float)$value;}
}
