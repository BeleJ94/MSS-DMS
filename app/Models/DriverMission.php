<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use RuntimeException;
use Throwable;

final class DriverMission
{
    public static function driver(): ?array
    {
        $s=Database::connection()->prepare('SELECT id,first_name,last_name,phone FROM drivers WHERE user_id=:user AND is_active=1');$s->execute(['user'=>Auth::id()]);$row=$s->fetch();return $row?:null;
    }

    public static function listing(): array
    {
        $sql='SELECT d.id,d.reference,d.scheduled_at,d.priority,d.status,c.company_name,fd.label site_name,fd.city,fd.address_line address_line1,(SELECT COUNT(*) FROM delivery_destinations dc WHERE dc.delivery_id=d.id) destination_count,(SELECT COUNT(*) FROM delivery_destinations dc WHERE dc.delivery_id=d.id AND dc.status="Livrée") delivered_destination_count FROM deliveries d JOIN drivers dr ON dr.id=d.driver_id JOIN clients c ON c.id=d.client_id LEFT JOIN delivery_destinations fd ON fd.id=(SELECT dx.id FROM delivery_destinations dx WHERE dx.delivery_id=d.id AND dx.status NOT IN ("Livrée","Annulée") ORDER BY dx.stop_order LIMIT 1) WHERE dr.user_id=:user AND d.status<>"Annulée" ORDER BY FIELD(d.status,"Partie","En transit","Arrivée","Incident","Chargée","Chargement","Prête","À préparer","Affectée","Brouillon","Livrée","Clôturée"),d.scheduled_at DESC';
        $s=Database::connection()->prepare($sql);$s->execute(['user'=>Auth::id()]);return $s->fetchAll();
    }

    public static function findOwned(int $id): ?array
    {
        $sql='SELECT d.*,c.company_name,fd.id current_destination_id,fd.stop_order current_stop_order,fd.label site_name,fd.address_line site_address,fd.city site_city,fd.latitude,fd.longitude,fd.instructions delivery_instructions,fd.contact_name,fd.contact_phone,NULL contact_email,v.registration_number FROM deliveries d JOIN drivers dr ON dr.id=d.driver_id JOIN clients c ON c.id=d.client_id LEFT JOIN delivery_destinations fd ON fd.id=(SELECT dx.id FROM delivery_destinations dx WHERE dx.delivery_id=d.id AND dx.status NOT IN ("Livrée","Annulée") ORDER BY dx.stop_order LIMIT 1) LEFT JOIN vehicles v ON v.id=d.vehicle_id WHERE d.id=:id AND dr.user_id=:user';
        $s=Database::connection()->prepare($sql);$s->execute(['id'=>$id,'user'=>Auth::id()]);$d=$s->fetch();if(!$d){return null;}$dest=Database::connection()->prepare('SELECT * FROM delivery_destinations WHERE delivery_id=:id ORDER BY stop_order');$dest->execute(['id'=>$id]);$d['destinations']=$dest->fetchAll();if($d['current_destination_id']){$g=Database::connection()->prepare('SELECT id,description_snapshot,quantity,unit,destination_id,delivered_quantity,delivery_condition,driver_note,checked_at FROM delivery_goods WHERE delivery_id=:id AND (destination_id=:destination OR destination_id IS NULL) ORDER BY id');$g->execute(['id'=>$id,'destination'=>$d['current_destination_id']]);}else{$g=Database::connection()->prepare('SELECT id,description_snapshot,quantity,unit,destination_id,delivered_quantity,delivery_condition,driver_note,checked_at FROM delivery_goods WHERE delivery_id=:id ORDER BY id');$g->execute(['id'=>$id]);}$d['goods']=$g->fetchAll();$d['unloading_complete']=$d['goods']!==[]&&count(array_filter($d['goods'],function(array $row):bool{return !empty($row['checked_at']);}))===count($d['goods']);return $d;
    }

