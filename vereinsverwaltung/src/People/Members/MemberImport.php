<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;use PDO;
final class MemberImport
{
 public const FIELDS=['name'=>'Vollständiger Name','first'=>'Vorname','last'=>'Nachname','number'=>'Mitgliedsnummer','legacy'=>'Alte Mitgliedsnummer','email'=>'E-Mail','address'=>'Anschrift','phone'=>'Telefon','birthday'=>'Geburtstag','start'=>'Eintritt','end'=>'Austritt','status'=>'Status (aktiv/passiv/ehemalig)',...MemberImportDetails::FIELDS];
 public function __construct(private PDO $db,private string $actor){}
 /** Suggest exact familiar headings; ambiguous columns remain unselected for explicit review. */
 public static function suggest(#[\SensitiveParameter] array $book,?int $selectedSheet=null,?int $selectedHeader=null):array
 {
  $aliases=['name'=>['Name','Vollständiger Name','Vollname','Full name'],'first'=>['Vorname','First name'],'last'=>['Nachname','Familienname','Last name','Surname'],'number'=>['Mitgliedsnummer','Mitglieds-Nr.','Mitgliedsnummer aktuell','Member number'],'legacy'=>['Alte Mitgliedsnummer','Bisherige Mitgliedsnummer','Legacy member number'],'email'=>['E-Mail','Email','E-Mail-Adresse'],'address'=>['Anschrift','Adresse','Address'],'phone'=>['Telefon','Telefonnummer','Phone'],'birthday'=>['Geburtstag','Geburtsdatum','Date of birth'],'start'=>['Eintritt','Eintrittsdatum','Mitglied seit','Membership start'],'end'=>['Austritt','Austrittsdatum','Membership end'],'status'=>['Status','Mitgliedsstatus','Status (aktiv/passiv/ehemalig)','Membership status']];
  $aliases+=MemberImportDetails::ALIASES;
  $norm=static fn(string$s):string=>preg_replace('/[^\pL\pN]/u','',mb_strtolower($s));$keys=[];foreach($aliases as$field=>$labels)foreach($labels as$label)$keys[$norm($label)]=$field;$best=['sheet'=>$selectedSheet??0,'header'=>$selectedHeader??1,'mapping'=>[],'ambiguous'=>[],'recognized'=>0];
  foreach($book['sheets']as$sheet=>$source){if($selectedSheet!==null&&$sheet!==$selectedSheet)continue;foreach($source['rows']as$index=>$row){if($index>=20||($selectedHeader!==null&&$index!==$selectedHeader-1))continue;$candidates=[];foreach($row as$column=>$label)if(is_string($label)&&isset($keys[$norm($label)]))$candidates[$keys[$norm($label)]][]=(string)$column;$mapping=[];$ambiguous=[];foreach($candidates as$field=>$columns)if(count($columns)===1)$mapping[$field]=$columns[0];else$ambiguous[]=$field;$score=count($mapping);if($score>$best['recognized'])$best=['sheet'=>$sheet,'header'=>$index+1,'mapping'=>$mapping,'ambiguous'=>$ambiguous,'recognized'=>$score];}}
  return$best;
 }
 private static function date(string $value,bool $date1904,bool $year=false,bool $excelDate=false):array
 {
  $value=trim($value);if($value==='')return ['','day'];
  if(!$excelDate&&preg_match('/^[0-9]{4}(?:\.0+)?$/D',$value)){
   if(!$year)throw new DomainException('Ein bloßes Jahr reicht für Geburtstag oder Austritt nicht aus.');
   if(!preg_match('/^[0-9]{4}$/D',$value)||(int)$value<1800||(int)$value>(int)date('Y'))throw new DomainException('Eintrittsjahr vollständig prüfen.');
   return [$value.'-01-01','year'];
  }
  if(preg_match('/^[0-9]{1,5}(?:\.0+)?$/D',$value)){$n=(int)$value;if($n<1||$n>80000||(!$date1904&&$n===60))throw new DomainException('Excel-Datum ungültig.');$value=(new \DateTimeImmutable($date1904?'1904-01-01':($n<60?'1899-12-31':'1899-12-30')))->modify('+'.$n.' days')->format('Y-m-d');}
  foreach(['Y-m-d','d.m.Y','j.n.Y','d/m/Y'] as $format){$d=\DateTimeImmutable::createFromFormat('!'.$format,$value);if($d&&$d->format($format)===$value)return [$d->format('Y-m-d'),'day'];}throw new DomainException('Datum als TT.MM.JJJJ oder JJJJ-MM-TT eingeben.');
 }
 public function preview(#[\SensitiveParameter] array $workbook,int $sheet,int $header,array $mapping,string $defaultStart='',bool $locked=false):array
 {
  $registry=new Registry($this->db,$this->actor);if(!$registry->canWrite())throw new DomainException('Mitglieder-Schreibrecht erforderlich.');$a=Permissions::actor($this->db,$this->actor);
  if(!isset($workbook['sheets'][$sheet])||$header<1||$header>20)throw new DomainException('Tabellenblatt und Überschriftszeile prüfen.');$source=$workbook['sheets'][$sheet];
  foreach($mapping as $key=>$value)if(!isset(self::FIELDS[$key])||!is_string($value)||($value!==''&&(!preg_match('/\A(?:0|[1-9]|[1-5][0-9]|6[0-3])\z/D',$value))))throw new DomainException('Spaltenzuordnung prüfen.');
  $used=array_filter($mapping,static fn($value)=>$value!=='');if(count($used)!==count(array_unique($used)))throw new DomainException('Eine Dateispalte nur einem Feld zuordnen.');
  if(($mapping['name']??'')===''&&(($mapping['first']??'')===''||($mapping['last']??'')===''))throw new DomainException('Name oder Vorname und Nachname zuordnen.');
  $contacts=DocumentCatalog::query($this->db,'SELECT c.*,m.member_number,m.status membership_status,m.starts_on,m.ends_on,m.version membership_version FROM contacts c LEFT JOIN memberships m ON m.club_id=c.club_id AND m.contact_id=c.id WHERE c.club_id=? ORDER BY c.id,m.id'.($locked?' FOR SHARE NOWAIT':''),[$a['club_id']])->fetchAll();
  $norm=static fn($s)=>mb_strtolower(preg_replace('/\s+/u',' ',trim($s)));$rows=[];$seenNames=[];$seenNumbers=[];
  foreach($source['rows'] as $index=>$values){if($index<$header)continue;$raw=[];$problem='';foreach(self::FIELDS as $field=>$label){$col=$mapping[$field]??'';$raw[$field]=$col!==''?trim((string)($values[(int)$col]??'')):'';if($col!==''&&!empty($source['formulas'][$index][(int)$col]))$problem='Formel oder Excel-Fehler in zugeordneter Spalte; zuerst als Werte speichern.';}
   if(!array_filter($raw,fn($v)=>$v!==''))continue;$record=$raw;$record['name']=$raw['name']!==''?$raw['name']:trim($raw['first'].' '.$raw['last']);$state='new';$target='';$note='Neues Mitglied anlegen';$matches=[];$warnings=[];
   try{
    if($problem!=='')throw new DomainException($problem);if($record['name']===''||strlen($record['name'])>200||str_starts_with($record['name'],'='))throw new DomainException('Name fehlt oder ist ungültig.');
    foreach(['number','legacy'] as $field)if($record[$field]!==''&&!preg_match('/^[\pL\pN ._\/-]{1,80}$/uD',$record[$field]))throw new DomainException('Mitgliedsnummer prüfen.');
    if(strlen($record['email'])>254||($record['email']!==''&&!filter_var($record['email'],FILTER_VALIDATE_EMAIL))||strlen($record['address'])>1000||strlen($record['phone'])>80)throw new DomainException('Kontaktdaten prüfen.');
    $isExcelDate=static function(string$field)use($source,$index,$mapping):bool{$column=$mapping[$field]??'';return $column!==''&&!empty($source['date_cells'][$index][(int)$column]);};
    [$record['start'],$record['start_precision']]=self::date($raw['start']!==''?$raw['start']:$defaultStart,(bool)$workbook['date1904'],true,$raw['start']!==''&&$isExcelDate('start'));[$record['birthday']]=self::date($raw['birthday'],(bool)$workbook['date1904'],false,$isExcelDate('birthday'));[$record['end']]=self::date($raw['end'],(bool)$workbook['date1904'],false,$isExcelDate('end'));
    if($record['birthday']>date('Y-m-d')||$record['start']>date('Y-m-d'))throw new DomainException('Geburtstag und Eintritt dürfen nicht in der Zukunft liegen.');
    $record['status']=mb_strtolower($raw['status']!==''?$raw['status']:'aktiv');if(!in_array($record['status'],['aktiv','passiv','ehemalig'],true))throw new DomainException('Status muss aktiv, passiv oder ehemalig sein.');
    if($record['status']==='ehemalig'&&$record['end']==='')throw new DomainException('Austrittsdatum für ehemaliges Mitglied fehlt.');if($record['end']!==''&&($record['start']===''||$record['end']<$record['start']))throw new DomainException('Austritt liegt vor Eintritt oder Eintritt fehlt.');if($record['end']!=='')$record['status']='ehemalig';
    $matches=[];foreach($contacts as $c){$sameName=$norm($c['name'])===$norm($record['name']);$sameNumber=($record['number']!==''&&$c['member_number']!==null&&$norm($c['member_number'])===$norm($record['number']))||($record['legacy']!==''&&$c['legacy_member_number']!==null&&$norm($c['legacy_member_number'])===$norm($record['legacy']));if($sameName||$sameNumber)$matches[$c['id']][]=$c;if($record['email']!==''&&$norm((string)$c['email'])===$norm($record['email'])&&!$sameName)$warnings[$c['id']]=['id'=>$c['id'],'name'=>$c['name']];}
    if(count($matches)>1)throw new DomainException('Mehrere Profile passen zu Name oder Nummer. Manuell klären.');
    if($matches){$matched=reset($matches);$c=$matched[0];$target=$c['id'];if($norm($c['name'])!==$norm($record['name']))throw new DomainException('Die Nummer gehört zu einem anderen Namen. Keine Nummer wird ersetzt.');if($c['archived_at']!==null)throw new DomainException('Profil ist archiviert. Zuerst im Archiv prüfen.');if($record['birthday']!==''&&$c['birthday']!==null&&$c['birthday']!==$record['birthday'])throw new DomainException('Geburtstage widersprechen sich.');
     if(array_filter($matched,fn($m)=>$m['member_number']!==null)){$state='existing';$note='Mitglied bereits vorhanden; vorhandene Daten und Nummern bleiben erhalten.';if($record['number']!==''&&!in_array($record['number'],array_column($matched,'member_number'),true))throw new DomainException('Abweichende Mitgliedsnummer. Vorhandene Nummer bleibt erhalten; manuell klären.');}
     else{if($record['legacy']!==''&&($c['legacy_member_number']??'')!==''&&$c['legacy_member_number']!==$record['legacy'])throw new DomainException('Abweichende alte Mitgliedsnummer; manuell klären.');$state='convert';$note='Vorhandenen Kontakt als Mitglied führen; Kontaktdaten und Familie bleiben erhalten.';}
    }
    if($state!=='existing'&&$record['start']==='')throw new DomainException('Eintritt fehlt. Spalte zuordnen oder gemeinsames Eintrittsdatum angeben.');
    $nameKey=$norm($record['name']);if(isset($seenNames[$nameKey]))throw new DomainException('Name innerhalb der Datei doppelt; Zeile '.$seenNames[$nameKey].' prüfen.');$seenNames[$nameKey]=$index+1;
    foreach(['number','legacy'] as $field)if($record[$field]!==''){$numberKey=$norm($record[$field]);if(isset($seenNumbers[$numberKey])&&$seenNumbers[$numberKey]!==$index+1)throw new DomainException('Nummer innerhalb der Datei doppelt.');$seenNumbers[$numberKey]=$index+1;}
   }catch(DomainException $e){$state='review';$note=$e->getMessage();}
   $duplicates=[];foreach($matches as$id=>$versions){$c=$versions[0];$duplicates[]=['id'=>(string)$id,'name'=>$c['name'],'numbers'=>array_values(array_unique(array_filter(array_column($versions,'member_number'),static fn($n)=>is_string($n)))),'archived'=>$c['archived_at']!==null];}
   $rows[]=['line'=>$index+1,'state'=>$state,'target'=>$target,'record'=>$record,'note'=>$note,'matches'=>$duplicates,'shared_email'=>array_values($warnings)];
  }
  if(!$rows)throw new DomainException('Keine Datenzeilen nach der Überschrift gefunden.');
  $details=(new MemberImportDetails($this->db,$this->actor))->review($rows,$locked);$rows=$details['rows'];
  return ['rows'=>$rows,'bank_count'=>count(array_filter($rows,static fn($row)=>in_array($row['state'],['new','convert'],true)&&($row['record']['_bank']??null)!==null)),'hash'=>hash('sha256',json_encode([$rows,$contacts,$details['source']],JSON_THROW_ON_ERROR))];
 }
 public function apply(#[\SensitiveParameter] array $book,int $sheet,int $header,array $mapping,string $start,string $expected,?MemberImportBankPermit $permit=null):array
 {
  try{return (new Registry($this->db,$this->actor))->importMembers(function()use($book,$sheet,$header,$mapping,$start,$expected,$permit){
   $a=Permissions::actor($this->db,$this->actor);DocumentCatalog::query($this->db,'SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT',[$a['club_id']])->fetchColumn();
   $p=$this->preview($book,$sheet,$header,$mapping,$start,true);
   if(!hash_equals($expected,$p['hash']))throw new DomainException('Bestand seit der Vorschau geändert. Vorschau erneut erstellen.');
   if(array_filter($p['rows'],fn($r)=>$r['state']==='review'))throw new DomainException('Zuerst alle unklaren Zeilen korrigieren. Es wurde nichts importiert.');
   if($p['bank_count']>0){if($permit===null)throw new DomainException('Bankdatenübernahme zuerst mit Passwort und gegebenenfalls Sicherheitscode bestätigen.',403);$permit->assert($this->db,$this->actor,$expected);}
   return$p['rows'];
  },fn(#[\SensitiveParameter] array $rows,array $ids)=>(new MemberImportDetails($this->db,$this->actor))->apply($rows,$ids,$permit,$expected));
  }catch(\PDOException $failure){if(in_array((int)($failure->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('Mitgliederimport wird gerade bearbeitet. Ursprüngliche Vorschau erneut prüfen.',503,$failure);throw$failure;}
 }
}
