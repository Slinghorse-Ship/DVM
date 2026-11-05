<?php declare(strict_types=1);
namespace Kulturhus\Verein\Documents;
use Kulturhus\Verein\{DocumentChecks,Documents,DocumentLibrary,Registry,LiveUploads};
require_once __DIR__.'/DocumentCheckForm.php';
require_once __DIR__.'/DocumentLibrary.php';
require_once dirname(__DIR__).'/Infrastructure/Media/LiveUploads.php';

/** Prepare one authorized original or one counted page before response headers. */
final class DocumentCheckState
{
 public static function prepare(\PDO $db,DocumentChecks $checks,Documents $documents,Registry $registry,array $query,array $post,string $csrf,string $error='',?array $draft=null):array
 {
  $state=['fatal'=>false,'error'=>$error,'http_status'=>0,'mode'=>'list','editing'=>false,'original'=>null,'original_document'=>null,'original_status'=>null,'form'=>null,'rejected'=>null,'page'=>null,'receipt'=>null,'receipt_page'=>null,'receipt_key'=>'','sources'=>[],'source_errors'=>[],'selected'=>[],'upload'=>null,'upload_token'=>'','conflict'=>false];
  $owned=!$db->inTransaction();if($owned)$db->beginTransaction();
  try{
   $actor=$checks->actor();$ownPost=($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&($post['action']??null)==='document-check-save'&&is_string($post['csrf']??null)&&$csrf!==''&&hash_equals($csrf,$post['csrf']);
   $text=static function(array $input,string $key,string $default=''):string{if(!array_key_exists($key,$input))return $default;if(!is_string($input[$key])||preg_match('//u',$input[$key])!==1)throw new \DomainException('Nachweisauswahl prüfen.');return $input[$key];};
   if($ownPost){
    try{$draft??=DocumentCheckForm::read($post);}catch(\DomainException $failure){
     $state['editing']=true;$state['http_status']=422;$state['error']=$state['error']?:$failure->getMessage();$state['rejected']=[];foreach(DocumentChecks::defaults()as$key=>$default)$state['rejected'][$key]=is_string($post[$key]??null)&&preg_match('//u',$post[$key])===1?$post[$key]:'';
     $candidate=$state['rejected']['id'];if($candidate!==''&&preg_match('/\A[A-Za-z0-9_.:@-]{1,128}\z/D',$candidate))$state['original']=$checks->get($candidate);
    }
    if($draft!==null){if($draft['actor']!==$actor['id']||$draft['club']!==$actor['club_id'])throw new \DomainException('Nachweisformular gehört zu einem anderen Zugang oder Verein.');$state['editing']=true;$state['original']=$draft['id']!==''?$checks->get($draft['id']):DocumentChecks::defaults();$state['form']=$draft;$state['conflict']=(int)$state['original']['version']!==$draft['version'];if($state['conflict']){$state['http_status']=409;$state['error']=$state['error']?:'Nachweis inzwischen geändert. Bitte neu laden.';}}
   }else{
    $id=$text($query,'check');$new=$text($query,'new_check');if($new!==''&&$new!=='1')throw new \DomainException('Nachweisauswahl prüfen.');
    $receipt=$text($query,'check_receipt');$receipts=$text($query,'check_receipts');if($receipts!==''&&$receipts!=='1')throw new \DomainException('Nachweisauswahl prüfen.');
    $state['editing']=$id!==''||$new==='1';
    if($state['editing']){
     $state['original']=$id!==''?$checks->get($id):DocumentChecks::defaults();$data=[];foreach(DocumentChecks::defaults()as$key=>$default)if(!in_array($key,['id','version'],true))$data[$key]=(string)$state['original'][$key];
     $state['form']=['id'=>$id,'version'=>(int)$state['original']['version'],'actor'=>$actor['id'],'club'=>$actor['club_id'],'request_key'=>bin2hex(random_bytes(16)),'data'=>$data,'browse'=>'','sources'=>array_fill_keys(['documents','contacts','events'],['query'=>'','page'=>1]),'source_errors'=>[],'event_archive'=>false,'post'=>[]];
    }elseif($receipt!==''){$state['mode']='receipt';$state['receipt_key']=$receipt;$state['receipt']=$checks->creationReceipt($receipt);if($state['receipt']===null)$state['http_status']=404;}
    elseif($receipts==='1'){$state['mode']='receipts';$state['receipt_page']=$checks->creationPage(array_key_exists('check_receipts_page',$query)?$query['check_receipts_page']:1);}
    else try{$state['page']=$checks->page($query);}catch(\DomainException $failure){$state['error']=$state['error']?:$failure->getMessage();$state['http_status']=422;}
   }
   if($state['form']!==null){
    $state['source_errors']=$state['form']['source_errors']??[];if($state['source_errors']&&$state['http_status']===0)$state['http_status']=422;
    $tokens=$post['live_uploads']['check_file']??[];if(!is_array($tokens)||!array_is_list($tokens)||count($tokens)>1)throw new \DomainException('Uploadzuordnung ungültig.');
    if($tokens){if(!is_string($tokens[0]))throw new \DomainException('Uploadzuordnung ungültig.');$state['upload_token']=$tokens[0];try{$state['upload']=LiveUploads::description($actor['id'],$tokens[0],'document-check-save','check_file');}catch(\DomainException $failure){$state['source_errors']['file']=$failure->getMessage();if($state['http_status']===0)$state['http_status']=422;}}
    if(!$state['conflict']){
     $read=static function(string $source,callable $read)use(&$state):void{if(isset($state['source_errors'][$source]))return;try{$state['sources'][$source]=$read();}catch(\DomainException|\InvalidArgumentException $failure){$state['source_errors'][$source]=$failure->getMessage();if($state['http_status']===0)$state['http_status']=422;}};
     $form=$state['form'];$data=$form['data'];
     $read('documents',static fn():array=>(new DocumentLibrary($documents))->page(['q'=>$form['sources']['documents']['query'],'state'=>'active'],$form['sources']['documents']['page'],6));
     if($data['document_id']!=='')try{$state['selected']['documents']=$documents->document($data['document_id']);}catch(\DomainException){$state['selected']['documents_unavailable']=true;}
     $read('contacts',static function()use($registry,$form,$data,&$state):array{$input=['contact_query'=>$form['sources']['contacts']['query'],'contact_page'=>$form['sources']['contacts']['page'],'contact_id'=>$data['contact_id']];try{$page=$registry->contactChoicePage($input);}catch(\DomainException $failure){if($data['contact_id']===''||$failure->getMessage()!=='Gewählter Kontakt ist nicht mehr verfügbar.')throw $failure;$state['selected']['contacts_unavailable']=true;$page=$registry->contactChoicePage(array_replace($input,['contact_id'=>'']));}$state['selected']['contacts']=$page['selected'];return $page;});
     $read('events',static fn():array=>$registry->appointmentChoicePage(['query'=>$form['sources']['events']['query'],'page'=>$form['sources']['events']['page'],'archive'=>$form['event_archive']]));
     if($data['event_id']!=='')try{$state['selected']['events']=$registry->appointment($data['event_id']);}catch(\DomainException){$state['selected']['events_unavailable']=true;}
    }
   }
   if($state['original']!==null){$original=$state['original'];if(($original['document_id']??'')!=='')try{$state['original_document']=$documents->document($original['document_id']);}catch(\DomainException){}$state['original_status']=DocumentChecks::status($original,(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Berlin')))->format('Y-m-d'),($state['original_document']['state']??'')==='active');}
   $current=$checks->actor();if($current['id']!==$actor['id']||$current['club_id']!==$actor['club_id'])throw new \DomainException('Nachweisauswahl nach geänderten Zugangsrechten erneut öffnen.');
   if($owned)$db->commit();return $state;
  }catch(\DomainException $failure){if($owned&&$db->inTransaction())$db->rollBack();$state['fatal']=true;$state['page']=null;$state['form']=null;$state['error']=$failure->getMessage();$state['http_status']=in_array($failure->getMessage(),['Nachweisformular gehört zu einem anderen Zugang oder Verein.','Nachweis nicht verfügbar.','Dieser Bereich ist nur für den aktuellen Vorstand und Superuser freigegeben.','Zugang nicht aktiv.'],true)?403:($failure->getCode()===503?503:422);return $state;}
  catch(\Throwable){if($owned&&$db->inTransaction())$db->rollBack();$state['fatal']=true;$state['page']=null;$state['form']=null;$state['error']='Nachweisansicht konnte nicht geladen werden.';$state['http_status']=500;return $state;}
 }
}