    public static function perform(int $id,string $action,?string $description=null): string
    {
        $mission=self::findOwned($id);if(!$mission){throw new RuntimeException('Mission introuvable ou non autorisée.');}
        if($action==='accept'){
            if($mission['status']==='Brouillon'){Delivery::transition($id,'Affectée','Mission affectée confirmée par le chauffeur');$mission=self::findOwned($id);}
            if($mission['status']!=='Affectée'){throw new RuntimeException('Cette mission ne peut plus être acceptée à cette étape.');}Delivery::transition($id,'À préparer','Mission acceptée par le chauffeur');return 'Mission acceptée. Vous êtes désormais responsable de sa progression.';
        }
        if($action==='prepare'){if($mission['status']!=='À préparer'){throw new RuntimeException('La préparation ne peut pas être confirmée à cette étape.');}Delivery::transition($id,'Prête','Préparation confirmée par le chauffeur');return 'Préparation terminée. La mission est prête au chargement.';}
        if($action==='load'){
            if($mission['status']==='Brouillon'){Delivery::transition($id,'Affectée','Mission affectée confirmée au démarrage du chargement');$mission=self::findOwned($id);}
            if($mission['status']==='Affectée'){Delivery::transition($id,'À préparer','Préparation administrative validée automatiquement');$mission=self::findOwned($id);}
            if($mission['status']==='À préparer'){Delivery::transition($id,'Prête','Mission déclarée prête automatiquement');$mission=self::findOwned($id);}
            if($mission['status']==='Prête'){Delivery::transition($id,'Chargement','Chargement confirmé par le chauffeur');$mission=self::findOwned($id);}
            if($mission['status']!=='Chargement'){throw new RuntimeException('Le chargement ne peut pas être confirmé à cette étape.');}
            Delivery::transition($id,'Chargée','Véhicule déclaré chargé par le chauffeur');return 'Chargement confirmé. Vous pouvez signaler le départ.';
        }
        if($action==='loaded'){if($mission['status']!=='Chargement'){throw new RuntimeException('La fin du chargement ne peut pas être confirmée à cette étape.');}Delivery::transition($id,'Chargée','Chargement terminé et contrôlé par le chauffeur');return 'Chargement confirmé. La mission peut démarrer.';}
        if($action==='start'){
            if($mission['status']==='Chargée'){Delivery::transition($id,'Partie','Mission démarrée par le chauffeur');Delivery::transition($id,'En transit','Chauffeur en route');return 'Mission démarrée.';}
            if($mission['status']==='Partie'){Delivery::transition($id,'En transit','Chauffeur en route');return 'Mission démarrée.';}
            if($mission['status']==='En transit'){return 'Mission déjà active.';}
            throw new RuntimeException('La mission doit être chargée avant le départ.');
        }
        if($action==='arrive'){if($mission['status']!=='En transit'){throw new RuntimeException('Cette mission ne peut pas encore être marquée arrivée.');}if(!$mission['current_destination_id']){throw new RuntimeException('Aucune destination restante.');}Delivery::transition($id,'Arrivée','Arrivée à la destination '.$mission['current_stop_order']);Database::connection()->prepare('UPDATE delivery_destinations SET status="Arrivée",arrived_at=NOW() WHERE id=:id')->execute(['id'=>$mission['current_destination_id']]);return 'Arrivée confirmée à la destination '.$mission['current_stop_order'].'.';}
        if($action==='unload'){if($mission['status']!=='Arrivée'){throw new RuntimeException('Le déchargement ne peut pas être démarré à cette étape.');}Delivery::transition($id,'Déchargement','Déchargement démarré à la destination '.$mission['current_stop_order']);Database::connection()->prepare('UPDATE delivery_destinations SET status="Déchargement" WHERE id=:id')->execute(['id'=>$mission['current_destination_id']]);return 'Déchargement démarré. Contrôlez les marchandises.';}
        if($action==='deliver'){throw new RuntimeException('Confirmez la livraison effectuée avec le nom du réceptionnaire, la photo et la position GPS.');}
        if($action==='incident'){throw new RuntimeException('Utilisez le formulaire complet de signalement d’incident.');}
        throw new RuntimeException('Action inconnue.');
    }

