<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Kulturhus\Verein\Minutes;

/** Read models and rejected own drafts never acquire a current version or replacement nonce. */
final class MinutesPageState
{
 public static function read(Minutes $service,array $query,#[\SensitiveParameter] array $post,array $files,int $status):array
 {
  if(!$service->canRead())throw new DomainException('Keine Berechtigung für Protokolle.',403);
  $write=$service->canWrite();$source=MinutesForm::query($query);$state=['query'=>$source,'write'=>$write,'minute'=>null,'lookup'=>false,'editing'=>false,'values'=>null,'blocked'=>false,'posted'=>$post!==[],'transition'=>null,'upload_needed'=>false];
  if($post!==[]){
   if(!$write)throw new DomainException('Keine ausreichende Berechtigung für Protokolle.',403);
   $command=MinutesForm::command($query,$post);$state['blocked']=in_array($status,[403,404,409,500,503],true);
   if(($post['action']??null)==='minutes-transition')return$state+['own_transition'=>$command];
   $state['editing']=true;
   $html=$post['body_mode']==='html'?$post['body_html']:MinutesForm::fromPlain($post['body_text'],$post['body_html']);
   $state['values']=['id'=>$command['id'],'version'=>$post['version'],'request_key'=>$post['request_key'],'title'=>$post['title'],'meeting_on'=>$post['meeting_on'],'source_kind'=>$post['source_kind'],'body_html'=>$html,'body_text'=>$post['body_mode']==='plain'?$post['body_text']:MinutesForm::plain($html),'pdf_existing'=>$command['pdf_existing']];
   $file=$files['minute_pdf']??null;
   $state['upload_needed']=$command['upload_required']||(is_array($file)&&($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE);
   return$state;
  }
  if(isset($source['created_request'])){
   if(!$write)throw new DomainException('Keine ausreichende Berechtigung für Protokolle.',403);
   $state['lookup']=true;$state['minute']=$service->creationResult($source['created_request']);return$state;
  }
  $id=$source['mid'];$minute=$id!==''?$service->entry($id):null;$state['minute']=$minute;
  if(isset($source['compose'])||isset($source['new'])){
   if(!$write){if($minute!==null)return$state;throw new DomainException('Keine ausreichende Berechtigung für Protokolle.',403);}
   if($minute!==null&&$minute['state']!=='draft')throw new DomainException('Protokoll vor dem Bearbeiten in den Entwurf zurücknehmen.',409);
   $state['editing']=true;
   $state['values']=['id'=>$id,'version'=>(string)($minute['version']??0),'request_key'=>bin2hex(random_bytes(16)),'title'=>$minute['title']??'','meeting_on'=>$minute['meeting_on']??(new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin')))->format('Y-m-d'),'source_kind'=>$minute['source_kind']??'editor','body_html'=>$minute['body_html']??'','body_text'=>MinutesForm::plain($minute['body_html']??''),'pdf_existing'=>($minute['source_kind']??null)==='pdf'];
   return$state;
  }
  if($minute!==null)return$state;
  if(!$write&&!in_array($source['state'],['published','all'],true))throw new DomainException('Diese Protokollauswahl benötigt Bearbeitungsrechte.',403);
  $rows=$service->listing(['state'=>$source['state'],'q'=>$source['q'],'year'=>$source['year']]);$pages=max(1,(int)ceil(count($rows)/12));$page=(int)$source['page'];
  if($page>$pages)throw new DomainException('Die ursprüngliche Protokollseite ist nicht mehr verfügbar. Eine neue Seite bewusst wählen.',422);
  return$state+['rows'=>array_slice($rows,($page-1)*12,12),'total'=>count($rows),'page'=>$page,'pages'=>$pages];
 }
 public static function originals(array $query,#[\SensitiveParameter] array $post,array $files):string
 {
  unset($post['live_uploads']);
  $metadata=[];foreach($files as$key=>$file){if(!is_array($file)){$metadata[$key]=$file;continue;}$metadata[$key]=array_intersect_key($file,array_flip(['name','size','error','type']));}
  return OriginalInput::json($query,$post+($metadata!==[]?['selected_files'=>$metadata]:[]));
 }
 public static function status(DomainException $error):int
 {
  if(in_array($error->getCode(),[403,404,409,422,503],true))return$error->getCode();
  if(in_array($error->getMessage(),['Keine Berechtigung für Protokolle.','Keine ausreichende Berechtigung für Protokolle.','Protokoll nicht verfügbar.','Protokolldatei nicht verfügbar.'],true))return 403;
  return in_array($error->getMessage(),['Protokoll wurde inzwischen geändert. Bitte neu laden.','Vorgangskennung mit anderen Eingaben bereits verwendet.','Protokoll vor dem Bearbeiten in den Entwurf zurücknehmen.'],true)?409:422;
 }
}
