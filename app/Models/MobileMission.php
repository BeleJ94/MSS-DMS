<?php

declare(strict_types=1);
namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use App\Core\PodPdf;
use PDO;
use RuntimeException;
use Throwable;

/** One transaction per driver intent, including the generated delivery document. */
final class MobileMission
{
    private const START_STAGES=['Brouillon','Affectée','À préparer','Prête','Chargement','Chargée','Partie','En transit'];

    private static function lockOwned(PDO $pdo,int $id): array
    {
        $s=$pdo->prepare('SELECT d.*,dr.user_id FROM deliveries d JOIN drivers dr ON dr.id=d.driver_id WHERE d.id=:id FOR UPDATE');
        $s->execute(['id'=>$id]);$mission=$s->fetch();
        if(!$mission || (int)$mission['user_id']!==(int)Auth::id()){throw new RuntimeException('Mission introuvable ou non autorisée.');}
        return $mission;
    }

    public static function start(int $id,array $position): void
    {
        $pdo=Database::connection();$pdo->beginTransaction();
        try {
            $mission=self::lockOwned($pdo,$id);
            if(!in_array($mission['status'],self::START_STAGES,true)){throw new RuntimeException('Cette mission ne peut pas être commencée.');}
            if(!$mission['vehicle_id']){throw new RuntimeException('Aucun véhicule affecté. Contactez le dispatching.');}
            $next=['Brouillon'=>'Affectée','Affectée'=>'À préparer','À préparer'=>'Prête','Prête'=>'Chargement','Chargement'=>'Chargée','Chargée'=>'Partie','Partie'=>'En transit'];
            $status=$mission['status'];
            while(isset($next[$status])) {
                $target=$next[$status];
                Delivery::transition($id,$target,'Application mobile : étape automatique après « Commencer la mission ».');
                $status=$target;
            }
            // GPS validation or persistence failure rolls back ALL automatic transitions.
            GpsTracking::recordBatch($id,[$position],'native-android');
            $pdo->commit();
        } catch(Throwable $e) {if($pdo->inTransaction()){$pdo->rollBack();}throw $e;}
    }

    public static function deliver(int $id,int $destinationId,array $data,array $photo): array
    {
        if($destinationId<=0){throw new RuntimeException('Choisissez la prochaine destination.');}
        $pdo=Database::connection();$pdo->beginTransaction();
        try {
            $mission=self::lockOwned($pdo,$id);
            // A retry targets the same explicit destination, never the next one.
            $existing=$pdo->prepare('SELECT id FROM delivery_pods WHERE delivery_id=:delivery AND destination_id=:destination');
            $existing->execute(['delivery'=>$id,'destination'=>$destinationId]);$podId=(int)$existing->fetchColumn();
            if($podId){self::ensureDocument($pdo,$id,$destinationId,$podId);$pdo->commit();return ['pod_id'=>$podId,'already_delivered'=>true];}
            if(!in_array($mission['status'],['En transit','Arrivée','Déchargement'],true)){throw new RuntimeException('Commencez la mission ou faites résoudre son incident avant de livrer.');}
            $next=$pdo->prepare('SELECT id FROM delivery_destinations WHERE delivery_id=:id AND status NOT IN ("Livrée","Annulée") ORDER BY stop_order,id LIMIT 1 FOR UPDATE');
            $next->execute(['id'=>$id]);
            if((int)$next->fetchColumn()!==$destinationId){throw new RuntimeException('Livrez les destinations dans l’ordre indiqué. Actualisez la mission.');}
            if($mission['status']==='En transit'){
                Delivery::transition($id,'Arrivée','Application mobile : arrivée enregistrée automatiquement lors de la confirmation de livraison.');
                $pdo->prepare('UPDATE delivery_destinations SET status="Arrivée",arrived_at=COALESCE(arrived_at,NOW()) WHERE id=:id')->execute(['id'=>$destinationId]);
            }
            if($mission['status']!=='Déchargement'){
                Delivery::transition($id,'Déchargement','Application mobile : déchargement enregistré automatiquement lors de la confirmation de livraison.');
                $pdo->prepare('UPDATE delivery_destinations SET status="Déchargement" WHERE id=:id')->execute(['id'=>$destinationId]);
            }
            // Preserve any quantities or anomalies already recorded through the web interface.
            $pdo->prepare('UPDATE delivery_goods SET delivered_quantity=quantity,delivery_condition="Conforme",driver_note="Validation automatique : livraison complète confirmée dans l’application.",checked_at=NOW(),checked_by=:user WHERE delivery_id=:delivery AND destination_id=:destination AND checked_at IS NULL')->execute(['user'=>Auth::id(),'delivery'=>$id,'destination'=>$destinationId]);
            $podId=DeliveryPod::createOwned($id,$destinationId,$data,$photo);
            self::ensureDocument($pdo,$id,$destinationId,$podId);
            $pdo->commit();
            return ['pod_id'=>$podId,'already_delivered'=>false];
        } catch(Throwable $e) {if($pdo->inTransaction()){$pdo->rollBack();}throw $e;}
    }

    private static function ensureDocument(PDO $pdo,int $deliveryId,int $destinationId,int $podId): void
    {
        $s=$pdo->prepare('SELECT pod_id FROM delivery_pod_documents WHERE pod_id=:id');$s->execute(['id'=>$podId]);
        if($s->fetchColumn()){return;}
        $pod=DeliveryPod::findByDestination($deliveryId,$destinationId);
        if(!$pod){throw new RuntimeException('Impossible de préparer le bon de livraison.');}
        $pdf=PodPdf::render($pod);
        if(strncmp($pdf,'%PDF-',5)!==0){throw new RuntimeException('Le bon de livraison n’a pas pu être généré.');}
        $insert=$pdo->prepare('INSERT INTO delivery_pod_documents (pod_id,pdf_data) VALUES (:id,:pdf)');
        $insert->bindValue(':id',$podId,PDO::PARAM_INT);$insert->bindValue(':pdf',$pdf,PDO::PARAM_LOB);$insert->execute();
    }

    public static function document(int $podId): ?string
    {
        $s=Database::connection()->prepare('SELECT pdf_data FROM delivery_pod_documents WHERE pod_id=:id');$s->execute(['id'=>$podId]);$data=$s->fetchColumn();
        return $data===false?null:(is_resource($data)?stream_get_contents($data):(string)$data);
    }
}
