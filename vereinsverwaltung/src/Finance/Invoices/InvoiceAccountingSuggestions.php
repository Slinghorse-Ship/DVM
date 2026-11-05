<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
require_once __DIR__.'/InvoiceInbox.php';
/** Read-only suggestions from this supplier's existing accounting, never from document prose. */
final class InvoiceAccountingSuggestions
{
 public function __construct(private PDO $db,private string $actor){}
 public function suggest(string $document,string $supplier,string $date,array $positions,string $category,string $purpose='general'):array
 {
  $finance=new Finance($this->db,$this->actor);if(!$finance->canRead())throw new DomainException('Finanz-Leserecht erforderlich.');
  $doc=(new Documents($this->db,$this->actor))->document($document);
  $contact=(new Registry($this->db,$this->actor))->contact($supplier);
  if($contact['kind']!=='organisation'||!in_array('finance',$doc['scopes'],true)||$doc['club_id']!==$contact['club_id'])throw new DomainException('Rechnung und Firmenkontakt prüfen.');
  if(!array_is_list($positions)||count($positions)>50)throw new DomainException('Höchstens 50 Positionen prüfen.');
  foreach($positions as $row)if(!is_array($row)||!is_string($row['description']??null)||mb_strlen($row['description'])>300||!in_array($row['tax_rate']??null,['0','7','19'],true))throw new DomainException('Positionsangaben prüfen.');
  $context=InvoiceInbox::purposeData($purpose);$empty=array_fill(0,count($positions),null);
  // Only event assignments retain a trustworthy structured purpose. Free-text notes cannot identify other purposes.
  if(!in_array($context['type'],['appointment','artist','gema'],true)||!isset(Finance::CATEGORIES[$category]))return $empty;
  $year=TaxCatalog::documentYear($date);if(!TaxCatalog::hasYear($year))return $empty;
  $params=[$contact['club_id'],$supplier,$category,sprintf('%04d-01-01',$year),sprintf('%04d-12-31',$year),$context['type'],$context['reference']];
  $purposeWhere='EXISTS(SELECT 1 FROM finance_event_assignments a WHERE a.club_id=e.club_id AND a.entry_id=e.id AND a.purpose=? AND a.event_id=?)';
  $q=$this->db->prepare("SELECT p.description,p.sphere,p.account_code,p.booking_key,p.tax_rate,p.catalog_year FROM accounting_positions p JOIN finance_entries e ON e.club_id=p.club_id AND e.id=p.entry_id JOIN finance_supplier_contacts s ON s.club_id=e.club_id AND s.entry_id=e.id WHERE p.club_id=? AND s.contact_id=? AND e.kind='expense' AND e.status!='cancelled' AND e.category=? AND e.document_date BETWEEN ? AND ? AND $purposeWhere AND NOT EXISTS(SELECT 1 FROM finance_reversals r WHERE r.club_id=e.club_id AND r.entry_id=e.id) LIMIT 10001");$q->execute($params);$history=$q->fetchAll(PDO::FETCH_ASSOC);if(count($history)>10000)return $empty;
  $catalog=$finance->taxAccounts($year);$norm=static fn(string $s):string=>mb_strtolower(preg_replace('/\s+/u',' ',trim($s)));$out=[];
  foreach($positions as $row){$matches=array_filter($history,static fn($p)=>$norm($p['description'])===$norm($row['description']));$unique=[];$valid=true;
   foreach($matches as $p){if((int)$p['catalog_year']!==$year)continue;$a=$catalog[$p['account_code']]??null;if(!$a||!$a['active']||TaxCatalog::sphereOf((string)$p['sphere'])===''){$valid=false;break;}$unique[json_encode([$p['sphere'],$p['account_code'],$p['booking_key']],JSON_THROW_ON_ERROR)]=$p;}
   if(!$valid||count($unique)!==1){$out[]=null;continue;}$p=reset($unique);$key=$p['booking_key'];$rule=TaxCatalog::supportedKeys($year)[$key]??null;$fn=$catalog[$p['account_code']]['function']??'';
   if($key!==''&&(!$rule||$rule['direction']!=='expense'||(int)$rule['local_rate']!==(int)$row['tax_rate']||str_contains($fn,'AM')||str_contains($fn,'AV')))$key='';
   $out[]=['sphere'=>$p['sphere'],'account'=>$p['account_code'],'booking_key'=>'','notice'=>'Vorschlag aus früherer Kontierung mit SKR42 '.$year.'. Bitte prüfen.'];
  }return $out;
 }
}
