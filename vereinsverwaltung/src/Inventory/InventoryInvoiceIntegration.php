<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;use Throwable;

/** Explicitly reviewed original-to-inventory plan. No invoice booking or payment is manufactured. */
final class InventoryInvoiceIntegration
{
 public function __construct(private PDO $db,private string $actor){}
 private function q(string $sql,array $args=[]):\PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
 private function authority():array{$a=Permissions::actor($this->db,$this->actor);if($a['finance_access']!=='write'||!(new Inventory($this->db,$this->actor))->canWrite())throw new DomainException('Finanz- und Inventarschreibrecht erforderlich.');return $a;}
 private static function json(array $v):string{return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
 private static function text(mixed $v,int $max,bool $required=true):string{if(!is_string($v)||strlen($v)>$max||preg_match('/[\x00-\x1f]/',$v)||($required&&trim($v)===''))throw new DomainException('Übernahmeangaben prüfen.');return trim($v);}
 private static function integer(mixed $v,int $min=0,int $max=100000000000):int{if(!is_int($v)||$v<$min||$v>$max)throw new DomainException('Ganzzahlige Mengen, Stände und Centbeträge prüfen.');return $v;}
 /** Human-facing evidence comes from the reviewed originals, never the operational audit reason. */
 private static function valueNote(array $sources,bool $pooled):string
 {
  $suffix=$pooled?' Gemeinsamer Original-Paketpreis; Aufteilung auf die einzelnen Gegenstände noch offen.':'';
  $more=' Weitere Positionen stehen bei den verknüpften Rechnungsoriginalen.';$parts=[];
  foreach($sources as $source){
   $date=substr($source['date'],8,2).'.'.substr($source['date'],5,2).'.'.substr($source['date'],0,4);
   $gross=$source['gross_cents'];$money=number_format(intdiv($gross,100),0,',','.').','.str_pad((string)($gross%100),2,'0',STR_PAD_LEFT);
   $line='Rechnung '.$source['reference'].' vom '.$date.', Pos. '.$source['ordinal'].': '.$money.' € brutto';
   if(strlen(implode('; ',[...$parts,$line]).$suffix.$more)>1000)return implode('; ',$parts).$suffix.$more;
   $parts[]=$line;
  }
  return implode('; ',$parts).$suffix;
 }
 private function validate(array $plan):array
 {
  $a=$this->authority();if(($plan['originals_reviewed']??false)!==true)throw new DomainException('Originale und Zusammenführung ausdrücklich prüfen.');
  $location=$plan['location']??[];$id=self::text($location['id']??null,80);$version=self::integer($location['version']??null,1);
  $place=$this->q('SELECT * FROM inventory_locations WHERE club_id=? AND id=?',[$a['club_id'],$id])->fetch();if(!$place||!$place['active']||(int)$place['version']!==$version)throw new DomainException('Lagerort inzwischen geändert.');
  $reason=self::text($plan['reason']??null,1000);$groups=$plan['groups']??null;if(!is_array($groups)||!$groups||count($groups)>50)throw new DomainException('Eine bis 50 Inventargruppen prüfen.');
  $normalized=[];$keys=[];$sources=[];$dates=[];$used=[];$pools=[];$inventory=new Inventory($this->db,$this->actor);$docs=new Documents($this->db,$this->actor);
  foreach($groups as $g){
   if(!is_array($g))throw new DomainException('Inventargruppe prüfen.');$key=self::text($g['key']??null,80);if(isset($keys[$key]))throw new DomainException('Inventargruppe doppelt.');$keys[$key]=true;
   $item=self::text($g['item_id']??'',80,false);$quantity=self::integer($g['quantity']??null,1,500);$units=$g['units']??null;if(!is_array($units)||count($units)!==$quantity)throw new DomainException('Ein geprüfter Eintrag je Stück erforderlich.');
   $unit=self::text($g['unit']??null,30);$content=self::text($g['content_unit']??null,40);if(!isset(Inventory::UNITS[$unit],Inventory::UNITS[$content]))throw new DomainException('Bestandseinheiten prüfen.');
   $row=['key'=>$key,'item_id'=>$item,'version'=>self::integer($g['version']??0),'stock_version'=>self::integer($g['stock_version']??0),'quantity'=>$quantity,'name'=>self::text($g['name']??null,160),'category'=>self::text($g['category']??'',100,false),'unit'=>$unit,'content_quantity'=>self::integer($g['content_quantity']??1,1,1000000),'content_unit'=>$content,'notes'=>self::text($g['notes']??'',1000,false),'units'=>[],'sources'=>[],'pool_key'=>self::text($g['pool_key']??'',80,false)];
   if($item!==''){$current=$inventory->item($item);if(!$current['active']||(int)$current['version']!==$row['version']||(int)$current['stock_version']!==$row['stock_version'])throw new DomainException('Gegenstand inzwischen geändert.');if(isset($used[$item]))throw new DomainException('Gegenstand mehrfach im Plan.');$used[$item]=true;}
   foreach($units as $u){if(!is_array($u))throw new DomainException('Stückangaben prüfen.');$row['units'][]=['serial_number'=>self::text($u['serial_number']??'',120,false),'unit_value_cents'=>($u['unit_value_cents']??null)===null?null:self::integer($u['unit_value_cents']),'value_note'=>self::text($u['value_note']??'',1000,false)];}
   if(!is_array($g['sources']??null)||!$g['sources']||count($g['sources'])>50)throw new DomainException('Originalpositionen erforderlich.');
   foreach($g['sources'] as $s){
    $docid=self::text($s['document_id']??null,80);$doc=$docs->document($docid,true);$revision=self::integer($s['revision']??null,1);$docversion=self::integer($s['version']??null,1);$sha=self::text($s['sha256']??null,64);$ordinal=self::integer($s['ordinal']??null,1,1000);$gross=self::integer($s['gross_cents']??null,1);$date=self::text($s['date']??null,10);$parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if(!$parsed||$parsed->format('Y-m-d')!==$date||$date>date('Y-m-d'))throw new DomainException('Anschaffungsdatum am Original prüfen.');
    if($doc['state']!=='active'||$doc['origin']!=='upload'||$doc['kind']!=='receipt'||!in_array('finance',$doc['scopes'],true)||(int)$doc['version']!==$docversion||(int)$doc['revision']!==$revision)throw new DomainException('Aktuelles aktives Rechnungsoriginal erforderlich.');
    $file=$docs->file($docid,$revision);if(!hash_equals($file['sha256'],$sha)||!hash_equals($sha,hash('sha256',$file['bytes'])))throw new DomainException('Originaldatei inzwischen geändert.');
    $recognized=(new InvoiceRecognition($this->db,$this->actor))->result($docid);$known=$recognized['fields']['Rechnungsdatum']??null;if(is_string($known)&&$known!==$date)throw new DomainException('Anschaffungsdatum widerspricht der aktuellen Erkennung; Original zuerst klären.');
    if(isset($dates[$sha])&&$dates[$sha]!==$date)throw new DomainException('Ein Original darf keine unterschiedlichen Rechnungsdaten erhalten.');$dates[$sha]=$date;
    $reference=array_key_exists('reference',$s)?self::text($s['reference'],200,false):'';
    if($reference===''){$reference=($recognized['state']??'')==='ready'?($recognized['fields']['Rechnungsnummer']??''):'';if(!is_string($reference))$reference='';$reference=self::text(trim($reference)!==''?$reference:$doc['title'],200);}
    $source=['document_id'=>$docid,'version'=>$docversion,'revision'=>$revision,'sha256'=>$sha,'ordinal'=>$ordinal,'gross_cents'=>$gross,'date'=>$date,'reference'=>$reference];$sourcekey=$sha.':'.$ordinal;
    if($this->q('SELECT 1 FROM inventory_acquisition_sources WHERE club_id=? AND sha256=? AND source_ordinal=?',[$a['club_id'],$sha,$ordinal])->fetchColumn())throw new DomainException('Originalposition bereits übernommen; gespeicherten Übernahmevorgang verwenden.');
    if(isset($sources[$sourcekey])&&($row['pool_key']===''||$sources[$sourcekey]!==$row['pool_key']))throw new DomainException('Originalposition würde mehrfach bewertet.');$sources[$sourcekey]=$row['pool_key'];
    $row['sources'][]=$source;
   }
   $sum=array_sum(array_column($row['sources'],'gross_cents'));$unknown=count(array_filter($row['units'],static fn($u)=>$u['unit_value_cents']===null));
   if($row['pool_key']!==''){
    if(!$unknown||$unknown!==$quantity||count($row['sources'])!==1)throw new DomainException('Gemeinsamer Paketpreis benötigt unbekannte Einzelwerte und genau eine Quellposition.');
    $p=['source'=>$row['sources'][0],'description'=>self::text($g['pool_description']??null,300)];if(isset($pools[$row['pool_key']])&&$pools[$row['pool_key']]!==$p)throw new DomainException('Gemeinsamer Paketpreis widersprüchlich.');$pools[$row['pool_key']]=$p;
   }elseif($unknown||array_sum(array_column($row['units'],'unit_value_cents'))!==$sum)throw new DomainException('Einzelwerte müssen genau die geprüften Originalpositionen ergeben; unaufgeteilte Pakete als gemeinsamer Preis.');
   $row['acquired_on']=min(array_column($row['sources'],'date'));$row['value_note']=self::valueNote($row['sources'],$row['pool_key']!=='');foreach($row['units'] as &$unitRow)if($unitRow['value_note']==='')$unitRow['value_note']=$row['value_note'];unset($unitRow);$normalized[]=$row;
  }
  foreach($pools as $key=>$pool)if(count(array_filter($normalized,static fn($r)=>$r['pool_key']===$key))<2)throw new DomainException('Gemeinsamer Paketpreis benötigt mehrere Gegenstandsgruppen.');
  return ['club'=>$a['club_id'],'actor'=>$a['id'],'location'=>['id'=>$id,'version'=>$version],'reason'=>$reason,'groups'=>$normalized,'pools'=>$pools];
 }
 public function preview(array $plan):array{$data=$this->validate($plan);return ['hash'=>hash('sha256',self::json($data)),'summary'=>$data];}
 public function apply(array $plan,string $expectedHash,string $requestKey):array
 {
  if(!preg_match('/^[a-f0-9]{64}$/D',$expectedHash)||!preg_match('/^[a-f0-9]{32}$/D',$requestKey))throw new DomainException('Vorschau und Vorgangskennung erforderlich.');
  $a=$this->authority();$payload=hash('sha256',self::json($plan));$this->db->beginTransaction();
  try{
   $a=$this->authority();$old=$this->q('SELECT * FROM inventory_integration_runs WHERE club_id=? AND request_key=?',[$a['club_id'],$requestKey])->fetch();
   if($old){if($old['actor_id']!==$a['id']||!hash_equals($old['payload_hash'],$payload))throw new DomainException('Vorgangskennung bereits anders verwendet.');foreach($plan['groups'] as $group)foreach($group['sources'] as $source)(new Documents($this->db,$this->actor))->document($source['document_id'],true);$result=json_decode($old['result_json'],true,512,JSON_THROW_ON_ERROR);$this->db->commit();return $result;}
   $data=$this->validate($plan);if(!hash_equals($expectedHash,hash('sha256',self::json($data))))throw new DomainException('Bestand oder Vorschau geändert.');$result=['items'=>[],'pools'=>[]];
   foreach($data['pools'] as $key=>$pool){$id=bin2hex(random_bytes(16));$s=$pool['source'];$this->q('INSERT INTO inventory_acquisition_pools VALUES(?,?,?,?,?,?,?,?,?,?)',[$a['club_id'],$id,$s['document_id'],$s['revision'],$s['sha256'],$s['ordinal'],$s['gross_cents'],$pool['description'],$a['id'],gmdate('c')]);$result['pools'][$key]=$id;}
   $inventory=new Inventory($this->db,$this->actor);
   foreach($data['groups'] as $group){$id=$inventory->integrateAcquisitionInTransaction($group,$data['location'],$data['reason']);$result['items'][$group['key']]=$id;
    foreach($group['sources'] as $source){$pool=$group['pool_key']!==''?$result['pools'][$group['pool_key']]:null;$this->q('INSERT INTO inventory_acquisition_sources VALUES(?,?,?,?,?,?,?,?,?,?)',[$a['club_id'],$id,$source['document_id'],$source['revision'],$source['sha256'],$source['ordinal'],$pool?null:$source['gross_cents'],$pool,$source['date'],$pool?$group['quantity']:null]);
     $this->q("INSERT IGNORE INTO document_links(club_id,document_id,source_type,source_id) VALUES(?,?,'item',?)",[$a['club_id'],$source['document_id'],$id]);
     $this->q('INSERT INTO document_history(club_id,document_id,actor_id,action,version,happened_at,details) VALUES(?,?,?,?,?,?,?)',[$a['club_id'],$source['document_id'],$a['id'],'inventory-linked',$source['version'],gmdate('c'),self::json(['item'=>$id,'ordinal'=>$source['ordinal'],'pool'=>$pool,'reason'=>$data['reason']])]);
    }
   }
   $dated=[];foreach($data['groups'] as $group)foreach($group['sources'] as $source)$dated[$source['document_id']]=$source['date'];
   foreach($dated as $id=>$date){$before=$this->q('SELECT document_date,version FROM documents WHERE club_id=? AND id=?',[$a['club_id'],$id])->fetch();$this->q('UPDATE documents SET version=version+1,document_date=? WHERE club_id=? AND id=?',[$date,$a['club_id'],$id]);$this->q('INSERT INTO document_history(club_id,document_id,actor_id,action,version,happened_at,details) VALUES(?,?,?,?,?,?,?)',[$a['club_id'],$id,$a['id'],'inventory-original-date',(int)$before['version']+1,gmdate('c'),self::json(['before_date'=>$before['document_date'],'document_date'=>$date,'reason'=>$data['reason']])]);}
   $this->q('INSERT INTO inventory_integration_runs VALUES(?,?,?,?,?,?)',[$a['club_id'],$requestKey,$a['id'],$payload,self::json($result),gmdate('c')]);$this->db->commit();return $result;
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
}
