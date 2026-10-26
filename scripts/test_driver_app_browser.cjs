// Chrome must run locally with --remote-debugging-port=9229 in an isolated audit profile.
// Mission writes are intercepted. Only DEMO login/logout reaches the live application.
const fs=require('fs');
const path=require('path');
const assert=require('assert');
const base='http://127.0.0.1/MSS-DMS/public';
const manifest=JSON.parse(fs.readFileSync(path.join(__dirname,'../storage/cache/demo-2026-manifest.json'),'utf8'));
const views=JSON.parse(fs.readFileSync(path.join(__dirname,'../storage/cache/driver-audit-views.json'),'utf8'));
const pause=ms=>new Promise(resolve=>setTimeout(resolve,ms));

(async()=>{
 const page=await(await fetch('http://127.0.0.1:9229/json/new?about:blank',{method:'PUT'})).json();
 const ws=new WebSocket(page.webSocketDebuggerUrl);await new Promise((resolve,reject)=>{ws.onopen=resolve;ws.onerror=reject;});
 let sequence=0,checks=0;const pending=new Map(),errors=[],writes=[];let failure='refused';
 const call=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const id=++sequence;pending.set(id,{resolve,reject});ws.send(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})}));});
 ws.onmessage=event=>{
  const data=JSON.parse(event.data);
  if(data.id&&pending.has(data.id)){const task=pending.get(data.id);pending.delete(data.id);data.error?task.reject(new Error(JSON.stringify(data.error))):task.resolve(data.result);}
  if(data.method==='Runtime.exceptionThrown')errors.push(data.params.exceptionDetails.exception?.description||data.params.exceptionDetails.text);
  if(data.method==='Fetch.requestPaused'){
   const {request,requestId}=data.params;
   if(request.method!=='POST'){call('Fetch.continueRequest',{requestId}).catch(()=>{});return;}
   writes.push({url:request.url,body:request.postData||''});
   let result={success:false,message:'Refus simulé : vérifiez les informations du formulaire.'},status=422;
   if(request.url.endsWith('/positions')){
    const positions=JSON.parse(request.postData).positions;result={success:true,data:{accepted:positions.length,duplicates:0,total_positions:positions.length,recorded_ids:positions.map(p=>p.position_id),duplicate_ids:[],persisted_ids:positions.map(p=>p.position_id)}};status=200;
   }else if(failure==='success'){
    result={success:true,message:'Opération de navigateur simulée.',delivery_status:'En transit',pdf_url:base+'/deliveries/1/pod.pdf'};status=request.url.endsWith('/pod')||request.url.endsWith('/incident')?201:200;
   }
   call('Fetch.fulfillRequest',{requestId,responseCode:status,responseHeaders:[{name:'Content-Type',value:'application/json'}],body:Buffer.from(JSON.stringify(result)).toString('base64')}).catch(()=>{});
  }
 };
 const evaluate=async(expression)=>{const result=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(result.exceptionDetails)throw new Error(JSON.stringify(result.exceptionDetails));return result.result.value;};
 const wait=async(expression)=>{for(let n=0;n<80;n++){if(await evaluate(expression))return;await pause(150);}throw new Error('Timeout: '+expression);};
 const check=(condition,label)=>{assert(condition,label);checks++;console.log('OK - '+label);};
 const navigate=async(url)=>{await call('Page.navigate',{url});await wait('document.readyState==="complete"');};
 const click=async(selector)=>{await evaluate('document.querySelector('+JSON.stringify(selector)+').click()');};
 const popup=async(title)=>wait('!!document.querySelector(".swal2-title") && document.querySelector(".swal2-title").textContent.includes('+JSON.stringify(title)+')');
 async function login(email){
  if(await evaluate('!!window.MSS_DRIVER_APP')){
   await evaluate('fetch('+JSON.stringify(base+'/logout')+',{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:new URLSearchParams({_token:window.MSS_DRIVER_APP.csrf})}).then(r=>r.text()).then(()=>true)');
  }
  await navigate(base+'/login');await wait('!!document.querySelector("input[name=email]")');
  await evaluate('document.querySelector("input[name=email]").value='+JSON.stringify(email)+';document.querySelector("input[name=password]").value='+JSON.stringify(manifest.password)+';document.querySelector("input[name=email]").form.submit();true');
  try{await wait('location.pathname.endsWith("/driver-app") && !!window.MSSGps && !!window.jQuery');}catch(error){console.log(await evaluate('JSON.stringify({url:location.href,body:document.body.innerText.slice(0,700),gps:!!window.MSSGps,jquery:!!window.jQuery})'));throw error;}await pause(400);
 }
 try{
  await call('Runtime.enable');await call('Page.enable');
  await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
  await call('Browser.grantPermissions',{origin:'http://127.0.0.1',permissions:['geolocation']});
  await call('Emulation.setGeolocationOverride',{latitude:-11.6647,longitude:27.4794,accuracy:8});
  await call('Fetch.enable',{patterns:[{urlPattern:'*/api/driver-app/missions/*',requestStage:'Request'}]});
  await login(manifest.driver_login);
  if(!process.argv.includes('--offline-only')){
  check(await evaluate('document.querySelectorAll(".mission-card").length>0'),'la connexion DEMO ouvre la liste sur mobile');
  check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),'la liste ne déborde pas horizontalement sur mobile');
  await click('[data-mission-action="load"]');await popup('chargé');await click('.swal2-cancel');
  check(writes.filter(r=>r.url.endsWith('/action')).length===0,'annuler un chargement ne soumet aucune action');
  let shot=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync('storage/cache/driver-app-mobile.png',Buffer.from(shot.data,'base64'));
  const done=views.find(row=>row.status==='Clôturée');await login(done.email);await navigate(base+'/driver-app/missions/'+done.id);await wait('!!window.MSSGps && !!document.querySelector(".pod-complete-card")');
  check(await evaluate('document.body.textContent.includes("DERNIÈRE DESTINATION")'),'une mission clôturée et ses bons sont consultables dans le navigateur');
  check(await evaluate('fetch(document.querySelector(".pod-complete-card a").href).then(async r=>r.status===200 && r.headers.get("content-type").includes("application/pdf") && (await r.text()).startsWith("%PDF-"))'),'le lien du bon renvoie un PDF valide par HTTP');
  const unloading=views.find(row=>row.status==='Déchargement');await login(unloading.email);await navigate(base+'/driver-app/missions/'+unloading.id);await wait('!!window.MSSGps && !!document.querySelector("[data-unloading-form]")');
  check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),'la fiche de déchargement ne déborde pas sur mobile');
  await click('[data-all-conform]');
  check(await evaluate('Array.from(document.querySelectorAll(".unloading-item")).every(row=>row.querySelector("[data-condition=Conforme]").classList.contains("active"))'),'le bouton Tout est conforme sélectionne toutes les marchandises');
  // This representative DEMO stop already has a completed unloading check.
  await wait('!!document.querySelector("[data-pod-open]")');await click('[data-pod-open]');
  check(await evaluate('!document.querySelector("#podModal").hidden'),'le formulaire de preuve s’ouvre');
  await evaluate('document.querySelector("#podForm [name=recipient_name]").value="Réception navigateur DEMO"');
  const doc=await call('DOM.getDocument');const input=await call('DOM.querySelector',{nodeId:doc.root.nodeId,selector:'#podForm [name=delivery_photo]'});
  await call('DOM.setFileInputFiles',{nodeId:input.nodeId,files:[path.resolve('storage/cache/driver-audit-photo.jpg')]});
  await evaluate('window.__auditStop=0;window.__originalGpsStop=window.MSSGps.stop;window.MSSGps.stop=function(){window.__auditStop++;return window.__originalGpsStop();};window.MSSGps.prepare=function(){return Promise.resolve({coords:{latitude:-11.6647,longitude:27.4794,accuracy:8},timestamp:Date.now()});}');
  await evaluate('document.querySelector("#podForm").requestSubmit()');await popup('Confirmer la livraison');await click('.swal2-cancel');
  check(writes.filter(r=>r.url.endsWith('/pod')).length===0,'annuler la preuve n’envoie aucun fichier');
  await evaluate('document.querySelector("#podForm").requestSubmit()');await popup('Confirmer la livraison');await click('.swal2-confirm');await popup('Livraison non enregistrée');
  check(await evaluate('!document.querySelector(".pod-submit").disabled && !jQuery("#podForm").data("busy") && document.querySelector("#podForm [name=recipient_name]").value.includes("DEMO")'),'un refus serveur conserve la preuve et permet de corriger puis réessayer');await click('.swal2-confirm');
  await evaluate('window.MSSGps.prepare=function(){return Promise.reject(Object.assign(new Error("Autorisation GPS refusée pour ce test"),{gpsCode:"denied"}));}');
  await evaluate('document.querySelector("#podForm").requestSubmit()');await popup('Confirmer la livraison');await click('.swal2-confirm');await popup('GPS requis');
  check(await evaluate('!document.querySelector(".pod-submit").disabled'),'un refus de localisation explique le problème et réactive la validation');await click('.swal2-confirm');
  failure='success';await evaluate('window.MSSGps.prepare=function(){return Promise.resolve({coords:{latitude:-11.6647,longitude:27.4794,accuracy:8},timestamp:Date.now()});}');
  await evaluate('document.querySelector("#podForm").requestSubmit()');await popup('Confirmer la livraison');await click('.swal2-confirm');await popup('Livraison effectuée');
  check(await evaluate('window.__auditStop===0'),'une livraison intermédiaire ne coupe pas le GPS de la tournée');
  const transit=views.find(row=>row.status==='En transit');await login(transit.email);await navigate(base+'/driver-app/missions/'+transit.id);await wait('!!window.MSSGps && !!document.querySelector("[data-incident-open]")');
  await click('[data-incident-open]');check(await evaluate('!document.querySelector("#driverIncidentModal").hidden'),'le formulaire d’incident s’ouvre');
  await evaluate('document.querySelector("#driverIncidentForm [name=incident_type]").value="panne";document.querySelector("#driverIncidentForm [name=description]").value="Panne fictive pour la vérification du navigateur.";window.MSSGps.prepare=function(){return Promise.resolve({coords:{latitude:-11.66,longitude:27.48,accuracy:10},timestamp:Date.now()});}');
  failure='refused';await evaluate('document.querySelector("#driverIncidentForm").requestSubmit()');await popup('Envoyer cette alerte');await click('.swal2-confirm');await popup('Alerte non envoyée');
  check(await evaluate('!document.querySelector(".incident-submit").disabled && !jQuery("#driverIncidentForm").data("busy")'),'un échec d’envoi d’incident laisse le formulaire réutilisable');await click('.swal2-confirm');
  failure='success';await evaluate('document.querySelector("#driverIncidentForm").requestSubmit()');await popup('Envoyer cette alerte');await click('.swal2-confirm');await popup('Incident signalé');
  check(writes.some(r=>r.url.endsWith('/incident')),'l’alerte transmet le formulaire et la position à la bonne API');
  }
  await navigate(base+'/driver-app');await wait('!!window.MSSGps');
  await wait('!!navigator.serviceWorker.controller');
  // Chrome applies network emulation per target: the service worker must be offline too.
  const targets=await call('Target.getTargets');
  const worker=targets.targetInfos.find(target=>target.type==='service_worker'&&target.url.includes('/MSS-DMS/public/service-worker.js'));
  let workerSession;
  if(worker){workerSession=(await call('Target.attachToTarget',{targetId:worker.targetId,flatten:true})).sessionId;await call('Network.enable',{},workerSession);await call('Network.emulateNetworkConditions',{offline:true,latency:0,downloadThroughput:0,uploadThroughput:0},workerSession);}
  await call('Network.enable');await call('Network.emulateNetworkConditions',{offline:true,latency:0,downloadThroughput:0,uploadThroughput:0});
  await call('Page.reload',{ignoreCache:false});
  try{await wait('document.body.textContent.includes("Vous êtes hors ligne")');}catch(error){console.log(await evaluate('JSON.stringify({url:location.href,body:document.body.innerText.slice(0,500)})'));throw error;}
  check(await evaluate('document.body.textContent.includes("Reconnectez-vous")'),'la vue hors ligne explique comment reprendre les missions');
  await call('Network.emulateNetworkConditions',{offline:false,latency:0,downloadThroughput:-1,uploadThroughput:-1});
  if(workerSession)await call('Network.emulateNetworkConditions',{offline:false,latency:0,downloadThroughput:-1,uploadThroughput:-1},workerSession);
  check(errors.length===0,'aucune exception JavaScript non traitée dans les vues vérifiées');
  console.log('DRIVER_APP_BROWSER_OK: '+checks+' checks; GPS/camera simulated; mission writes intercepted');
 }finally{await call('Browser.close');ws.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
