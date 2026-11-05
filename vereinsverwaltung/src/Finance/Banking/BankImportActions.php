<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
final class BankImportActions
{
 public static function handle(PDO $db,string $actor,string $action,array $post,EnableBanking $client):string{
  if($client->actorId()!==$actor||$client->environment()!=='PRODUCTION')throw new DomainException('Bankimport nur aus der eigenen produktiven Freigabe.');$service=new BankImport($db,$actor);
  $get=static function(string $key,string $default='')use($post):string{$v=$post[$key]??$default;if(!is_string($v))throw new DomainException('Bankformular prüfen.');return $v;};
  if($action==='enable-map'){$service->map('enable','PRODUCTION',$client->account($get('bank_uid')),$get('account_id'));return 'Bankkonto zugeordnet. Jetzt den gewünschten Zeitraum übernehmen.';}
  if($action==='enable-import'){$uid=$get('bank_uid');$from=$get('date_from');$to=$get('date_to');$map=$service->mapping($get('mapping'));if($map['provider']!=='enable')throw new DomainException('Bankdienst und Konto passen nicht zusammen.');$run=$service->fetch($map['id'],'PRODUCTION',$client->account($uid),$from,$to,$get('request_key'),fn($next)=>$client->transactionsPage($uid,$from,$to,$next));return $run['state']==='complete'?'Abruf gespeichert: '.$run['rows_count'].' Bankzeilen, '.$run['new_count'].' neu, '.$run['review_count'].' zur Prüfung.':'Dieser Abruf wurde nicht vollständig übernommen. Bitte einen neuen Abruf starten.';}
  if($action==='enable-review'){$version=$get('record_version');if(!ctype_digit($version))throw new DomainException('Bankformular neu öffnen.');$service->decide($get('record'),(int)$version,$get('decision'),$get('transaction'),$get('reason'));return 'Bankumsatz geprüft und Entscheidung gespeichert.';}
  throw new DomainException('Unbekannte Bankaktion.');
 }
}
