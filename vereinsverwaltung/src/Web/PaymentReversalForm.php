<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
use Kulturhus\Verein\Finance;

/** Validate the submitted mode before scalar helpers can reinterpret an existing counter. */
final class PaymentReversalForm
{
 public const FIELDS=['id','version','reverse_original','counter_id','counter_version','counter_review','reversed_on','reason','counter_page','counter_q','new_counter_confirm','reverse_commit','reverse_move','choose_counter'];
 public static function unsupported(array$body):array{
  $out=[];foreach(self::FIELDS as$field)if(array_key_exists($field,$body)&&(!is_string($body[$field])||!mb_check_encoding($body[$field],'UTF-8')||preg_match($field==='reason'?'/[\x00-\x08\x0b\x0c\x0e-\x1f]/':'/[\x00-\x1f\x7f]/',$body[$field])))$out[$field]=$body[$field];return$out;
 }
 public static function assertInput(Finance$finance,array$body,array$query):void{
  if(!$finance->canWrite())throw new DomainException('Keine ausreichende Finanzberechtigung.',403);
  if(self::unsupported($body))throw new DomainException('Ursprüngliche Gegenbuchungsangaben vollständig prüfen. Diese Eingabeform kann nicht als neue Gegenbewegung übernommen werden.',422);
  if(!is_string($body['id']??null)||!is_string($query['entry']??null)||($query['view']??null)!=='finance'||$body['id']!==$query['entry'])throw new DomainException('Gegenbuchung im ursprünglichen Beleg erneut öffnen. Die eingereichten Angaben bleiben erhalten.',409);
  $source=$finance->reversalSource($body['id']);
  if(($body['reverse_commit']??null)!=='1')return;
  foreach(['version','counter_id','counter_version','reversed_on','reason']as$field)if(!array_key_exists($field,$body))throw new DomainException('Ursprüngliche Gegenbuchungsangaben vollständig prüfen. Diese Eingabeform kann nicht als neue Gegenbewegung übernommen werden.',422);
  foreach(['version','counter_version']as$field)if(!preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/D',$body[$field]))throw new DomainException('Ursprüngliche Beleg- und Gegenbewegungsfassung prüfen. Zuerst den aktuellen Korrekturstand lesen.',422);
  $original=$source['original']['id']??'';$submitted=$body['reverse_original']??'';
  if(($submitted!==''&&$submitted!==$original)||(!$source['entry']['reversed_on']&&$submitted!==$original))throw new DomainException('Die ursprüngliche Zahlung gehört nicht mehr zu dieser Prüffassung. Aktuellen Korrekturstand prüfen.',409);
 }
 public static function dateInputType(string$value):string{
  if(!preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D',$value,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))return'text';return'date';
 }
 public static function original(array$body):string{
  $body=array_diff_key($body,['csrf'=>true]);$json=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
  if(!mb_check_encoding(serialize($body),'UTF-8'))$json.="\n".json_encode(['original_bytes_base64'=>base64_encode(serialize($body))],JSON_THROW_ON_ERROR);return$json;
 }
}
