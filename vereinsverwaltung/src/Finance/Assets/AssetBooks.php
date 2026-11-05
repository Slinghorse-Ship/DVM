<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
require_once __DIR__.'/AssetBookSuggestions.php';
require_once __DIR__.'/GwgDepreciation.php';
require_once __DIR__.'/DegressiveDepreciation.php';
require_once __DIR__.'/AssetDisposal.php';
require_once __DIR__.'/AssetValuationEvidence.php';
require_once __DIR__.'/PartnershipBookPlans.php';
require_once __DIR__.'/OwnIntangiblePlans.php';
require_once __DIR__.'/OriginalSubstance.php';
/** Confirmed linear, GWG or degressive plans; no tax basis inferred from inventory estimates. */
final class AssetBooks
{
 public function __construct(private PDO $db,private string $actor){}
 private function access(bool $write=false):array{$a=Permissions::actor($this->db,$this->actor);if(!in_array($a['finance_access'],$write?['write']:['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.');if(!(new WorkspaceAccess($this->db,$this->actor))->depreciationEnabled())throw new DomainException('Abschreibungen sind für diesen Verein in den Steuereinstellungen nicht aktiviert.');return $a;}
 private function q(string $sql,array $args=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
 /** Serialize plan edits, annual confirmations and balance source imports per club. */
 private function lockWriter(array $actor):void{
  $this->q('SELECT id FROM clubs WHERE id=? FOR UPDATE',[$actor['club_id']])->fetchColumn();
  if($this->access(true)['club_id']!==$actor['club_id'])throw new DomainException('Vereinszuordnung geändert. Bitte neu laden.');
 }
 public function items():array{$a=$this->access();return $this->q("SELECT i.id,i.name,i.code,p.version,p.payload FROM inventory_items i LEFT JOIN asset_book_plans p ON p.club_id=i.club_id AND p.item_id=i.id WHERE i.club_id=? AND (i.active=1 OR p.item_id IS NOT NULL) ORDER BY i.name",[$a['club_id']])->fetchAll();}
 public function get(string $item):array{$a=$this->access();$i=$this->q('SELECT * FROM inventory_items WHERE club_id=? AND id=?',[$a['club_id'],$item])->fetch();if(!$i)throw new DomainException('Gegenstand nicht verfügbar.');$p=$this->q('SELECT * FROM asset_book_plans WHERE club_id=? AND item_id=?',[$a['club_id'],$item])->fetch();return ['item'=>$i,'version'=>(int)($p['version']??0),'data'=>$p?json_decode($p['payload'],true,64,JSON_THROW_ON_ERROR):null,'years'=>$this->q('SELECT * FROM asset_book_years WHERE club_id=? AND item_id=? ORDER BY year',[$a['club_id'],$item])->fetchAll(),'history'=>$this->q('SELECT version,reason,happened_at,snapshot FROM asset_book_history WHERE club_id=? AND item_id=? ORDER BY version DESC',[$a['club_id'],$item])->fetchAll()];}
 /** Original invoice amounts are proposals, never an inventory valuation or a final tax basis. */
 public function suggestion(string $item):array{
  $a=$this->access();$p=$this->get($item);$out=['values'=>[],'sources'=>[],'message'=>'Anschaffungskosten anhand der zugehörigen Rechnung prüfen.'];
  if($p['data']||$p['item']['ownership']!=='club'||$p['item']['cash_account']!==null)return $out;
  $sources=$this->q('SELECT s.*,d.title FROM inventory_acquisition_sources s JOIN documents d ON d.club_id=s.club_id AND d.id=s.document_id WHERE s.club_id=? AND s.item_id=? ORDER BY s.acquired_on,s.source_ordinal',[$a['club_id'],$item])->fetchAll();
  if(!$sources)return $out;
  $documents=new Documents($this->db,$this->actor);
  foreach($sources as $s){try{$d=$documents->document($s['document_id'],true);if($d['state']!=='active'||!in_array('finance',$d['scopes'],true)||(int)$d['revision']!==(int)$s['revision'])return $out;}catch(DomainException){return $out;}}
  $out['sources']=$sources;
  $dates=array_values(array_unique(array_column($sources,'acquired_on')));
  if(count($dates)===1&&TaxCatalog::documentYear($dates[0]))$out['values']['acquired_on']=$dates[0];
  $quantity=(int)$this->q('SELECT COALESCE(SUM(shelf+lent+blocked),0) FROM inventory_stock WHERE club_id=? AND item_id=?',[$a['club_id'],$item])->fetchColumn();
  // Several pieces or a shared price do not prove one separately depreciable asset.
  if(count($sources)!==1||$quantity!==1||$sources[0]['pool_id']!==null){$out['message']='Mehrere Teile oder Anschaffungen: Kosten des einzelnen abschreibbaren Gegenstands zuerst anhand der Rechnungen abgrenzen.';return $out;}
  $positions=$this->q("SELECT p.sphere,p.gross_cents,p.tax_cents,p.input_tax FROM document_links l JOIN finance_entries e ON e.club_id=l.club_id AND e.id=l.source_id JOIN accounting_positions p ON p.club_id=e.club_id AND p.entry_id=e.id WHERE l.club_id=? AND l.document_id=? AND l.source_type='entry' AND e.kind='expense' AND e.status<>'cancelled'",[$a['club_id'],$sources[0]['document_id']])->fetchAll();
  if(count($positions)===1&&(int)$sources[0]['source_ordinal']===1&&(int)$positions[0]['gross_cents']===(int)$sources[0]['gross_cents']){
   $position=$positions[0];$sphere=TaxCatalog::sphereOf((string)$position['sphere']);if(in_array($sphere,['1','2','3','4'],true))$out['values']['sphere']=$sphere;
   if(in_array($position['input_tax'],['none','deductible'],true)){$out['values']['basis']=number_format(((int)$position['gross_cents']-($position['input_tax']==='deductible'?(int)$position['tax_cents']:0))/100,2,',','');$out['message']='Kosten und Vereinsbereich aus der eindeutig zugehörigen Rechnungsbuchung vorausgefüllt. Für den Gegenstand prüfen.';return $out;}
  }
  $tax=(new Settings($this->db,$this->actor))->section('tax')['data'];
  if(($tax['input_tax_eligibility']??'')==='no'&&(int)$sources[0]['gross_cents']>0){$out['values']['basis']=number_format((int)$sources[0]['gross_cents']/100,2,',','');$out['message']='Bruttobetrag aus der Rechnung vorausgefüllt. Kosten und Vorsteuerbehandlung im Anschaffungsjahr vor dem Speichern prüfen.';}
  else $out['message']='Rechnungsbetrag vorhanden. Den tatsächlich abziehbaren Vorsteueranteil und die Anschaffungskosten zuerst prüfen.';
  return $out;
 }
 public static function plan(array $input):array{
  $method=$input['method']??'linear_monthly';
  if($method===OriginalSubstance::METHOD)return OriginalSubstance::input($input);
  if(($input['account']??null)==='02250')throw new DomainException('Grundstück mit Substanzverzehr benötigt den ursprünglichen Mengenplan.');
  if($method===OwnIntangiblePlans::METHOD)return OwnIntangiblePlans::input($input);
  if($method===PartnershipBookPlans::METHOD)return PartnershipBookPlans::input($input);
  if(!in_array($method,['linear_monthly','gwg_immediate','degressive','cost'],true))throw new DomainException('Abschreibungsmethode prüfen.');
  if($method!=='cost'&&in_array($input['account']??'',AssetTypes::codes('cost'),true))throw new DomainException('Nicht planmäßig abzuschreibende Anlage nicht mit einer künstlichen Nutzungsdauer abschreiben. Kostenbewertung wählen.');
  if($method==='cost'){
   foreach(['basis','acquired_on','sphere','account','reason','valuation_kind'] as $field)if(!is_string($input[$field]??null))throw new DomainException('Ursprüngliche Anschaffung und Bewertungsart vollständig eingeben.');
   $plan=['basis_cents'=>Finance::cents($input['basis']),'residual_cents'=>0,'acquired_on'=>$input['acquired_on'],'months'=>0,'sphere'=>$input['sphere'],
    'account'=>$input['account'],'reason'=>trim($input['reason']),'method'=>'cost','valuation_kind'=>$input['valuation_kind'],'valuation_document'=>$input['valuation_document']??null];
   if(($input['valuation_target']??'')!=='')$plan['target']=$input['valuation_target'];
   if(($input['financial_profile']??'')!=='')$plan['financial_profile']=$input['financial_profile'];
   if(in_array($plan['account'],FinancialInvestment::CODES,true)){$target=FinancialInvestment::target($plan['account'],$plan['financial_profile']??null);if(isset($plan['target'])&&$plan['target']!==$target)throw new DomainException('Bestätigter Sitz und Bilanzposition der Beteiligung stimmen nicht überein.');$plan['target']=$target;}
   AssetValuation::fromBookPlan($plan);
   if($plan['acquired_on']>date('Y-m-d')||!in_array($plan['account'],AssetTypes::codes($plan['valuation_kind']),true))throw new DomainException('Geprüfte nicht planmäßig abzuschreibende Anlagenart aus dem Anschaffungsjahr auswählen.');
   if(($input['asset_reviewed']??null)!=='1')throw new DomainException('Ursprüngliche Kosten und die nicht planmäßige Abschreibung ausdrücklich bestätigen.');
   if(($input['depreciation_code']??'')!=='')throw new DomainException('Kostenanlage hat kein regelmäßiges Abschreibungskonto.');
   return $plan;
  }
  if($method==='gwg_immediate'){
   if(($input['depreciation_code']??'')!=='')throw new DomainException('Sofortabschreibung bleibt auf dem ursprünglichen GWG-Gegenkonto.');
   foreach(['basis','acquired_on','sphere','reason'] as $key)if(!is_string($input[$key]??null))throw new DomainException('GWG-Angaben vollständig eingeben.');
   $basis=Finance::cents($input['basis']);$date=$input['acquired_on'];$reason=trim($input['reason']);
   if($date>date('Y-m-d')||!isset(TaxCatalog::SPHERES[$input['sphere']])||$input['sphere']==='9'||$reason===''||mb_strlen($reason)>2000)throw new DomainException('Anschaffung, Vereinsbereich und Nachweis prüfen.');
   if(!in_array($input['account']??'',['','06700'],true))throw new DomainException('Für die Sofortabschreibung Anlagekonto 06700 verwenden.');
   return ['basis_cents'=>$basis,'residual_cents'=>0,'acquired_on'=>$date,'months'=>1,'sphere'=>$input['sphere'],'account'=>'06700','reason'=>$reason,'method'=>$method,'gwg'=>GwgDepreciation::input($input,$basis,$date)];
  }
  $input=AssetBookSuggestions::confirm($input);
  foreach(['basis','residual','acquired_on','months','sphere','account','reason'] as $k)if(!is_string($input[$k]??null))throw new DomainException('Buchwertangaben vollständig eingeben.');$basis=Finance::cents($input['basis']);$residual=Finance::cents($input['residual']);$date=$input['acquired_on'];$year=TaxCatalog::documentYear($date);$months=filter_var($input['months'],FILTER_VALIDATE_INT);$reason=trim($input['reason']);if(!$year||$date>date('Y-m-d')||$basis<=0||$basis>100000000000||$residual>=$basis||$months===false||$months<12||$months>1200||$months%12!==0||$reason===''||mb_strlen($reason)>2000)throw new DomainException('Anschaffung, positive Bemessungsgrundlage, Restwert und volle Nutzungsjahre prüfen. Die fachliche Begründung darf nicht fehlen.');if(!isset(TaxCatalog::SPHERES[$input['sphere']])||$input['sphere']==='9')throw new DomainException('Geklärten Vereinsbereich wählen.');
  $plan=['basis_cents'=>$basis,'residual_cents'=>$residual,'acquired_on'=>$date,'months'=>$months,'sphere'=>$input['sphere'],'account'=>$input['account'],'reason'=>$reason,'method'=>$method];
  if(($input['depreciation_code']??'')!=='')$plan['depreciation_code']=$input['depreciation_code'];
  if(($plan['depreciation_code']??null)==='62500')$plan['original_leasing']=OriginalLeasing::input($input);
  AssetOriginalDepreciation::code($plan);
  if($method==='degressive')$plan['degressive']=DegressiveDepreciation::input($input,$plan);
  return $plan;
 }
 public function save(string $item,int $version,array $input):void{
  $a=$this->access(true);
  if(($input['method']??null)===OwnIntangiblePlans::METHOD)$input['own_source']=OwnIntangiblePlans::formSource($this->db,$this->actor,$item,$input,false);
  if(($input['method']??null)===PartnershipBookPlans::METHOD){
   $input['holding_id']=$item;$input['club_id']=$a['club_id'];$e=new AssetValuationEvidence($this->db,$this->actor);
   $input['partnership_document']=$e->reference((string)($input['partnership_document_id']??''));
   $input['partnership_relationship_document']=$e->reference((string)($input['partnership_relationship_document_id']??''));
  }
  if(($input['method']??null)==='cost')$input['valuation_document']=(new AssetValuationEvidence($this->db,$this->actor))->reference((string)($input['valuation_document_id']??''));
  if(($input['depreciation_code']??null)==='62500')$input['lease_document']=(new AssetValuationEvidence($this->db,$this->actor))->reference((string)($input['lease_document_id']??''));
  if(($input['method']??null)===OriginalSubstance::METHOD){$e=new AssetValuationEvidence($this->db,$this->actor);foreach(['acquisition','estimate'] as $k){OriginalSubstance::need(is_string($input['substance_'.$k.'_document_id']??null));$input['substance_'.$k.'_document']=$e->reference($input['substance_'.$k.'_document_id']);}}
  $data=self::plan($input);$this->db->beginTransaction();
  try{
   $this->lockWriter($a);$old=$this->get($item);
   if($data['method']===PartnershipBookPlans::METHOD)PartnershipBookPlans::evidence($this->db,$this->actor,$data,null,true);
   if(isset($data['original_leasing']))(new AssetValuationEvidence($this->db,$this->actor))->check($data['original_leasing']['document'],true);
   if($data['method']===OriginalSubstance::METHOD){OriginalSubstance::need($data['acquired_on']<=date('Y-m-d'));$e=new AssetValuationEvidence($this->db,$this->actor);foreach(['acquisition_document','estimate_document'] as $k)$e->check($data[$k],true);}
   if($data['method']==='cost')(new AssetValuationEvidence($this->db,$this->actor))->check($data['valuation_document'],true);
   if($old['item']['ownership']!=='club'||$old['item']['cash_account']!==null)throw new DomainException('Nur eigene Sachanlagen können abgeschrieben werden.');
   if($old['version']!==$version)throw new DomainException('Buchwertplan wurde inzwischen geändert. Neu laden.');
   if($data['method']===OwnIntangiblePlans::METHOD){
    if((int)$old['item']['active']!==1)throw new DomainException('Aktiven eigenen Projektgegenstand auswählen.');
    $proof=OwnIntangibleOriginalSources::calculate(OwnIntangiblePlans::original($data));
    (new OwnIntangibleEvidence($this->db,$this->actor))->checkSources($proof,true);OwnIntangiblePlans::allocation($this->db,$this->actor,$proof,true);
   }
   if($this->q("SELECT 1 FROM balance_period_sources WHERE club_id=? AND source_type='depreciation' AND SUBSTRING_INDEX(source_id,':',1)=? LIMIT 1",[$a['club_id'],$item])->fetchColumn())throw new DomainException('Abschreibungsplan gehört zu einem bestätigten Abschlusszeitraum. Keine rückwirkende Änderung des Originals.');
   if($old['years'])throw new DomainException('Für diesen Plan sind Jahresstände festgestellt. Diese bleiben unveränderlich; keine rückwirkende Änderung der Abschreibungsgrundlage.');
   if($data['method']==='gwg_immediate'){
    $stock=$this->q('SELECT shelf,lent,blocked FROM inventory_stock WHERE club_id=? AND item_id=? FOR UPDATE',[$a['club_id'],$item])->fetchAll();
    $quantity=0;foreach($stock as $row)$quantity+=(int)$row['shelf']+(int)$row['lent']+(int)$row['blocked'];
    if($quantity!==1)throw new DomainException('GWG als einzelnen Gegenstand mit Bestand 1 erfassen. Mehrere Stücke zuerst einzeln zuordnen.');
   }
   $year=TaxCatalog::documentYear($data['acquired_on']);
   if($data['account']!==''){
    $r=(new Finance($this->db,$this->actor))->taxAccounts($year)[$data['account']]??null;
    if(!$r||!$r['active']||$r['category']!=='fixed')throw new DomainException('Ein aktives Anlagekonto aus dem Anschaffungsjahr auswählen; für Jahre ohne geprüften Kontenplan leer lassen.');
   }
   $json=json_encode($data,JSON_THROW_ON_ERROR);$now=gmdate('c');
   $this->q('INSERT INTO asset_book_plans VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE version=VALUES(version),payload=VALUES(payload),updated_at=VALUES(updated_at)',[$a['club_id'],$item,$version+1,$json,$a['id'],$now]);
   $this->q('INSERT INTO asset_book_history VALUES(?,?,?,?,?,?,?)',[$a['club_id'],$item,$version+1,$a['id'],$now,$data['reason'],$json]);
   $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,\'finance.asset.plan\',?,?,?)',[$a['club_id'],$a['id'],$item,$version+1,$now]);
   $this->db->commit();
  }catch(\Throwable $e){$this->db->rollBack();throw $e;}
 }
 public static function schedule(array $data,int $year,int $throughMonth=12):array{
  if(($data['method']??null)===OwnIntangiblePlans::METHOD){OwnIntangiblePlans::original($data);throw new DomainException('Selbst geschaffene Werte aus dem bestätigten HGB-Jahresregister lesen. Unbestätigte Folgejahre bleiben offen.');}
  if(($data['method']??null)===OriginalSubstance::METHOD){OriginalSubstance::plan($data);OriginalSubstance::need($year===TaxCatalog::documentYear($data['acquired_on'])&&$throughMonth===12);return ['year'=>$year,'months'=>0,'opening_cents'=>$data['basis_cents'],'depreciation_cents'=>0,'closing_cents'=>$data['basis_cents']];}
  AssetOriginalDepreciation::code($data);
  if($throughMonth<1||$throughMonth>12)throw new DomainException('Abschreibungsmonat prüfen.');
  if(($data['method']??null)===PartnershipBookPlans::METHOD){
   $p=PartnershipBookPlans::original($data);$start=(int)substr($p['acquired_on'],0,4);
   if($year!==$start||$throughMonth!==12)throw new DomainException('HGB-Beteiligungswert anhand des ursprünglichen bestätigten Jahresregisters lesen; keinen späteren Buchwert aus den Anschaffungskosten ableiten.');
   return ['year'=>$year,'months'=>0,'opening_cents'=>0,'depreciation_cents'=>0,'closing_cents'=>$p['basis_cents']];
  }
  if(($data['method']??null)==='cost'){
   $plan=AssetValuation::fromBookPlan($data);if($year<1900||$year>2199)throw new DomainException('Jahr prüfen.');
   return ['year'=>$year,'months'=>0,'opening_cents'=>$year>=(int)substr($plan['acquired_on'],0,4)?$plan['basis_cents']:0,'depreciation_cents'=>0,
    'closing_cents'=>$year>(int)substr($plan['acquired_on'],0,4)||($year===(int)substr($plan['acquired_on'],0,4)&&$throughMonth>=(int)substr($plan['acquired_on'],5,2))?$plan['basis_cents']:0];
  }
  if(in_array($data['account']??'',AssetTypes::codes('cost'),true))throw new DomainException('Nicht planmäßig abzuschreibende Anlage benötigt ihren Bewertungsnachweis.');
  if(!in_array($data['method']??'linear_monthly',['linear_monthly','gwg_immediate','degressive'],true))throw new DomainException('Unbekannte Abschreibungsmethode.');
  if(!is_int($data['basis_cents']??null)||$data['basis_cents']<=0||$data['basis_cents']>BalanceCalculation::LIMIT
     ||!is_int($data['residual_cents']??null)||$data['residual_cents']<0||$data['residual_cents']>=$data['basis_cents']
     ||!is_int($data['months']??null)||!is_string($data['acquired_on']??null)||!TaxCatalog::documentYear($data['acquired_on'])
     ||(($data['method']??'linear_monthly')!=='gwg_immediate'&&($data['months']<12||$data['months']>1200||$data['months']%12!==0)))
   throw new DomainException('Gespeicherten Abschreibungsplan prüfen.');
  if(($data['method']??'')==='degressive')return DegressiveDepreciation::schedule($data,$year,$throughMonth);
  if(($data['method']??'linear_monthly')==='gwg_immediate'){
   GwgDepreciation::check($data['gwg']??null,$data['basis_cents'],$data['acquired_on']);
   if($year<1900||$year>2200||$data['months']!==1||$data['residual_cents']!==0||$data['account']!=='06700')throw new DomainException('GWG-Plan prüfen.');
   $start=(int)substr($data['acquired_on'],0,4);$basis=$data['basis_cents'];
   return ['year'=>$year,'months'=>$year===$start?1:0,'opening_cents'=>$year<=$start?$basis:0,'depreciation_cents'=>$year===$start?$basis:0,'closing_cents'=>$year<$start?$basis:0];
  }
  if($year<1900||$year>2200)throw new DomainException('Jahr prüfen.');
  $startYear=(int)substr($data['acquired_on'],0,4);$startMonth=(int)substr($data['acquired_on'],5,2);
  if(($data['account']??null)==='01500'&&($data['residual_cents']!==0||($data['method']??'linear_monthly')!=='linear_monthly'))throw new DomainException('Erworbener Geschäfts- oder Firmenwert: linearen Buchplan ohne Restwert anhand der Belege bestätigen.');
  $months=$data['months'];$basis=$data['basis_cents'];$depreciable=$basis-$data['residual_cents'];
  $before=max(0,min($months,($year-$startYear)*12-($startMonth-1)));
  $through=max(0,min($months,($year-$startYear)*12+$throughMonth-($startMonth-1)));
  $cumulative=static fn($n)=>intdiv($depreciable*$n+intdiv($months,2),$months);
  $opening=$basis-$cumulative($before);$closing=$basis-$cumulative($through);
  return ['year'=>$year,'months'=>$through-$before,'opening_cents'=>$opening,'depreciation_cents'=>$opening-$closing,'closing_cents'=>$closing];
 }
 public function recordYear(string $item,int $version,int $year,array $input=[]):void{
  $a=$this->access(true);$this->db->beginTransaction();
  try{
   $this->lockWriter($a);$p=$this->get($item);
   if($this->q('SELECT 1 FROM balance_periods WHERE club_id=? AND starts_on<=? AND ends_on>=? LIMIT 1',[$a['club_id'],$year.'-12-31',$year.'-01-01'])->fetchColumn())throw new DomainException('Für diesen Verein bestehen Abschlusszeiträume. Abschreibung dort prüfen; keine überlappenden Kalenderjahreswerte anlegen.');
   if($this->q("SELECT 1 FROM balance_period_sources WHERE club_id=? AND source_type='depreciation' AND SUBSTRING_INDEX(source_id,':',1)=? LIMIT 1",[$a['club_id'],$item])->fetchColumn())throw new DomainException('Diese Anlage wird in Abschlusszeiträumen fortgeführt. Keinen zusätzlichen Kalenderjahresstand anlegen.');
   if(!$p['data']||$p['version']!==$version)throw new DomainException('Plan geändert oder fehlt.');
   $start=(int)substr($p['data']['acquired_on'],0,4);
   foreach($p['years'] as $prior)if(isset(json_decode($prior['snapshot'],true,512,JSON_THROW_ON_ERROR)['exceptional']))throw new DomainException('AfaA: Folgejahre benötigen einen eigenständig geprüften Wertvortrag.');
   if($year<$start||$year>=(int)date('Y'))throw new DomainException('Nur abgeschlossene Kalenderjahre ab Anschaffung feststellen.');
   $last=$p['years']?(int)end($p['years'])['year']:$start-1;
   if($year!==$last+1)throw new DomainException('Jahresstände lückenlos in zeitlicher Reihenfolge feststellen.');
   if(($p['data']['method']??null)===OriginalSubstance::METHOD){
    OriginalSubstance::need(!$p['years']&&($input['disposal_kind']??'')===''&&($input['exceptional_code']??'')==='');
    if($this->q("SELECT 1 FROM balance_years WHERE club_id=? AND tax_year>=? AND state='closed' LIMIT 1",[$a['club_id'],$year])->fetchColumn())throw new DomainException('Festgeschriebenen Buchstand nicht durch späteren Substanzverzehr ändern.');
    $e=new AssetValuationEvidence($this->db,$this->actor);foreach(['acquisition_document','estimate_document'] as $k)$e->check($p['data'][$k],true);$v=OriginalSubstance::annual($p['data'],$year,$input,$e);$snapshot=['plan'=>$p['data'],'substance'=>$v];
    $this->q('INSERT INTO asset_book_years VALUES(?,?,?,?,?,?,?,?,?,?)',[$a['club_id'],$item,$year,$version,$v['opening_cents'],$v['depreciation_cents'],$v['closing_cents'],$a['id'],gmdate('c'),json_encode($snapshot,JSON_THROW_ON_ERROR)]);
    $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,\'finance.asset.substance-year\',?,?,?)',[$a['club_id'],$a['id'],$item,$year,gmdate('c')]);$this->db->commit();return;
   }
   if(($input['exceptional_code']??'')==='')foreach(['exceptional_book','exceptional_tax','exceptional_book_reason','exceptional_tax_reason','exceptional_document_id','exceptional_book_reviewed','exceptional_tax_reviewed'] as $key)if(($input[$key]??'')!=='')throw new DomainException('AfaA-Gegenkonto und getrennte vollständige Jahresentscheidung auswählen.');
   if(($input['exceptional_code']??'')!==''&&($p['data']['method']??null)!=='linear_monthly')throw new DomainException('AfaA nur anhand des ursprünglichen linearen Einzelplans bestätigen.');
   if(($p['data']['method']??null)===OwnIntangiblePlans::METHOD){
    $previous=null;
    if($p['years']){$prior=json_decode(end($p['years'])['snapshot'],true,512,JSON_THROW_ON_ERROR);if($prior['plan']!==$p['data'])throw new DomainException('Ursprünglichen Entwicklungsplan unverändert fortführen.');$previous=OwnIntangiblePlans::snapshot($prior);}
    $value=OwnIntangiblePlans::annual($this->db,$this->actor,$p['data'],$year,$input,$previous);
    if($this->q("SELECT 1 FROM balance_years WHERE club_id=? AND tax_year>=? AND state='closed' LIMIT 1",[$a['club_id'],$year])->fetchColumn())throw new DomainException('Festgeschriebene Bilanzjahre nicht nachträglich verändern.');
    $v=$value['hgb'];$snapshot=['plan'=>$p['data'],'own_book'=>$value];
    $this->q('INSERT INTO asset_book_years VALUES(?,?,?,?,?,?,?,?,?,?)',[$a['club_id'],$item,$year,$version,$v['book_opening_cents']+$v['additions_cents'],$v['amortisation_cents'],$v['book_closing_cents'],$a['id'],gmdate('c'),json_encode($snapshot,JSON_THROW_ON_ERROR)]);
    $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,\'finance.asset.own-intangible-year\',?,?,?)',[$a['club_id'],$a['id'],$item,$year,gmdate('c')]);
    $this->db->commit();return;
   }
   if(($p['data']['method']??null)===PartnershipBookPlans::METHOD){
    if(($input['partnership_year_reviewed']??null)!=='1'||($input['disposal_kind']??'')!=='')throw new DomainException('Ursprüngliche HGB-Ereignisse und Jahresstand der Beteiligung ausdrücklich bestätigen.');
    $plan=PartnershipBookPlans::original($p['data']);$previous=null;
    if($p['years'])$previous=PartnershipBookPlans::snapshot(json_decode(end($p['years'])['snapshot'],true,512,JSON_THROW_ON_ERROR));
    $events=PartnershipBookPlans::formEvents($this->db,$this->actor,$input,true);
    $value=PartnershipBookBasis::calculate(['schema'=>1,'plan'=>$plan,'period'=>BalancePeriod::calendar($year),'events'=>$events],$previous);
    PartnershipBookPlans::evidence($this->db,$this->actor,$p['data'],$value,true);
    if($this->q("SELECT 1 FROM balance_years WHERE club_id=? AND tax_year>=? AND state='closed' LIMIT 1",[$a['club_id'],$year])->fetchColumn())throw new DomainException('Festgeschriebene Bilanzjahre nicht durch eine nachträgliche HGB-Bewertung verändern.');
    $v=$value['book'];$snapshot=['plan'=>$p['data'],'partnership_book'=>$value];
    $this->q('INSERT INTO asset_book_years VALUES(?,?,?,?,?,?,?,?,?,?)',[$a['club_id'],$item,$year,$version,$v['book_opening_cents']+$v['additions_cents'],0,$v['book_closing_cents'],$a['id'],gmdate('c'),json_encode($snapshot,JSON_THROW_ON_ERROR)]);
    $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,\'finance.asset.partnership-year\',?,?,?)',[$a['club_id'],$a['id'],$item,$year,gmdate('c')]);
    $this->db->commit();return;
   }
   if(($p['data']['method']??null)==='cost'){
    $plan=AssetValuation::fromBookPlan($p['data']);$events=(new AssetValuationEvidence($this->db,$this->actor))->formEvents($input,true);$previous=null;
    if($p['years']){
     $lastRow=end($p['years']);$lastSnapshot=json_decode($lastRow['snapshot'],true,512,JSON_THROW_ON_ERROR);
     if(($lastSnapshot['plan']??null)!==$p['data']||!is_array($lastSnapshot['valuation']??null))throw new DomainException('Unveränderten Bewertungsvortrag nachweisen.');
     $previous=$lastSnapshot['valuation'];
    }
    if(($input['disposal_kind']??'')!=='')throw new DomainException('Abgänge nicht zusätzlich als Abschreibung erfassen. Im Bewertungsverlauf dokumentieren.');
    $valuation=AssetValuation::calculate($plan,BalancePeriod::calendar($year),$events,$previous);
    (new AssetValuationEvidence($this->db,$this->actor))->valuation($valuation,true);
    if($this->q("SELECT 1 FROM balance_years WHERE club_id=? AND tax_year>=? AND state='closed' LIMIT 1",[$a['club_id'],$year])->fetchColumn())throw new DomainException('Festgeschriebene Bilanzjahre nicht durch eine nachträgliche Anlagenbewertung verändern.');
    $v=$valuation['book'];$snapshot=['plan'=>$p['data'],'valuation'=>$valuation];
    $this->q('INSERT INTO asset_book_years VALUES(?,?,?,?,?,?,?,?,?,?)',[$a['club_id'],$item,$year,$version,$v['book_opening_cents']+$v['additions_cents'],0,$v['book_closing_cents'],$a['id'],gmdate('c'),json_encode($snapshot,JSON_THROW_ON_ERROR)]);
    $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,\'finance.asset.valuation\',?,?,?)',[$a['club_id'],$a['id'],$item,$year,gmdate('c')]);
    $this->db->commit();return;
   }
   if(($input['exceptional_code']??'')!==''){
    require_once __DIR__.'/OriginalExceptionalDepreciation.php';
    if(($input['disposal_kind']??'')!==''||$p['years'])throw new DomainException('AfaA: anfänglichen Kalenderabschluss ohne Abgang oder Vortrag separat bestätigen.');
    $e=new AssetValuationEvidence($this->db,$this->actor);$proof=OriginalExceptionalDepreciation::input($input,$year,$e);$v=OriginalExceptionalDepreciation::calculate($p['data'],$proof);$e->check($proof['document'],true);
    if($this->q("SELECT 1 FROM balance_years WHERE club_id=? AND tax_year>=? AND state='closed' LIMIT 1",[$a['club_id'],$year])->fetchColumn())throw new DomainException('Festgeschriebenen Buchstand nicht durch eine spätere AfaA ändern.');
    $snapshot=['plan'=>$p['data'],'exceptional'=>$v];
    $this->q('INSERT INTO asset_book_years VALUES(?,?,?,?,?,?,?,?,?,?)',[$a['club_id'],$item,$year,$version,$v['opening_cents'],$v['depreciation_cents'],$v['closing_cents'],$a['id'],gmdate('c'),json_encode($snapshot,JSON_THROW_ON_ERROR)]);
    $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,\'finance.asset.exceptional-year\',?,?,?)',[$a['club_id'],$a['id'],$item,$year,gmdate('c')]);
    $this->db->commit();return;
   }
   foreach(['exceptional_book','exceptional_tax','exceptional_book_reason','exceptional_tax_reason','exceptional_document_id','exceptional_book_reviewed','exceptional_tax_reviewed'] as $key)if(($input[$key]??'')!=='')throw new DomainException('AfaA-Gegenkonto und getrennte vollständige Jahresentscheidung auswählen.');
   $snapshot=$p['data'];$proof=null;
   foreach($p['years'] as $previous){
    [$previousPlan,$ended]=AssetDisposal::unpack(json_decode($previous['snapshot'],true,64,JSON_THROW_ON_ERROR));
    if(!$ended)continue;
    if(($ended['kind']??'')!=='partial_scrap')throw new DomainException('Der Anlagenabgang ist bereits abgeschlossen. Keine weitere Abschreibung.');
    if($previousPlan!==$p['data']||($proof!==null&&$proof!==$ended))throw new DomainException('Teilabgangsverlauf stimmt nicht mit dem Anlagenplan überein.');
    $proof=$ended;
    $sources=new BalanceSources($this->db,$this->actor);$sourceId=$item.':'.$previous['year'];
    $binding=$sources->binding('depreciation',$sourceId);
    if((int)$previous['depreciation_cents']>0||(int)$previous['year']===(int)substr($proof['date'],0,4)){
     $check=$sources->preview((int)$previous['year'],'depreciation',$sourceId,[],true);
     if(!$binding||$check['issues']||!hash_equals($binding['source_hash'],$check['hash']))throw new DomainException('Vorherigen Teilabgang und Abschreibung zuerst vollständig in die Bilanz übernehmen.');
     (new BalanceSourceHistory($this->db,$this->actor))->checked($binding);
    }
   }
   if($proof&&($input['disposal_kind']??'')!=='')throw new DomainException('Ein weiterer Abgang nach dem Teilabgang muss gesondert fachlich geklärt werden.');
   if(($input['disposal_kind']??'')==='sale'){
    if(!is_string($input['disposal_reason']??null))throw new DomainException('Grund des Anlagenverkaufs angeben.');
    $proof=AssetSale::prepare($this->db,$this->actor,$p['data'],$input);
   }elseif(($input['disposal_kind']??'')!==''){
    foreach(['disposal_kind','disposal_date','disposal_document','disposal_reason','disposal_confirmed'] as $field)if(!is_string($input[$field]??null))throw new DomainException('Ausmusterungsangaben vollständig eingeben.');
    if(!in_array($input['disposal_kind'],['scrap','software_end'],true)||$input['disposal_confirmed']!=='1')throw new DomainException('Vollständigen Abgang ohne Erlös ausdrücklich bestätigen.');
    $software=$input['disposal_kind']==='software_end';
    if($software&&($input['software_rights_ended']??null)!=='1')throw new DomainException('Das endgültige Ende aller Lizenzrechte ausdrücklich bestätigen. Bloße Nichtnutzung reicht nicht.');
    $document=(new Documents($this->db,$this->actor))->document((string)($input['disposal_document']??''),true);
    if($document['state']!=='active'||!in_array('finance',$document['scopes'],true))throw new DomainException('Aktiven Finanzbeleg als Ausmusterungsnachweis auswählen.');
    $file=$this->q('SELECT revision,sha256,SHA2(bytes,256) actual_sha256 FROM document_files WHERE club_id=? AND document_id=? ORDER BY revision DESC LIMIT 1',[$a['club_id'],$document['id']])->fetch();
    if(!$file)throw new DomainException('Datei zum Ausmusterungsnachweis fehlt.');
    if($software&&!hash_equals($file['sha256'],$file['actual_sha256']))throw new DomainException('Datei zum Lizenzende ist beschädigt. Originalnachweis prüfen.');
    $proof=['kind'=>$input['disposal_kind'],'date'=>$input['disposal_date']??'','reason'=>trim((string)($input['disposal_reason']??'')),
      'document_id'=>$document['id'],'revision'=>(int)$file['revision'],'sha256'=>$file['sha256'],'no_proceeds'=>true,'complete'=>true];
    if($software)$proof['rights_ended']=true;
   }
   if($proof){
    AssetDisposal::check($p['data'],$proof);
    if($proof['kind']!=='partial_scrap'&&TaxCatalog::documentYear($proof['date'])!==$year)throw new DomainException('Abgangsdatum muss im ausgewählten Jahr liegen.');
    if($this->q("SELECT 1 FROM balance_years WHERE club_id=? AND tax_year>=? AND state='closed' LIMIT 1",[$a['club_id'],$year])->fetchColumn())throw new DomainException('Bereits festgeschriebene Bilanzjahre dürfen nicht durch einen nachträglichen Anlagenabgang verändert werden.');
    $snapshot=['plan'=>$p['data'],'disposal'=>$proof];
   }
   $r=AssetDisposal::schedule($p['data'],$year,$proof);
   $this->q('INSERT INTO asset_book_years VALUES(?,?,?,?,?,?,?,?,?,?)',[$a['club_id'],$item,$year,$version,$r['opening_cents'],$r['depreciation_cents'],$r['closing_cents'],$a['id'],gmdate('c'),json_encode($snapshot,JSON_THROW_ON_ERROR)]);
   if(($proof['kind']??'')==='sale')$this->q('INSERT INTO asset_sale_links(club_id,item_id,year,entry_id) VALUES(?,?,?,?)',[$a['club_id'],$item,$year,$proof['sale_entry']]);
   $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,\'finance.asset.year\',?,?,?)',[$a['club_id'],$a['id'],$item,$year,gmdate('c')]);
   $this->db->commit();
  }catch(\Throwable $e){$this->db->rollBack();throw $e;}
 }
}
