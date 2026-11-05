<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Web\{BootstrapAssets,PublicAssets,Html,Manager,WebsiteWorkspace};
use Kulturhus\Native\Web\Settings\{Workspace,EventWorkflowPanel};
use Kulturhus\Native\Web\Website\FacebookLinkPanel;
use Kulturhus\Native\Cms\Pages;
if(PHP_SAPI!=='cli-server'||getenv('KH_NATIVE_CI')!=='1'||(TestSupport::config()['label']??'')!=='native-facebook-events'||(TestSupport::config()['owner']??'')!=='isolated-native-ci')throw new RuntimeException('Own isolated event browser required.');
$fixture=json_decode(file_get_contents(getenv('DVM_EVENT_WORKFLOW_DATA')),true,32,JSON_THROW_ON_ERROR);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$assets=PublicAssets::sources();
if(isset($assets[$path])){header('Content-Type: '.PublicAssets::mime($assets[$path]));readfile($assets[$path]);return;}
header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'");
$db=TestSupport::db();$access=new Access($db,'admin');$section=$_GET['section']??'settings';if(!in_array($section,['settings','events','link'],true)){$section='settings';}
$error='';$notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(($_POST['operation']??'')==='event-workflow')(new Workspace($db,$access,$fixture['media'],$fixture['csrf']))->action($_POST);
        else (new Manager($db,$access,$fixture['media'],$fixture['csrf'],websiteWorkspace:true,facebookRequest:static fn()=>['status'=>200,'body'=>['data'=>[$fixture['raw']]]]))->action('events',$_POST,[]);
        header('Location: /?section='.rawurlencode($section).'&saved=1',true,303);return;
    }catch(DomainException|InvalidArgumentException $e){$error=$e->getMessage();}
}
$body=match($section){
    'settings'=>EventWorkflowPanel::render($db,$access,$fixture['csrf']),
    'events'=>(new WebsiteWorkspace($db,$access,$fixture['csrf']))->events(),
    'link'=>(new FacebookLinkPanel($db,$access,$fixture['media'],$fixture['csrf']))->render((new Pages($db,$access))->get($fixture['id']))
};
echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'.BootstrapAssets::styles().'<link rel="stylesheet" href="/assets/native.css"><title>Veranstaltungsablauf</title></head><body><main class="content" id="content">'.($error!==''?'<p role="alert">'.Html::e($error).'</p>':'').(isset($_GET['saved'])?'<p role="status">Gespeichert</p>':'').$body.'</main></body></html>';
