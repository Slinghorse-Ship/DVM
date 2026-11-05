<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
use Kulturhus\Verein\Finance;

/** Web account edits retain their exact choice before the existing workflow handles money settings. */
final class AccountSettingsForm
{
 public const ACTIONS=['finance-account-settings','finance-account-delete','finance-account-settings-refresh'];
 public const FIELDS=['account','version','name','kind','opening','opened_on','active','tax_account','fints_read','fints_write','confirm_name'];
 public static function unsupported(array$body):array{
  $out=[];foreach(self::FIELDS as$field)if(array_key_exists($field,$body)&&(!is_string($body[$field])||!mb_check_encoding($body[$field],'UTF-8')||preg_match('/[\x00-\x1f\x7f]/',$body[$field])))$out[$field]=$body[$field];return$out;
 }
 public static function required(array$body):array{return($body['action']??null)==='finance-account-delete'?['account','version','confirm_name']:['account','version','name','active','tax_account','fints_read','fints_write'];}
 public static function assertInput(Finance$finance,array$body,array$query):void{
  if(!$finance->canWrite())throw new DomainException('Keine ausreichende Finanzberechtigung.',403);
  if(self::unsupported($body)||array_diff(self::required($body),array_keys($body)))throw new DomainException('Ursprüngliche Kontoeingaben vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
  if(!is_string($body['account']??null)||!is_string($query['account']??null)||($query['view']??null)!=='finance'||$body['account']!==$query['account'])throw new DomainException('Kontoeinstellungen am ursprünglich geöffneten Konto erneut prüfen.',409);
  $finance->account($body['account']);
  if(!preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/D',$body['version']))throw new DomainException('Ursprüngliche Kontofassung prüfen. Zuerst den aktuellen Stand vergleichen.',422);
  if(($body['action']??null)==='finance-account-settings'){
   foreach(['active','fints_read','fints_write']as$field)if(!in_array($body[$field],['0','1'],true))throw new DomainException('Ursprüngliche Konto- und Bankfreigaben prüfen. Ungültige Auswahl wird nicht ersetzt.',422);
   if(isset($body['kind'])&&!in_array($body['kind'],['offline','cash'],true))throw new DomainException('Bankkonto oder Handkasse wählen.',422);
  }
 }
 public static function original(array$body):string{
  unset($body['csrf']);$json=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);if(!mb_check_encoding(serialize($body),'UTF-8'))$json.="\n".json_encode(['original_bytes_base64'=>base64_encode(serialize($body))],JSON_THROW_ON_ERROR);return$json;
 }
 public static function dateInputType(string$value):string{
  if(!preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D',$value,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))return'text';return'date';
 }
}
