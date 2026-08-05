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
            DeliveryPod::createOwned((int) $request->param('id'), $request->all(), $signature, $photo, $signedNote);
            return $this->json(['success' => true, 'message' => 'Livraison confirmée et preuve enregistrée.', 'pdf_url' => $this->baseUrl().'/deliveries/'.(int) $request->param('id').'/pod.pdf'], 201);
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

    private function baseUrl(): string { return rtrim((string) \App\Core\Env::get('APP_URL', ''), '/'); }
}
