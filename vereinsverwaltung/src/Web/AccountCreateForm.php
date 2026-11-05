<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
use Kulturhus\Verein\Finance;

/** Preserve the declared account mode before mapping the three real creation paths. */
final class AccountCreateForm
{
 public const FIELDS=['kind','name','opening','opened_on'];
 public const MODES=['cash','offline-manual','offline-online'];
 public static function kind(string$value):string{return$value==='offline'?'offline-manual':$value;}
 public static function unsupported(array$body):array{
  $out=[];foreach(self::FIELDS as$field)if(array_key_exists($field,$body)&&(!is_string($body[$field])||!mb_check_encoding($body[$field],'UTF-8')||preg_match('/[\x00-\x1f\x7f]/',$body[$field])))$out[$field]=$body[$field];return$out;
 }
 public static function assertInput(Finance$finance,array$body,array$query):void{
  if(!$finance->canWrite())throw new DomainException('Keine ausreichende Finanzberechtigung.',403);
  if(self::unsupported($body)||array_diff(self::FIELDS,array_keys($body)))throw new DomainException('Ursprüngliche Kontoanlage vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
  if(($query['view']??null)!=='finance')throw new DomainException('Kontoanlage im ursprünglichen Bankbereich neu öffnen.',409);
  if(!in_array(self::kind($body['kind']),self::MODES,true))throw new DomainException('Ursprüngliche Kontoart ausdrücklich auswählen. Eine unbekannte Auswahl wird nicht als Offlinekonto angelegt.',422);
 }
 public static function read(array$body,array$query,?\DateTimeImmutable$clock=null):array{
  $posted=($body['action']??null)==='create-account';$original=$posted?$body:array_intersect_key($query,['kind'=>true,'fints'=>true]);$unsupported=$posted?self::unsupported($body):self::unsupported(array_intersect_key($query,['kind'=>true]));
  $values=['kind'=>(($query['fints']??null)==='1'?'offline-online':'offline-manual'),'name'=>'','opening'=>'0,00','opened_on'=>Finance::today($clock)];
  if($posted)foreach(self::FIELDS as$field){if(!array_key_exists($field,$body))$unsupported[$field]=['missing'=>true];$values[$field]=is_string($body[$field]??null)?$body[$field]:'';}
  elseif(array_key_exists('kind',$query))$values['kind']=is_string($query['kind'])?$query['kind']:'';
  $values['kind']=self::kind($values['kind']);return compact('posted','original','unsupported','values');
 }
}
