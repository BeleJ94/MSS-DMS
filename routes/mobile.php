<?php
// Loaded from routes/web.php. No change to existing web routes.
use App\Controllers\MobileDriverController;
use App\Controllers\DriverAppController;
use App\Controllers\PodController;
use App\Controllers\IncidentController;
use App\Middleware\MobileAuthenticate;
use App\Middleware\VerifyCsrf;

$app->router()->get('/api/mobile/bootstrap',[MobileDriverController::class,'bootstrap']);
$app->router()->post('/api/mobile/login',[MobileDriverController::class,'login'],[VerifyCsrf::class]);
$app->router()->get('/api/mobile/me',[MobileDriverController::class,'me'],[MobileAuthenticate::class]);
$app->router()->post('/api/mobile/logout',[MobileDriverController::class,'logout'],[MobileAuthenticate::class,VerifyCsrf::class]);
$app->router()->get('/api/mobile/missions',[MobileDriverController::class,'missions'],[MobileAuthenticate::class]);
$app->router()->get('/api/mobile/missions/{id}',[MobileDriverController::class,'show'],[MobileAuthenticate::class]);
$app->router()->post('/api/mobile/missions/{id}/action',[MobileDriverController::class,'action'],[MobileAuthenticate::class,VerifyCsrf::class]);
$app->router()->post('/api/mobile/missions/{id}/positions',[DriverAppController::class,'positions'],[MobileAuthenticate::class,VerifyCsrf::class]);
$app->router()->post('/api/mobile/missions/{id}/pod',[PodController::class,'store'],[MobileAuthenticate::class,VerifyCsrf::class]);
$app->router()->post('/api/mobile/missions/{id}/incident',[IncidentController::class,'report'],[MobileAuthenticate::class,VerifyCsrf::class]);

$app->router()->post('/api/mobile/missions/{id}/start',[MobileDriverController::class,'start'],[MobileAuthenticate::class,VerifyCsrf::class]);
$app->router()->post('/api/mobile/missions/{id}/deliver',[MobileDriverController::class,'deliver'],[MobileAuthenticate::class,VerifyCsrf::class]);

$app->router()->post('/api/mobile/resume',[MobileDriverController::class,'resume'],[VerifyCsrf::class]);
