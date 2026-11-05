<?php declare(strict_types=1);
namespace Kulturhus\Verein\Documents;
use Kulturhus\Verein\DocumentChecks;

/** Whole native intention only; business validation and current rights stay in DocumentChecks. */
final class DocumentCheckForm
{
 public static function read(array $post):array
 {
  $text=static function(string $key)use($post):string{if(!array_key_exists($key,$post)||!is_string($post[$key])||preg_match('//u',$post[$key])!==1)throw new \DomainException('Nachweisformular nicht vollständig übertragen. Eingaben prüfen.');return $post[$key];};
  if($text('check_complete')!=='1')throw new \DomainException('Nachweisformular nicht vollständig übertragen. Eingaben prüfen.');
  $id=$text('id');$version=$text('version');$key=$text('request_key');$actor=$text('check_actor');$club=$text('check_club');
  foreach([$actor,$club]as$identity)if(!preg_match('/\A[A-Za-z0-9_.:@-]{1,128}\z/D',$identity))throw new \DomainException('Geprüften Nachweis und Formularversion erneut öffnen.');
  if(($id!==''&&!preg_match('/\A[A-Za-z0-9_.:@-]{1,128}\z/D',$id))||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$version)||($parsed=filter_var($version,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>2147483647]]))===false||!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new \DomainException('Geprüften Nachweis und Formularversion erneut öffnen.');
  $data=[];foreach(DocumentChecks::defaults()as$name=>$default)if(!in_array($name,['id','version'],true))$data[$name]=$text($name);
  $browse=array_key_exists('check_pick',$post)?$text('check_pick'):'';
  if($browse!==''&&!preg_match('/\A(?:(?:documents|contacts|events):(?:search|previous|next|page|clear|refresh)|file:clear)\z/D',$browse))throw new \DomainException('Nachweisauswahl erneut prüfen.');
  if(array_key_exists('live_uploads',$post)&&(!is_array($post['live_uploads'])||(isset($post['live_uploads']['check_file'])&&(!is_array($post['live_uploads']['check_file'])||!array_is_list($post['live_uploads']['check_file'])||count($post['live_uploads']['check_file'])>1))))throw new \DomainException('Uploadzuordnung ungültig.');
  $sources=[];$sourceErrors=[];
  foreach(['documents','contacts','events']as$source){
   $query=array_key_exists('check_query_'.$source,$post)?$text('check_query_'.$source):'';$number=array_key_exists('check_page_'.$source,$post)?$text('check_page_'.$source):'1';
   $sourceAction=str_starts_with($browse,$source.':')?substr($browse,strlen($source)+1):'';
   if($sourceAction==='clear'){$data[['documents'=>'document_id','contacts'=>'contact_id','events'=>'event_id'][$source]]='';$query='';$number='1';}
   if(in_array($sourceAction,['search','refresh'],true))$number='1';
   $page=$number;try{$page=DocumentChecks::pageNumber($number);$page=match($sourceAction){'previous'=>max(1,$page-1),'next'=>min(999999999,$page+1),default=>$page};}catch(\DomainException $failure){$sourceErrors[$source]=$failure->getMessage();}
   if(mb_strlen($query,'UTF-8')>150||preg_match('/[\x00-\x1f\x7f]/',$query))$sourceErrors[$source]='Auswahlsuche auf 150 Zeichen ohne Steuerzeichen begrenzen.';
   $sources[$source]=['query'=>$query,'page'=>$page];
  }
  $archive=array_key_exists('check_event_archive',$post)?$text('check_event_archive'):'0';if(!in_array($archive,['0','1'],true))throw new \DomainException('Veranstaltungsauswahl erneut prüfen.');
  return ['id'=>$id,'version'=>$parsed,'request_key'=>$key,'actor'=>$actor,'club'=>$club,'data'=>$data,'browse'=>$browse,'sources'=>$sources,'source_errors'=>$sourceErrors,'event_archive'=>$archive==='1','post'=>$post];
 }
}
