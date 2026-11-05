<?php declare(strict_types=1);
/** Standalone loopback-only preview. Never load this router on a public server. */
ini_set('display_errors','0');
$workflowBufferLevel=ob_get_level();ob_start();
$discardWorkflowOutput=static function()use($workflowBufferLevel):void{while(ob_get_level()>$workflowBufferLevel)ob_end_clean();};
try{
$host=strtolower((string)($_SERVER['HTTP_HOST']??''));
if(PHP_SAPI!=='cli-server'||getenv('KULTURHUS_WORKFLOW_DEMO')!=='1'||!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)||!preg_match('/^(127\.0\.0\.1|localhost):[0-9]+$/D',$host)){
    http_response_code(403);exit('Nur für eine ausdrücklich gestartete lokale Workflow-Demo.');
}
$path=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
$public=dirname(__DIR__,2).'/public/';
$assets=['/document-workflows.css'=>['document-workflows.css','text/css'], '/document-workflows.js'=>['document-workflows.js','text/javascript'], '/brand.css'=>['brand.css','text/css']];
if(isset($assets[$path])){$asset=@file_get_contents($public.$assets[$path][0]);if($asset===false)throw new RuntimeException('Lokale Workflow-Datei nicht verfügbar.');header('Content-Type: '.$assets[$path][1].'; charset=utf-8');header('X-Content-Type-Options: nosniff');$discardWorkflowOutput();echo $asset;return;}
if($path!=='/'&&$path!=='/workflows'){http_response_code(404);exit('Nicht gefunden.');}
if(!in_array($_SERVER['REQUEST_METHOD']??'',['GET','POST'],true)){http_response_code(405);header('Allow: GET, POST');exit;}
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
header('Content-Type: text/html; charset=utf-8');
$directory=dirname(__DIR__,3).'/.local/document-workflows-20261007';
if(!is_file($directory.'/demo.json')){http_response_code(503);exit('Lokale Workflow-Demo zuerst per CLI einrichten.');}
if(!is_dir($directory.'/sessions')&&!@mkdir($directory.'/sessions',0700,true)&&!is_dir($directory.'/sessions'))throw new RuntimeException('Lokales Sitzungsverzeichnis nicht verfügbar.');
ini_set('display_errors','0');ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');
session_name('KH_WORKFLOW_LOCAL');session_save_path($directory.'/sessions');
session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','path'=>'/']);if(!session_start())throw new RuntimeException('Lokale Sitzung nicht verfügbar.');
$_SESSION['csrf']??=bin2hex(random_bytes(32));$_SESSION['actor']??='wf-author';
if(!is_string($_SESSION['csrf'])||!is_string($_SESSION['actor']))throw new RuntimeException('Lokale Sitzung ist ungültig.');
require_once __DIR__.'/document-workflow-fixture.php';
require_once __DIR__.'/document-workflow-demo-access.php';
require_once dirname(__DIR__,2).'/src/DocumentWorkflow/WorkflowStore.php';
require_once dirname(__DIR__,2).'/src/DocumentWorkflow/WorkflowSignatures.php';
require_once dirname(__DIR__,2).'/src/Documents/DocumentWorkflowPage.php';
$registration=DocumentWorkflowFixture::demoRegistration();
$db=DocumentWorkflowFixture::connection($registration['database']);
$access=new DocumentWorkflowDemoAccess((string)$_SESSION['actor']);
$workflowStore=new \Kulturhus\Verein\DocumentWorkflow\WorkflowStore($db,$access);
$workflowSignatures=new \Kulturhus\Verein\DocumentWorkflow\WorkflowSignatures($db,$access);
$workflowBase=['view'=>'documents','tab'=>'workflows'];$workflowCsrf=$_SESSION['csrf'];
$workflowError='';$workflowNotice=(string)($_SESSION['notice']??'');unset($_SESSION['notice']);
$esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
try{
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!is_string($_POST['csrf']??null)||!hash_equals($workflowCsrf,$_POST['csrf'])){http_response_code(403);throw new DomainException('Formular abgelaufen. Bitte neu laden.');}
        if(($_POST['action']??'')==='workflow-demo-actor'){
            $person=$_POST['actor']??'';if(!is_string($person)||!in_array($person,array_column(DocumentWorkflowDemoAccess::PEOPLE,'id'),true))throw new DomainException('Unbekannte Demo-Rolle.');
            if(!session_regenerate_id(true))throw new RuntimeException('Lokale Sitzung konnte nicht erneuert werden.');$_SESSION['actor']=$person;$_SESSION['csrf']=bin2hex(random_bytes(32));
            header('Location: /workflows?'.http_build_query($workflowBase),true,303);exit;
        }
        $route=\Kulturhus\Verein\DocumentWorkflowPage::handle($workflowStore,$_POST,$_FILES,$workflowSignatures);
        $_SESSION['notice']='Workflow gespeichert.';header('Location: /workflows?'.http_build_query(array_merge($workflowBase,$route)),true,303);exit;
    }
    if(isset($_GET['wf_signature_pdf'])){
        if(!is_string($_GET['wf_signature_pdf']))throw new DomainException('Unterschrift auswählen.');
        $file=$workflowSignatures->ownPdf($_GET['wf_signature_pdf']);
        $discardWorkflowOutput();header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.$file['name'].'"');echo $file['bytes'];exit;
    }
    if(isset($_GET['wf_attachment'])){
        $id=$_GET['wf_doc']??null;$attachment=$_GET['wf_attachment'];$revision=$_GET['wf_revision']??null;
        if(!is_string($id)||!is_string($attachment)||($revision!==null&&(!is_string($revision)||!ctype_digit($revision)||(int)$revision<1)))throw new DomainException('Anlagenparameter prüfen.');
        $file=$workflowStore->attachment($id,$attachment,$revision===null?null:(int)$revision);
        $discardWorkflowOutput();header('Content-Type: '.$file['mime']);header('Content-Disposition: attachment; filename="anlage.pdf"; filename*=UTF-8\'\''.rawurlencode($file['name']));echo $file['bytes'];exit;
    }
    if(isset($_GET['wf_export'])){
        $id=$_GET['wf_export'];$format=$_GET['wf_format']??'html';$revision=$_GET['wf_revision']??null;
        if(!is_string($id)||!is_string($format)||($revision!==null&&(!is_string($revision)||!ctype_digit($revision)||(int)$revision<1)))throw new DomainException('Exportparameter prüfen.');
        $file=$workflowStore->export($id,$revision===null?null:(int)$revision,$format);
        $discardWorkflowOutput();header('Content-Type: '.$file['mime']);header('Content-Disposition: attachment; filename="workflow-export"; filename*=UTF-8\'\''.rawurlencode($file['name']));echo $file['bytes'];exit;
    }
}catch(DomainException $e){if(http_response_code()===200)http_response_code(422);$workflowError=$e->getMessage();}
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Kulturhus · Dokumentenworkflows</title><link rel="stylesheet" href="/document-workflows.css"><link rel="stylesheet" href="/brand.css"></head><body data-brand="kultur"><main class="dwf-workspace"><header class="dwf-demo-header"><p><strong>Kulturhus · lokale Workflow-Demo</strong> · ausschließlich synthetische Daten</p><form method="post"><input type="hidden" name="csrf" value="<?= $esc($workflowCsrf) ?>"><input type="hidden" name="action" value="workflow-demo-actor"><label>Demo-Rolle <select name="actor"><?php foreach(DocumentWorkflowDemoAccess::PEOPLE as $person): ?><option value="<?= $esc($person['id']) ?>" <?= $person['id']===$access->id?'selected':'' ?>><?= $esc($person['name']) ?></option><?php endforeach ?></select></label><button type="submit">Rolle wechseln</button></form></header><?php
require dirname(__DIR__,2).'/views/Documents/document-workflows.php';
?></main></body></html><?php
$output=ob_get_clean();echo $output;
}catch(Throwable $error){
    $discardWorkflowOutput();header_remove('Content-Disposition');header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
    http_response_code($error instanceof DomainException?403:500);
    error_log('Workflow preview error: '.get_class($error));
    $message=$error instanceof DomainException?'Diese lokale Workflow-Ansicht ist nicht verfügbar oder nicht erlaubt.':'Die lokale Workflow-Demo ist derzeit nicht verfügbar.';
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Workflow-Demo</title></head><body><p role="alert">'.$message.'</p></body></html>';
}
