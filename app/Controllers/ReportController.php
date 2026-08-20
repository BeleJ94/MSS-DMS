<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Report;

final class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        if (!Auth::can('reports.view')) {
            return new Response(View::render('errors/403', ['title' => 'Accès refusé']), 403);
        }

        $period = max(7, min(365, (int) $request->query('period', 30)));
        $mode = in_array($request->query('view', 'manager'), ['executive', 'manager'], true) ? $request->query('view', 'manager') : 'manager';
        return $this->view('reports/index', [
            'title' => 'Rapports',
            'page' => 'reports',
            'period' => $period,
            'reportView' => $mode,
            'baseUrl' => rtrim((string) Env::get('APP_URL', ''), '/'),
        ] + Report::operational($period));
    }

    public function details(Request $request): Response
    {
        if(!Auth::can('reports.view')){return Response::json(['success'=>false,'message'=>'Permission insuffisante.'],403);}
        try{return Response::json(['success'=>true,'data'=>Report::details((string)$request->query('type','planned'),max(7,min(365,(int)$request->query('period',30))),(string)$request->query('value',''))]);}
        catch(\InvalidArgumentException $e){return Response::json(['success'=>false,'message'=>$e->getMessage()],422);}
    }

    public function export(Request $request): Response
    {
        if(!Auth::can('reports.view')){return new Response(View::render('errors/403',['title'=>'Accès refusé']),403);}
        try{$detail=Report::details((string)$request->query('type','planned'),max(7,min(365,(int)$request->query('period',30))),(string)$request->query('value',''));}
        catch(\InvalidArgumentException $e){return new Response($e->getMessage(),422);}
        $escape=function($value):string{$value=(string)$value;if(preg_match('/^[=+\-@]/',$value)){$value="'".$value;}return htmlspecialchars($value,ENT_QUOTES,'UTF-8');};
        $html='<html><head><meta charset="UTF-8"></head><body><table border="1"><caption>'.$escape($detail['title']).'</caption><thead><tr>';
        foreach($detail['columns'] as $column){$html.='<th>'.$escape($column['label']).'</th>';}$html.='</tr></thead><tbody>';
        foreach($detail['rows'] as $row){$html.='<tr>';foreach($detail['columns'] as $column){$html.='<td>'.$escape($row[$column['key']]??'').'</td>';}$html.='</tr>';}$html.='</tbody></table></body></html>';
        return new Response($html,200,['Content-Type'=>'application/vnd.ms-excel; charset=utf-8','Content-Disposition'=>'attachment; filename="rapport-'.$detail['slug'].'-'.date('Ymd-His').'.xls"','Cache-Control'=>'private, no-store']);
    }
}
