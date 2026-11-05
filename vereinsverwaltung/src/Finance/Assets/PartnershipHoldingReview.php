<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
require_once __DIR__.'/PartnershipHoldingMatrix.php';
require_once __DIR__.'/PartnershipBookPlans.php';
require_once __DIR__.'/AssetValuationEvidence.php';
require_once dirname(__DIR__, 2).'/Finance/Balance/BalanceTaxValues.php';
/** Original fiscal form values; identity, HGB register and journal come from the closed books. */
final class PartnershipHoldingReview
{
 public static function columns():array{
  $meta=json_decode(file_get_contents(dirname(__DIR__, 2).'/../resources/ebilanz/taxonomy-6.9.json'),true,512,JSON_THROW_ON_ERROR);$keys=['all_Prev_period'];
  foreach($meta['asset_calculations'] as $parent=>$children){$keys[]=$parent;foreach($children as $child=>$weight)$keys[]=$child;}return array_values(array_unique($keys));
 }
 public static function amount(mixed $input):int{
  if(!is_string($input)||!preg_match('/^-?\d+(?:[,.]\d{1,2})?$/D',$input))throw new DomainException('Originalbetrag in Euro mit höchstens zwei Nachkommastellen eingeben.');
  $negative=str_starts_with($input,'-');$amount=Finance::cents($negative?substr($input,1):$input);return $negative?-$amount:$amount;
 }
 private static function components(mixed $rows):array{
  if(!is_array($rows)||count($rows)!==3||array_diff(array_keys($rows),PartnershipCapitalBasis::COMPONENTS))throw new DomainException('Kapital-, Ergänzungs- und Sonderanteile einzeln anhand der Originalunterlagen eingeben.');
  $out=[];foreach(PartnershipCapitalBasis::COMPONENTS as $key)$out[$key]=self::amount($rows[$key]);return $out;
 }
 public function __construct(private PDO $db,private string $actor){}
 public function build(array $report,array $review,string $holding,array $input,?array $previous=null):array{
  $a=Permissions::actor($this->db,$this->actor);
  if(!in_array($a['finance_access'],['read','write'],true)||!isset($report['whole_club_scope'])||isset($report['period'])||($report['state']??null)!=='closed')throw new DomainException('Ursprüngliche vollständige Kalenderbilanz und Finanzberechtigung erforderlich.');
  if(array_diff(array_keys($input),['classification','opening','closing','capital_movements','capital_document_id','capital_reason','capital_confirmed','book_links','tax_links','journal_document_id','journal_reason','journal_confirmed','columns','matrix_document_id','matrix_reason','matrix_legal_basis','matrix_confirmed']))throw new DomainException('Steuerlichen Originalnachweis erneut prüfen.');
  foreach(['classification','capital_document_id','capital_reason','journal_document_id','journal_reason','matrix_document_id','matrix_reason','matrix_legal_basis'] as $key)if(!is_string($input[$key]??null))throw new DomainException('Originalart, Belege und fachliche Begründungen vollständig eingeben.');
  foreach(['capital_confirmed','journal_confirmed','matrix_confirmed'] as $key)if(($input[$key]??null)!=='1')throw new DomainException('Originalkapital, vollständige Journalzuordnung und steuerlichen Anlagenspiegel ausdrücklich bestätigen.');
  $item=$report['asset_schedule']['items'][$holding]??null;
  if(($item['method']??null)!==PartnershipBookPlans::METHOD||!is_array($item['partnership_book']??null)||!is_array($item['partnership_plan']??null))throw new DomainException('Bestätigte ursprüngliche HGB-Beteiligung im vollständigen Anlagenverzeichnis auswählen.');
  $plan=$item['partnership_plan'];$p=PartnershipBookPlans::original($plan);$book=$item['partnership_book'];
  if($p['holding_id']!==$holding||$p['club_id']!==$a['club_id'])throw new DomainException('Originalbeteiligung gehört nicht zu diesem Verein.');
  PartnershipBookPlans::evidence($this->db,$this->actor,$plan,$book);
  $e=new AssetValuationEvidence($this->db,$this->actor);$period=BalancePeriod::calendar($report['year']);
  $source=['schema'=>1,'holding_id'=>$holding,'club_id'=>$a['club_id'],'period'=>$period,'account'=>$p['account'],'classification'=>$input['classification']??null,
   'basis_scope'=>'original_assessed_components_no_duplicate_assets','opening'=>self::components($input['opening']??null),'closing'=>self::components($input['closing']??null),'movements'=>[],
   'document'=>$e->reference((string)($input['capital_document_id']??'')),'reason'=>$input['capital_reason']??null,'confirmed'=>true];
  $rows=$input['capital_movements']??[];if(!is_array($rows)||!array_is_list($rows)||count($rows)>200)throw new DomainException('Originale steuerliche Kapitalbewegungen vollständig angeben.');
  foreach($rows as $row){
   if(!is_array($row)||array_diff(array_keys($row),['id','kind','on','components','document_id','reason','confirmed']))throw new DomainException('Ursprüngliche Kapitalbewegung prüfen.');
   if(($row['kind']??'')==='')continue;
   foreach(['id','kind','on','document_id','reason'] as $key)if(!is_string($row[$key]??null))throw new DomainException('Ursprüngliche Kapitalbewegung vollständig eingeben.');
   $source['movements'][]=['id'=>$row['id']??null,'on'=>$row['on']??null,'kind'=>$row['kind'],'components'=>self::components($row['components']??null),
    'document'=>$e->reference((string)($row['document_id']??'')),'reason'=>$row['reason']??null,'confirmed'=>($row['confirmed']??null)==='1'];
  }
  $capital=PartnershipCapitalBasis::calculate($source,$previous['journal_proof']['sources']['capital_basis']??null);
  $sources=PartnershipHoldingSources::reconcile($book,$capital,$plan['partnership_relationship']);
  // Validate the existing adjustments independently before binding this new original source.
  $ordinary=$review;unset($ordinary['partnership_holdings']);$ordinary['complete']=true;$values=BalanceTaxValues::build($report,$ordinary);
  if(isset($values['asset_tax']))throw new DomainException('Gemischte steuerliche Anlagenpläne vor gemeinsamer Beteiligungsfreigabe vollständig anbinden.');
  $net=array_fill_keys(array_keys($report['accounts']),0);foreach($report['opening'] as $l)$net[$l['account']]+=$l['debit_cents']-$l['credit_cents'];
  foreach($review['opening_adjustments'] as $entry)foreach($entry['lines'] as $l)$net[$l['account']]+=$l['debit_cents']-$l['credit_cents'];
  $taxOpening=[];foreach($net as $key=>$n)if($n!==0)$taxOpening[]=['account'=>$key,'debit_cents'=>max(0,$n),'credit_cents'=>max(0,-$n),'sphere'=>''];
  $taxEntries=$report['entries'];foreach($review['current_adjustments'] as $entry)$taxEntries[]=['id'=>'tax-adjustment-'.$entry['id'],'date'=>$report['year'].'-12-31','lines'=>$entry['lines']];
  $journal=['schema'=>1,'club_id'=>$a['club_id'],'holding_id'=>$holding,'account_key'=>$item['account'],'result_account'=>$report['result_account'],'period'=>$period,'year'=>$report['year'],
   'chart'=>$report['accounts'],'book_opening'=>$report['opening'],'book_entries'=>$report['entries'],'tax_opening'=>$taxOpening,'tax_entries'=>$taxEntries,
   'book_links'=>$input['book_links']??[],'tax_links'=>$input['tax_links']??[],'document'=>$e->reference((string)($input['journal_document_id']??'')),'reason'=>$input['journal_reason']??null,'confirmed'=>true];
  $proof=PartnershipHoldingJournals::verify($sources,$journal,$previous['journal_proof']??null);
  $rows=$input['columns']??null;if(!is_array($rows)||count($rows)!==count(self::columns())||array_diff(array_keys($rows),self::columns()))throw new DomainException('Jede originale Spiegelspalte mit Betrag oder ausdrücklich unbekannt angeben.');
  $columns=[];foreach(self::columns() as $key)$columns[$key]=in_array($rows[$key]??null,['unknown','unbekannt'],true)?null:self::amount($rows[$key]);
  $matrix=['schema'=>1,'club_id'=>$a['club_id'],'holding_id'=>$holding,'period'=>$period,'taxonomy'=>'6.9','reporting'=>'original_fiscal_gross_matrix','columns'=>$columns,
   'document'=>$e->reference((string)($input['matrix_document_id']??'')),'reason'=>$input['matrix_reason']??null,'legal_basis'=>$input['matrix_legal_basis']??null,'confirmed'=>true];
  return PartnershipHoldingMatrix::reconcile($proof,$matrix,$previous);
 }
}
