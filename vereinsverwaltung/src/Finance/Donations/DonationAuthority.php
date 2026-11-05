<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
/** Shared authority for non-cash waivers. No bank transaction is created to satisfy this check. */
final class DonationAuthority
{
 public static function read(PDO $db,string $actor):array
 {
  $a=Permissions::actor($db,$actor);if($a['finance_access']!=='write')throw new DomainException('Finanz-Schreibrecht erforderlich.');
  $settings=new Settings($db,$actor);$section=$settings->section('tax');$tax=$section['data'];$stored=DocumentCatalog::query($db,"SELECT payload FROM club_settings WHERE club_id=? AND section='club'",[$a['club_id']])->fetchColumn();$club=$stored?json_decode($stored,true,512,JSON_THROW_ON_ERROR):SettingsSchema::defaults('club');
  foreach(['name','street','postcode','city'] as $key)if(trim($club[$key]??'')==='')throw new DomainException('Vollständige Vereinsanschrift erforderlich.');
  foreach(['office','number','purpose','notice_document_id','receipt_signatory'] as $key)if(trim($tax[$key]??'')==='')throw new DomainException('Steuerbescheid und Ausstellungsberechtigung vollständig hinterlegen.');
  if(empty($tax['receipt_authority_reviewed'])||!in_array($tax['membership_deductible']??'',['yes','no'],true))throw new DomainException('Ausstellungsberechtigung und Beitragsabzug zuerst prüfen.');
  $type=$tax['notice_type'];if(!in_array($type,['exemption','corporate','assessment'],true))throw new DomainException('Bescheidart prüfen.');
  $date=$type==='assessment'?$tax['assessment_date']:$tax['exemption_date'];$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date||$date>date('Y-m-d'))throw new DomainException('Bescheiddatum prüfen.');
  if($type==='assessment'){if(empty($tax['assessment_no_prior_notice'])||(int)$d->format('Y')<(int)date('Y')-3)throw new DomainException('§60a-Voraussetzungen oder Gültigkeit nicht bestätigt.');}
  elseif(trim($tax['assessment'])===''||$d<new \DateTimeImmutable('-5 years today'))throw new DomainException('Veranlagungszeitraum fehlt oder Freistellungsbescheid ist zu alt.');
  $docs=new Documents($db,$actor);$doc=$docs->document($tax['notice_document_id']);if($doc['state']!=='active'||$doc['origin']!=='upload'||$doc['mime']!=='application/pdf'||!in_array('finance',$doc['scopes'],true))throw new DomainException('Aktiven PDF-Finanznachweis auswählen.');
  return ['club'=>$club,'tax'=>$tax,'authority'=>['document_id'=>$doc['id'],'revision'=>$doc['revision'],'sha256'=>$doc['sha256'],'signatory'=>$tax['receipt_signatory'],'tax_settings_version'=>$section['version']]];
 }
}
