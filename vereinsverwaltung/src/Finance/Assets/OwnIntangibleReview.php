<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
require_once dirname(__DIR__, 2).'/Finance/Balance/BalanceOwnIntangibleStoredSources.php';
require_once dirname(__DIR__, 2).'/Finance/Balance/BalanceTaxValues.php';
require_once __DIR__.'/PartnershipHoldingReview.php';
/** Original tax values are entered independently; original HGB links are read from storage. */
final class OwnIntangibleReview
{
 public function __construct(private PDO $db,private string $actor){}
 public function build(array $report,array $review,string $id,array $input,?array $previous=null):array{
  return BalanceReadSnapshot::run($this->db,function()use($report,$review,$id,$input,$previous){
   BalanceOwnIntangibleValues::need(BalanceOwnIntangibleValues::scope($report,$review)===$id&&!array_diff(array_keys($input),['opening','closing','fiscal_document_id','fiscal_reason','fiscal_confirmed','tax_links','journal_document_id','journal_reason','journal_confirmed']));
   foreach(['opening','closing','fiscal_document_id','fiscal_reason','journal_document_id','journal_reason'] as $key)BalanceOwnIntangibleValues::need(is_string($input[$key]??null));
   foreach(['fiscal_confirmed','journal_confirmed'] as $key)BalanceOwnIntangibleValues::need(($input[$key]??null)==='1');
   BalanceOwnIntangibleValues::need(is_array($input['tax_links']??null));$stored=new BalanceOwnIntangibleStoredSources($this->db,$this->actor);$stored->actual($report);$links=$stored->bookLinks($report,$id);
   $book=$report['asset_schedule']['items'][$id]['own_book'];$s=$book['source_proof']['source'];$e=new AssetValuationEvidence($this->db,$this->actor);
   $fiscal=['schema'=>1,'club_id'=>$s['club_id'],'item_id'=>$id,'period'=>$s['period'],'book_proof_sha256'=>$book['sha256'],'basis'=>'EStG_5_2_not_acquired','opening_cents'=>PartnershipHoldingReview::amount($input['opening']),'closing_cents'=>PartnershipHoldingReview::amount($input['closing']),'document'=>$e->reference($input['fiscal_document_id']),'reason'=>$input['fiscal_reason'],'confirmed'=>true];
   $ordinary=$review;unset($ordinary['own_intangible_sources']);$ordinary['complete']=true;$v=BalanceTaxValues::build($report,$ordinary);BalanceOwnIntangibleValues::need(!isset($v['asset_tax']));
   $net=array_fill_keys(array_keys($report['accounts']),0);foreach($report['opening'] as $l)$net[$l['account']]+=$l['debit_cents']-$l['credit_cents'];foreach($review['opening_adjustments'] as $row)foreach($row['lines'] as $l)$net[$l['account']]+=$l['debit_cents']-$l['credit_cents'];
   $opening=[];foreach($net as $key=>$n)if($n)$opening[]=['account'=>$key,'debit_cents'=>max(0,$n),'credit_cents'=>max(0,-$n),'sphere'=>''];
   $entries=BalanceOwnIntangibleValues::entries($report,$id,$links);$tax=$entries;foreach($review['current_adjustments'] as $row)$tax[]=['id'=>'tax-adjustment-'.$row['id'],'date'=>$report['year'].'-12-31','lines'=>$row['lines'],'document'=>$fiscal['document']];
   $j=['schema'=>1,'club_id'=>$s['club_id'],'item_id'=>$id,'year'=>$report['year'],'period'=>$s['period'],'account_key'=>$report['asset_schedule']['items'][$id]['account'],'result_account'=>$report['result_account'],'chart'=>$report['accounts'],'book_opening'=>$report['opening'],'book_entries'=>$entries,'book_links'=>$links,'tax_opening'=>$opening,'tax_entries'=>$tax,'tax_links'=>$input['tax_links'],'fiscal_source'=>$fiscal,'document'=>$e->reference($input['journal_document_id']),'reason'=>$input['journal_reason'],'confirmed'=>true];
   $p=OwnIntangibleJournals::verify($book,$j,$previous);$bound=$review;$bound['own_intangible_sources']=[$id=>$p];BalanceOwnIntangibleValues::bind($report,$bound,$v['book'],$v['tax']);(new OwnIntangibleEvidence($this->db,$this->actor))->checkJournals($p);return $p;
  });
 }
}