    public static function nextAction(string $status): ?array
    {
        $actions=['Brouillon'=>['action'=>'load','label'=>'Véhicule chargé','icon'=>'package-check'],'Affectée'=>['action'=>'load','label'=>'Véhicule chargé','icon'=>'package-check'],'À préparer'=>['action'=>'load','label'=>'Véhicule chargé','icon'=>'package-check'],'Prête'=>['action'=>'load','label'=>'Véhicule chargé','icon'=>'package-check'],'Chargement'=>['action'=>'load','label'=>'Véhicule chargé','icon'=>'package-check'],'Chargée'=>['action'=>'start','label'=>'Confirmer le départ','icon'=>'navigation'],'Partie'=>['action'=>'start','label'=>'Confirmer le départ','icon'=>'navigation'],'En transit'=>['action'=>'arrive','label'=>'Confirmer mon arrivée','icon'=>'map-pin-check'],'Arrivée'=>['action'=>'unload','label'=>'Commencer le déchargement','icon'=>'package-open']];return $actions[$status]??null;
    }

    public static function confirmUnloading(int $id,array $rows): string
    {
        $pdo=Database::connection();$pdo->beginTransaction();try{$mission=$pdo->prepare('SELECT d.status,dr.user_id,(SELECT dd.id FROM delivery_destinations dd WHERE dd.delivery_id=d.id AND dd.status NOT IN ("Livrée","Annulée") ORDER BY dd.stop_order LIMIT 1) destination_id FROM deliveries d JOIN drivers dr ON dr.id=d.driver_id WHERE d.id=:id FOR UPDATE');$mission->execute(['id'=>$id]);$delivery=$mission->fetch();if(!$delivery||(int)$delivery['user_id']!==(int)Auth::id()){throw new RuntimeException('Mission introuvable ou non autorisée.');}if($delivery['status']!=='Déchargement'||!(int)$delivery['destination_id']){throw new RuntimeException('Le contrôle est disponible uniquement pendant le déchargement.');}$goods=$pdo->prepare('SELECT id,quantity FROM delivery_goods WHERE delivery_id=:delivery AND destination_id=:destination ORDER BY id FOR UPDATE');$goods->execute(['delivery'=>$id,'destination'=>$delivery['destination_id']]);$expected=[];foreach($goods->fetchAll() as $row){$expected[(int)$row['id']]=(float)$row['quantity'];}if($expected===[]||count($rows)!==count($expected)){throw new RuntimeException('Contrôlez toutes les marchandises de cette destination.');}$allowed=['Conforme','Partielle','Refusée','Endommagée','Manquante'];$update=$pdo->prepare('UPDATE delivery_goods SET delivered_quantity=:quantity,delivery_condition=:condition,driver_note=:note,checked_at=NOW(),checked_by=:user WHERE id=:id AND delivery_id=:delivery');$seen=[];foreach($rows as $row){$goodsId=(int)($row['id']??0);if(!isset($expected[$goodsId])||isset($seen[$goodsId])){throw new RuntimeException('Une ligne de contrôle est invalide.');}$seen[$goodsId]=true;$quantity=$row['delivered_quantity']??null;$condition=trim((string)($row['condition']??''));$note=trim((string)($row['note']??''));if(!is_numeric($quantity)||(float)$quantity<0||(float)$quantity>$expected[$goodsId]||!in_array($condition,$allowed,true)){throw new RuntimeException('Une quantité ou un état de livraison est invalide.');}$quantity=(float)$quantity;if($condition==='Conforme'&&abs($quantity-$expected[$goodsId])>0.0001){throw new RuntimeException('Une ligne conforme doit reprendre toute la quantité prévue.');}if(in_array($condition,['Refusée','Manquante'],true)&&$quantity>0){throw new RuntimeException('Une ligne refusée ou manquante doit avoir une quantité livrée nulle.');}if($condition!=='Conforme'&&mb_strlen($note)<3){throw new RuntimeException('Précisez brièvement le motif de chaque anomalie.');}if(mb_strlen($note)>500){throw new RuntimeException('Une observation est trop longue.');}$update->execute(['quantity'=>$quantity,'condition'=>$condition,'note'=>$note?:null,'user'=>Auth::id(),'id'=>$goodsId,'delivery'=>$id]);}$pdo->commit();return 'Contrôle du déchargement enregistré. Vous pouvez confirmer la livraison effectuée.';}catch(Throwable $e){if($pdo->inTransaction()){$pdo->rollBack();}throw $e;}
    }
}
