<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
require_once dirname(__DIR__, 2).'/Finance/Assets/OwnIntangibleBookJournals.php';
require_once __DIR__.'/BalanceOwnIntangibleStoredSources.php';
/** Original full HGB journal and register; no fiscal document or zero value inferred. */
final class BalanceOwnIntangibleBooks
{
 public function __construct(private PDO $db,private string $actor){}
 public static function bind(array $report,array $sources,array $book):array{
  BalanceOwnIntangibleValues::need(isset($report['whole_club_scope'])&&!isset($report['club_scope']));
  $id=BalanceOwnIntangibleValues::scope($report,[]);BalanceOwnIntangibleValues::need(array_keys($sources)===[$id]);$p=$sources[$id];
  BalanceOwnIntangibleValues::need(BalanceOwnIntangibleValues::same(OwnIntangibleBookJournals::verify($p['book_proof'],$p['journal'],$p['previous']),$p));$j=$p['journal'];$item=$report['asset_schedule']['items'][$id];
  BalanceOwnIntangibleValues::need($j['item_id']===$id&&$j['year']===$report['year']&&$j['period']===BalancePeriod::calendar($report['year'])&&$j['account_key']===$item['account']&&$j['chart']===$report['accounts']&&$j['result_account']===$report['result_account']&&$j['book_opening']===$report['opening']);
  $calc=$p['book_calculation'];unset($calc['period']);BalanceOwnIntangibleValues::need($calc===$book&&BalanceOwnIntangibleValues::same($p['book_proof'],$item['own_book'])&&BalanceOwnIntangibleValues::same($j['book_entries'],BalanceOwnIntangibleValues::entries($report,$id,(array)$j['book_links']))&&($report['previous_sha256']===null)===($p['previous']===null));
  $out=['schema'=>1,'club_id'=>$j['club_id'],'year'=>$report['year'],'journal_sha256'=>$report['closed_sha256'],'schedule_sha256'=>OwnIntangibleJournals::fingerprint($report['asset_schedule']),'sources'=>$sources,'export_authorized'=>false];
  return $out+['sha256'=>OwnIntangibleJournals::fingerprint($out)];
 }
 public function read(array $report):array{
  return BalanceReadSnapshot::run($this->db,function()use($report){
   $stored=new BalanceOwnIntangibleStoredSources($this->db,$this->actor);$stored->actual($report);$id=BalanceOwnIntangibleValues::scope($report,[]);$sources=[$id=>$this->journal($report,$id)];return self::bind($report,$sources,$report['calculation']);
  });
 }
 private function journal(array $report,string $id,int $depth=0):array{
  BalanceOwnIntangibleValues::need($depth<2);$stored=new BalanceOwnIntangibleStoredSources($this->db,$this->actor);$stored->actual($report);$links=$stored->bookLinks($report,$id);$book=$report['asset_schedule']['items'][$id]['own_book'];$s=$book['source_proof']['source'];$previous=null;
  if($report['previous_sha256']!==null){
   $r=BalanceWholeClubReport::fromReport((new BalanceLedger($this->db,$this->actor))->report($report['year']-1));BalanceOwnIntangibleValues::need($r['closed_sha256']===$report['previous_sha256']);$previous=$this->journal($r,$id,$depth+1);
  }
  $j=['schema'=>1,'club_id'=>$s['club_id'],'item_id'=>$id,'year'=>$report['year'],'period'=>BalancePeriod::calendar($report['year']),'account_key'=>$report['asset_schedule']['items'][$id]['account'],'result_account'=>$report['result_account'],'chart'=>$report['accounts'],'book_opening'=>$report['opening'],'book_entries'=>BalanceOwnIntangibleValues::entries($report,$id,$links),'book_links'=>$links,'document'=>$book['book_source']['document'],'reason'=>$book['book_source']['reason'],'confirmed'=>true];
  $p=OwnIntangibleBookJournals::verify($book,$j,$previous);(new OwnIntangibleEvidence($this->db,$this->actor))->checkBookJournals($p);return $p;
 }
}
