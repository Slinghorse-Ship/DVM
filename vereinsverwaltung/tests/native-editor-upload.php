<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\Schema;
use Kulturhus\Native\Communication\Workspace;
use Kulturhus\Native\Web\Communication\{Actions,Compose};
$db=TestSupport::db();TestSupport::seed($db);Schema::install($db);\Kulturhus\Native\Communication\Schema::install($db);
$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo 'PASS '.$label."\n";}
function rejected(callable $fn,string $message,string $label):void{try{$fn();}catch(DomainException|InvalidArgumentException $error){check(str_contains($error->getMessage(),$message),$label);return;}throw new RuntimeException($label);}
$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/editor-upload';mkdir($root,0700,true);
$admin=new Access($db,'admin');$drafts=[];
foreach(['newsletter','press','social']as$module)$drafts[$module]=(new Workspace($db,$admin,$module))->save(['title'=>'Eigener '.$module.' Entwurf','body'=>'Eigener ursprünglicher Text']);
foreach(['newsletter','press','social']as$module){
    $id=$drafts[$module]['id'];$post=['operation'=>'communication-photo-upload','id'=>$id,'csrf'=>'own-token'];$actions=new Actions($db,$admin,$root.'/media','own-token');
    rejected(fn()=>$actions->action($module,array_replace($post,['csrf'=>'wrong']),[]),'Formular abgelaufen',$module.' upload retains CSRF before inspecting a file');
    rejected(fn()=>(new Actions($db,new Access($db,'reader'),$root.'/media','own-token'))->action($module,$post,[]),'Berechtigung',$module.' reader has no upload authority');
    rejected(fn()=>(new Actions($db,new Access($db,'outsider'),$root.'/media','own-token'))->action($module,$post,[]),'nicht verfügbar',$module.' another club cannot upload against this draft');
    rejected(fn()=>$actions->action($module,array_replace($post,['id'=>$drafts[$module==='social'?'press':'social']['id']]),[]),'nicht verfügbar',$module.' cannot use a different module draft');
    foreach([[],['error'=>UPLOAD_ERR_INI_SIZE],['error'=>UPLOAD_ERR_OK,'tmp_name'=>__FILE__,'name'=>'own.jpg'],['error'=>UPLOAD_ERR_OK,'tmp_name'=>[],'name'=>'own.jpg'],['error'=>UPLOAD_ERR_OK,'tmp_name'=>__FILE__,'name'=>[]]]as$file)rejected(fn()=>$actions->action($module,$post,['photo'=>$file]),'Fotoupload fehlgeschlagen',$module.' rejects missing, failed, local or malformed multipart source');
    $html=(new Compose($db,$admin,$root.'/media','own-token'))->manager($module,$id);
    check(str_contains($html,'multiple data-editor-upload')&&!str_contains($html,'name="photo" required'),'actual '.$module.' editor offers direct single/multiple upload without required metadata');
    check(!str_contains((new Compose($db,new Access($db,'reader'),$root.'/media','own-token'))->manager($module,$id),'data-editor-upload'),'actual '.$module.' reader never receives a writable photo control');
    Db::run($db,"DELETE FROM native_grants WHERE actor_id='editor' AND module='website' AND capability='edit'");
    rejected(fn()=>(new Actions($db,new Access($db,'editor'),$root.'/media','own-token'))->action($module,$post,[]),'Berechtigung',$module.' current media upload grant is checked before a file');
    check(!str_contains((new Compose($db,new Access($db,'editor'),$root.'/media','own-token'))->manager($module,$id),'data-editor-upload'),'actual '.$module.' cannot offer upload after media authority is revoked');
    Db::run($db,"INSERT INTO native_grants(actor_id,module,capability) VALUES('editor','website','edit')");
    check((new Workspace($db,$admin,$module))->get($id)===$drafts[$module],$module.' rejected requests preserve its exact original draft');
}
check((int)$db->query('SELECT COUNT(*) FROM khcms_media')->fetchColumn()===0,'all rejected requests leave media storage empty');
echo $checks." editor upload authority and original-preservation checks passed; synthetic MySQL, no transport.\n";
