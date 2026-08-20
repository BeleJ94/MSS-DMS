<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\PodPdf;
use App\Core\PodUpload;
use App\Core\Request;
use App\Core\Response;
use App\Models\DeliveryPod;
use RuntimeException;
use Throwable;

final class PodController extends Controller
{
    public function store(Request $request): Response
    {
        if (!Auth::can('driver_app.access')) { return $this->json(['success' => false, 'message' => 'Accès refusé.'], 403); }
        try {
            $signature = PodUpload::signature((string) $request->input('signature', ''));
            $photo = PodUpload::photo($request->file('delivery_photo'), true, 'La photo de livraison');
            $signedNote = PodUpload::photo($request->file('signed_note_photo'), false, 'La photo du bon signé');
            $destinationId=(int)$request->input('destination_id',0);
            $podId=DeliveryPod::createOwned((int)$request->param('id'),$destinationId,$request->all(),$signature,$photo,$signedNote);$destinationId=DeliveryPod::destinationId($podId);
            return $this->json(['success'=>true,'message'=>'Destination livrée et bon généré.','pdf_url'=>$this->baseUrl().'/deliveries/'.(int)$request->param('id').'/destinations/'.$destinationId.'/pod.pdf'],201);
        } catch (Throwable $exception) {
            return $this->json(['success' => false, 'message' => $exception instanceof RuntimeException ? $exception->getMessage() : 'Impossible d’enregistrer la preuve de livraison.'], 422);
        }
    }

    public function pdf(Request $request): Response
    {
        $deliveryId = (int) $request->param('id');
        if (!DeliveryPod::canAccess($deliveryId)) { return new Response('Accès refusé.', 403, ['Content-Type' => 'text/plain; charset=utf-8']); }
        $pod = DeliveryPod::findByDelivery($deliveryId);
        if (!$pod) { return new Response('Preuve de livraison introuvable.', 404, ['Content-Type' => 'text/plain; charset=utf-8']); }
        $filename = preg_replace('/[^A-Za-z0-9_-]/', '-', 'POD-'.$pod['reference']).'.pdf';
        return new Response(PodPdf::render($pod), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$filename.'"', 'Cache-Control' => 'private, no-store']);
    }

    public function destinationPdf(Request $request): Response
    {
        $deliveryId=(int)$request->param('id');$destinationId=(int)$request->param('destinationId');
        if(!DeliveryPod::canAccess($deliveryId)){return new Response('Accès refusé.',403,['Content-Type'=>'text/plain; charset=utf-8']);}
        $pod=DeliveryPod::findByDestination($deliveryId,$destinationId);if(!$pod){return new Response('Bon de livraison introuvable.',404,['Content-Type'=>'text/plain; charset=utf-8']);}
        $filename=preg_replace('/[^A-Za-z0-9_-]/','-','BL-'.$pod['reference'].'-'.str_pad((string)$pod['stop_order'],2,'0',STR_PAD_LEFT)).'.pdf';
        return new Response(PodPdf::render($pod),200,['Content-Type'=>'application/pdf','Content-Disposition'=>'inline; filename="'.$filename.'"','Cache-Control'=>'private, no-store']);
    }

    private function baseUrl(): string { return rtrim((string) \App\Core\Env::get('APP_URL', ''), '/'); }
}
