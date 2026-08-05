<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use Throwable;

final class Driver
{
    public static function listing(array $filters): array
    {
        $where=['1=1']; $params=[];
        if(($filters['status']??'')!==''){ $where[]='d.status=:status'; $params['status']=$filters['status']; }
        if(($filters['license_category']??'')!==''){ $where[]='d.license_category=:category'; $params['category']=$filters['license_category']; }
        if(($filters['active']??'1')!=='all'){ $where[]='d.is_active=:active'; $params['active']=(int)$filters['active']; }
        if(($filters['search']??'')!==''){ $term='%'.$filters['search'].'%'; $where[]='(d.first_name LIKE :s1 OR d.last_name LIKE :s2 OR d.code LIKE :s3 OR d.phone LIKE :s4 OR d.license_number LIKE :s5)'; foreach(['s1','s2','s3','s4','s5'] as $key){$params[$key]=$term;} }
        $statement=Database::connection()->prepare('SELECT d.id,d.code,d.first_name,d.last_name,d.photo_mime,d.phone,d.license_number,d.license_category,d.license_expires_at,d.status,d.is_active,d.updated_at, (d.photo_data IS NOT NULL) has_photo, (SELECT COUNT(*) FROM driver_missions m WHERE m.driver_id=d.id) missions_count, (SELECT COUNT(*) FROM driver_incidents i WHERE i.driver_id=d.id AND i.status<>"Résolu") open_incidents FROM drivers d WHERE '.implode(' AND ',$where).' ORDER BY d.last_name,d.first_name');
        $statement->execute($params); return $statement->fetchAll();
    }
    public static function categories(): array { return Database::connection()->query("SELECT DISTINCT license_category FROM drivers WHERE license_category<>'' ORDER BY license_category")->fetchAll(PDO::FETCH_COLUMN); }
    public static function mobileUsers(): array {return Database::connection()->query("SELECT DISTINCT u.id,u.name,u.email FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE u.is_active=1 AND r.slug='chauffeur' ORDER BY u.name")->fetchAll();}
    public static function find(int $id): ?array
    {
        $s=Database::connection()->prepare('SELECT d.*, (d.photo_data IS NOT NULL) has_photo,uc.name creator_name,uu.name updater_name FROM drivers d LEFT JOIN users uc ON uc.id=d.created_by LEFT JOIN users uu ON uu.id=d.updated_by WHERE d.id=:id'); $s->execute(['id'=>$id]); $driver=$s->fetch(); if(!$driver){return null;} unset($driver['photo_data']);
        $m=Database::connection()->prepare('SELECT * FROM driver_missions WHERE driver_id=:id ORDER BY COALESCE(started_at,created_at) DESC,id DESC');$m->execute(['id'=>$id]);$driver['missions']=$m->fetchAll();
        $i=Database::connection()->prepare('SELECT * FROM driver_incidents WHERE driver_id=:id ORDER BY occurred_at DESC,id DESC');$i->execute(['id'=>$id]);$driver['incidents']=$i->fetchAll();
        $h=Database::connection()->prepare('SELECT h.*,u.name user_name FROM driver_history h LEFT JOIN users u ON u.id=h.user_id WHERE h.driver_id=:id ORDER BY h.created_at DESC,h.id DESC');$h->execute(['id'=>$id]);$driver['history']=$h->fetchAll(); return $driver;
    }
    public static function create(array $data, ?array $photo): int
    {
        $pdo=Database::connection();$pdo->beginTransaction();try{$code=self::nextCode($pdo);$p=self::params($data);$p['code']=$code;$p['photo_mime']=$photo['mime']??null;$p['photo_data']=$photo['data']??null;$p['created_by']=Auth::id();$p['updated_by']=Auth::id();$sql='INSERT INTO drivers (user_id,code,first_name,last_name,date_of_birth,gender,photo_mime,photo_data,phone,alternate_phone,email,address,city,license_number,license_category,license_issued_at,license_expires_at,status,available_from,emergency_contact_name,emergency_contact_phone,notes,is_active,created_by,updated_by) VALUES (:user_id,:code,:first_name,:last_name,:date_of_birth,:gender,:photo_mime,:photo_data,:phone,:alternate_phone,:email,:address,:city,:license_number,:license_category,:license_issued_at,:license_expires_at,:status,:available_from,:emergency_contact_name,:emergency_contact_phone,:notes,1,:created_by,:updated_by)';$s=$pdo->prepare($sql);$s->execute($p);$id=(int)$pdo->lastInsertId();self::history($pdo,$id,'created','Chauffeur créé',['code'=>$code]);$pdo->commit();return $id;}catch(Throwable $e){if($pdo->inTransaction()){$pdo->rollBack();}throw $e;}
    }
    public static function update(int $id,array $data,?array $photo): bool
    {
        $old=self::find($id);if(!$old){return false;}$pdo=Database::connection();$pdo->beginTransaction();try{$p=self::params($data);$p['id']=$id;$p['updated_by']=Auth::id();$photoSql='';if($photo){$photoSql=',photo_mime=:photo_mime,photo_data=:photo_data';$p['photo_mime']=$photo['mime'];$p['photo_data']=$photo['data'];}$sql='UPDATE drivers SET user_id=:user_id,first_name=:first_name,last_name=:last_name,date_of_birth=:date_of_birth,gender=:gender,phone=:phone,alternate_phone=:alternate_phone,email=:email,address=:address,city=:city,license_number=:license_number,license_category=:license_category,license_issued_at=:license_issued_at,license_expires_at=:license_expires_at,status=:status,available_from=:available_from,emergency_contact_name=:emergency_contact_name,emergency_contact_phone=:emergency_contact_phone,notes=:notes,updated_by=:updated_by'.$photoSql.' WHERE id=:id';$pdo->prepare($sql)->execute($p);self::history($pdo,$id,'updated','Dossier chauffeur mis à jour',['status'=>[$old['status'],$data['status']??'']]);$pdo->commit();return true;}catch(Throwable $e){if($pdo->inTransaction()){$pdo->rollBack();}throw $e;}
    }
    public static function deactivate(int $id): bool {$pdo=Database::connection();$s=$pdo->prepare("UPDATE drivers SET is_active=0,status='Indisponible',updated_by=:user WHERE id=:id AND is_active=1");$s->execute(['id'=>$id,'user'=>Auth::id()]);if($s->rowCount()){self::history($pdo,$id,'deactivated','Chauffeur désactivé',null);return true;}return false;}
    public static function photo(int $id): ?array {$s=Database::connection()->prepare('SELECT photo_mime,photo_data FROM drivers WHERE id=:id AND photo_data IS NOT NULL');$s->execute(['id'=>$id]);$photo=$s->fetch();return $photo?:null;}
    private static function params(array $d): array {$fields=['first_name','last_name','date_of_birth','gender','phone','alternate_phone','email','address','city','license_number','license_category','license_issued_at','license_expires_at','status','available_from','emergency_contact_name','emergency_contact_phone','notes'];$p=['user_id'=>!empty($d['user_id'])?(int)$d['user_id']:null];foreach($fields as $f){$v=trim((string)($d[$f]??''));$p[$f]=$v===''?null:$v;}return $p;}
    private static function nextCode(PDO $pdo): string {return 'CHF-'.str_pad((string)((int)$pdo->query('SELECT COALESCE(MAX(id),0)+1 FROM drivers')->fetchColumn()),5,'0',STR_PAD_LEFT);}
    private static function history(PDO $pdo,int $id,string $action,string $description,?array $changes): void {$s=$pdo->prepare('INSERT INTO driver_history (driver_id,user_id,action,description,changes_json) VALUES (:id,:user,:action,:description,:changes)');$s->execute(['id'=>$id,'user'=>Auth::id(),'action'=>$action,'description'=>$description,'changes'=>$changes?json_encode($changes,JSON_UNESCAPED_UNICODE):null]);}
}
