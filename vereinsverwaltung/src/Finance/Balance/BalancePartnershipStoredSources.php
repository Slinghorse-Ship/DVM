<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
require_once __DIR__.'/BalanceReadSnapshot.php';
require_once __DIR__.'/BalanceWholeClubReport.php';
require_once dirname(__DIR__, 2).'/Finance/Assets/PartnershipBookPlans.php';
require_once dirname(__DIR__, 2).'/Finance/Assets/PartnershipHoldingEvidence.php';
/** Bind reviewed original sources to actual immutable DB journals and registers. */
final class BalancePartnershipStoredSources
{
 public function __construct(private PDO $db,private string $actor){}
 public function check(array $report,array $review,?array $carry=null,int $depth=0):void{
  if(empty($review['partnership_holdings']))return;
  BalanceReadSnapshot::run($this->db,function()use($report,$review,$carry,$depth){$this->read($report,$review,$carry,$depth);});
 }
 private function read(array $report,array $review,?array $carry,int $depth):void{
  if($depth>100||!isset($report['whole_club_scope'])||isset($report['period'])||!is_array($review['partnership_holdings'])||count($review['partnership_holdings'])>200)throw new DomainException('Ursprüngliche Beteiligungsprüfung benötigt den vollständigen Kalenderabschluss.');
  $a=Permissions::actor($this->db,$this->actor);if(!in_array($a['finance_access'],['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.');
  $actual=BalanceWholeClubReport::fromReport((new BalanceLedger($this->db,$this->actor))->report($report['year']));
  if($actual!==$report)throw new DomainException('Beteiligungsprüfung stimmt nicht mit dem tatsächlich gespeicherten unveränderten Abschluss überein.');
  $previous=null;
  if($report['previous_sha256']!==null){
   if(!is_string($carry['filing_id']??null))throw new DomainException('Unveränderte bestätigte Vorjahresabgabe für die Beteiligungsprüfung übernehmen.');
   $r=(new TaxFilings($this->db,$this->actor))->get($carry['filing_id']);$b=$r['form_data']['balance_preparation']??null;
   if($r['state']!=='sent_test'||$r['environment']!=='test'||$r['family']!=='balance'||$r['data_version']!=='Bilanz_6_9'||(int)$r['tax_year']!==$report['year']-1||!is_array($b)||($b['journal_sha256']??null)!==$report['previous_sha256']||($b['profile']['kind']??null)!=='whole_taxable_club')throw new DomainException('Vorjahresabgabe und ursprünglicher Beteiligungsabschluss gehören nicht zusammen.');
   // Reuse the existing approval/receipt/carry boundary; no new weaker approval.
   (new BalanceTaxFiling($this->db,$this->actor))->assertCurrent($r);
   $previous=$b['review']['partnership_holdings']??null;if(!is_array($previous))throw new DomainException('Ursprüngliche bestätigte Beteiligungsnachweise des Vorjahrs fehlen.');
  }
  foreach($review['partnership_holdings'] as $id=>$m){
   if(!is_array($m)||($m['journal_proof']['journal']['holding_id']??null)!==$id||($m['journal_proof']['journal']['club_id']??null)!==$a['club_id'])throw new DomainException('Ursprüngliche Beteiligung gehört zu einem anderen Verein.');
   (new PartnershipHoldingEvidence($this->db,$this->actor))->checkMatrix($m);
   if($report['previous_sha256']===null){if($m['previous']!==null||$m['journal_proof']['previous']!==null)throw new DomainException('Eine Anfangsübernahme darf kein nicht gespeichertes Anwendungs-Vorjahresjournal behaupten.');}
   elseif(($previous[$id]??null)!==$m['previous'])throw new DomainException('Vorjahresmatrix unverändert aus genau der bestätigten gespeicherten Abgabe übernehmen.');
   $j=$m['journal_proof']['journal'];
   if($j['year']!==$report['year']||$j['chart']!==$report['accounts']||$j['result_account']!==$report['result_account']||$j['book_opening']!==$report['opening']||$j['book_entries']!==$report['entries'])throw new DomainException('Jede Originaljournalzeile einschließlich ihrer gespeicherten Herkunft unverändert übernehmen.');
   $plan=DocumentCatalog::query($this->db,'SELECT version,payload FROM asset_book_plans WHERE club_id=? AND item_id=?',[$a['club_id'],$id])->fetch();
   if(!$plan)throw new DomainException('Gespeicherter HGB-Beteiligungsplan fehlt.');$data=json_decode($plan['payload'],true,512,JSON_THROW_ON_ERROR);$p=PartnershipBookPlans::original($data);
   $sources=$m['journal_proof']['sources'];if($p!==$sources['book_basis']['source']['plan']||$data['partnership_relationship']!==$sources['relationship'])throw new DomainException('HGB-Plan und Beziehung wurden gegenüber dem gespeicherten Original verändert.');
   $book=$sources['book_basis'];$registerDepth=0;
   do{
    if(++$registerDepth>101)throw new DomainException('Zu viele ursprüngliche HGB-Jahresstände.');$period=$book['source']['period'];$year=(int)substr($period['end'],0,4);
    if($period!==BalancePeriod::calendar($year))throw new DomainException('Gespeichertes Kalenderregister nicht als anderen Abschlusszeitraum ausgeben.');
    $row=DocumentCatalog::query($this->db,'SELECT * FROM asset_book_years WHERE club_id=? AND item_id=? AND year=?',[$a['club_id'],$id,$year])->fetch();
    if(!$row||(int)$row['plan_version']!==(int)$plan['version']||PartnershipBookPlans::snapshot(json_decode($row['snapshot'],true,512,JSON_THROW_ON_ERROR))!==$book)throw new DomainException('Jeden HGB-Vortrag anhand des tatsächlich gespeicherten Originalregisters prüfen.');
    foreach(['opening_cents'=>$book['book']['book_opening_cents']+$book['book']['additions_cents'],'depreciation_cents'=>0,'closing_cents'=>$book['book']['book_closing_cents']] as $name=>$n)if((int)$row[$name]!==$n)throw new DomainException('Gespeicherter HGB-Jahreswert wurde verändert.');
    $book=$book['previous'];
   }while($book!==null);
   $item=$report['asset_schedule']['items'][$id]??null;
   if(!is_array($item)||($item['method']??null)!==PartnershipBookPlans::METHOD||($item['partnership_book']??null)!==$sources['book_basis']||($item['partnership_plan']??null)!==$data)throw new DomainException('Bestätigtes HGB-Register unverändert im tatsächlich festgeschriebenen Anlagenverzeichnis nachweisen.');
  }
 }
}
