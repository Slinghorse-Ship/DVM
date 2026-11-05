<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
/** Original2025 footnote11; read historical records without rewriting them. */
final class DatevComparisonCatalog {
 public const CODES=['41850','74600','74640','75600','75610','75630'];
 public const PATH='../resources/accounting/reviews/comparison-only-2025-r82.json';
 public const SHA256='b41d22e8864060d72724c25d23b556c6d36724ddd62c149e164b9df190279fe4';
 public static function codes(int $year,array $data):array{
  if($year!==2025)return [];
  $raw=str_replace("\r\n","\n",file_get_contents(dirname(__DIR__, 2).'/'.self::PATH));$e=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
  if(!hash_equals(self::SHA256,hash('sha256',$raw))||$e['codes']!==self::CODES||$e['pdf_sha256']!==$data['sha256']||$e['source']!==$data['source']||$e['catalog_sha256']!==hash('sha256',str_replace("\r\n","\n",file_get_contents(dirname(__DIR__, 2).'/../data/skr42-2025.json'))))throw new DomainException('Originalnachweis der Vorjahresvergleichskonten wurde geändert.');
  $codes=[];foreach($data['accounts'] as $a)if(str_contains($a['name'],'11)'))$codes[]=$a['code'];sort($codes,SORT_STRING);if($codes!==self::CODES)throw new DomainException('Vergleichskonten und Originalfußnote stimmen nicht überein.');return self::CODES;
 }
 public static function assertPosting(int $year,array $accounts,array $lines):void{
  if($year!==2025)return;$codes=self::codes($year,TaxCatalog::sourceData('skr42-2025.json'));
  foreach($lines as $l)if(in_array($accounts[$l['account']]['skr_code']??null,$codes,true))throw new DomainException('Dieses SKR42-Konto2025 ist ausschließlich für den Vorjahresvergleich vorgesehen. Für eine aktuelle Buchung das fachlich richtige Jahreskonto wählen.');
 }
}
