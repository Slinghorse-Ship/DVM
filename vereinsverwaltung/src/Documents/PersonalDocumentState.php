<?php declare(strict_types=1);
namespace Kulturhus\Verein\Documents;
use Kulturhus\Verein\{PortalDocuments,WebsiteCalendar};
final class PersonalDocumentState
{
 public const FIELDS=['invoices'=>['personal_invoice_query','personal_invoice_page'],'shared'=>['personal_shared_query','personal_shared_page'],'vouchers'=>['personal_voucher_query','personal_voucher_page']];
 public static function prepare(PortalDocuments $service,array $query,?WebsiteCalendar $website=null):array
 {
  $state=['actor'=>null,'pages'=>array_fill_keys(array_keys(self::FIELDS),null),'errors'=>[],'filters'=>[],'query'=>[],'profile_error'=>'','http_status'=>0];
  $inputs=[];
  foreach(self::FIELDS as$section=>[$search,$page]){
   $inputs[$section]=['query'=>array_key_exists($search,$query)?$query[$search]:'','page'=>array_key_exists($page,$query)?$query[$page]:1];
   $state['filters'][$section]=['query'=>is_string($inputs[$section]['query'])&&preg_match('//u',$inputs[$section]['query'])===1?$inputs[$section]['query']:'','page'=>is_scalar($inputs[$section]['page'])?(string)$inputs[$section]['page']:''];
   foreach([$search,$page]as$field)if(array_key_exists($field,$query)&&is_string($query[$field])&&preg_match('//u',$query[$field])===1)$state['query'][$field]=$query[$field];
  }
  if(is_string($query['wf_signed_files_page']??null))$state['query']['wf_signed_files_page']=$query['wf_signed_files_page'];
  try{
   $prepared=$service->personalPages($inputs,$website);$state['actor']=$prepared['actor'];$state['pages']=$prepared['pages'];$state['errors']=$prepared['errors'];
   foreach($prepared['errors']as$error)$state['http_status']=max($state['http_status'],$error['code']);
  }catch(\DomainException $failure){
   $state['profile_error']=$failure->getMessage();
   // Actor-bound signed originals remain available independently of a member-profile binding.
   if(!in_array($failure->getMessage(),['Diesem Zugang ist kein eigenes Mitgliederprofil zugeordnet.','Mitgliederprofil nicht verfügbar.'],true))$state['http_status']=$failure->getCode()===503?503:403;
  }catch(\Throwable){$state['profile_error']='Persönliche Belege konnten nicht geladen werden.';$state['http_status']=500;}
  return $state;
 }
}
