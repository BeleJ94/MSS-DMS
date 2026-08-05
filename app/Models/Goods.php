<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;

final class Goods
{
    public static function listing(array $filters): array
    {
        $where=['1=1'];$params=[];if(($filters['status']??'')!==''){$where[]='g.status=:status';$params['status']=$filters['status'];}if(($filters['unit']??'')!==''){$where[]='g.unit=:unit';$params['unit']=$filters['unit'];}if(($filters['search']??'')!==''){$term='%'.$filters['search'].'%';$where[]='(g.reference LIKE :s1 OR g.designation LIKE :s2 OR g.description LIKE :s3)';$params['s1']=$term;$params['s2']=$term;$params['s3']=$term;}$sql='SELECT g.*,(SELECT COUNT(*) FROM delivery_goods dg WHERE dg.goods_id=g.id) deliveries_count FROM goods g WHERE '.implode(' AND ',$where).' ORDER BY g.designation';$s=Database::connection()->prepare($sql);$s->execute($params);return $s->fetchAll();
    }
    public static function units(): array{return Database::connection()->query("SELECT DISTINCT unit FROM goods WHERE unit<>'' ORDER BY unit")->fetchAll(\PDO::FETCH_COLUMN);}
    public static function find(int $id): ?array{$s=Database::connection()->prepare('SELECT g.*,(SELECT COUNT(*) FROM delivery_goods dg WHERE dg.goods_id=g.id) deliveries_count FROM goods g WHERE g.id=:id');$s->execute(['id'=>$id]);$g=$s->fetch();return $g?:null;}
    public static function create(array $data): int{$s=Database::connection()->prepare('INSERT INTO goods (reference,designation,description,unit,unit_weight_kg,status,created_by,updated_by) VALUES (:reference,:designation,:description,:unit,:weight,:status,:user,:user_update)');$s->execute(self::params($data)+['user'=>Auth::id(),'user_update'=>Auth::id()]);return (int)Database::connection()->lastInsertId();}
    public static function update(int $id,array $data): bool{$p=self::params($data);$p['id']=$id;$p['user']=Auth::id();$s=Database::connection()->prepare('UPDATE goods SET reference=:reference,designation=:designation,description=:description,unit=:unit,unit_weight_kg=:weight,status=:status,updated_by=:user WHERE id=:id');$s->execute($p);return $s->rowCount()>0||self::find($id)!==null;}
    public static function archive(int $id): bool{$s=Database::connection()->prepare("UPDATE goods SET status='Archivé',updated_by=:user WHERE id=:id AND status<>'Archivé'");$s->execute(['id'=>$id,'user'=>Auth::id()]);return $s->rowCount()===1;}
    private static function params(array $d): array{return ['reference'=>mb_strtoupper(trim((string)($d['reference']??''))),'designation'=>trim((string)($d['designation']??'')),'description'=>self::nullable($d['description']??null),'unit'=>trim((string)($d['unit']??'')),'weight'=>($d['unit_weight_kg']??'')===''?null:(float)$d['unit_weight_kg'],'status'=>$d['status']??'Actif'];}
    private static function nullable($v){$v=trim((string)$v);return $v===''?null:$v;}
}

