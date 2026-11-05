<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/mysql-bootstrap.php';
use Kulturhus\Verein\{Database,TaxFilings,TaxFilingListMetadata as Metadata};
use Kulturhus\Verein\Tests\MySqlFixture;
$db=MySqlFixture::connect();Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('list-a','Synthetic list A'),('list-b','Synthetic list B');
 INSERT INTO actors(id,club_id,role,is_superuser) VALUES('list-finance','list-a','treasurer',0),('list-member','list-a','member',0),('list-other','list-b','treasurer',0)");
$checks=0;
function check(bool $value,string $label):void{global $checks;if(!$value)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$sql='SELECT '.Metadata::projection().' FROM (SELECT ? AS form_data) synthetic_source';$q=$db->prepare($sql);
$meta=static function(string $raw)use($q):array{$q->execute([$raw]);return Metadata::read($q->fetchColumn()?:null,2026);};
$binding=['period'=>['start'=>'2025-07-01','end'=>'2026-06-30'],'period_id'=>'20250701-20260630','profile'=>['kind'=>'whole_taxable_club']];
$m=$meta(json_encode(['balance_preparation'=>$binding]));
check($m===['dated'=>true,'period'=>$binding['period'],'kind'=>'whole_taxable_club'],'projection retains exact dates and profile hint');
check($meta('{"balance_preparation":{}}')['period']===['start'=>'2026-01-01','end'=>'2026-12-31'],'calendar records retain original calendar label');
foreach(['{}','[]','{"balance_preparation":null}','{"balance_preparation":[]}'] as $raw)check(!$meta($raw)['dated'],'legacy missing binding remains an undated list hint');
foreach(['{bad','null','true','7','"text"','{"balance_preparation":true}','{"balance_preparation":"text"}','{"balance_preparation":{"period":null}}','{"balance_preparation":{"period_id":"20260101-20261231"}}'] as $raw)
    denied(fn()=>$meta($raw),'malformed metadata is controlled, not a calendar fallback');
foreach([
    array_replace($binding,['period_id'=>'20260101-20261231']),
    array_replace($binding,['period'=>['start'=>'2026-01-01','end'=>'2025-12-31']]),
    array_replace($binding,['period'=>['start'=>'2025-07-01','end'=>'2026-06-31']]),
    array_replace($binding,['period'=>['start'=>'2024-07-01','end'=>'2025-06-30']]),
    array_replace($binding,['profile'=>['kind'=>['whole_taxable_club']]])
] as $bad)denied(fn()=>$meta(json_encode(['balance_preparation'=>$bad])),'invalid date identity or profile cannot become an eligible hint');

// 24 MiB of synthetic source text stays on MySQL. Reading the list must not
// transfer/decode all of it just to display dates. Nothing is sent or approved.
$insert=$db->prepare("INSERT INTO tax_filings(club_id,id,family,data_version,tax_year,environment,state,version,form_data,scope_snapshot,created_by,updated_by,created_at,updated_at)
 VALUES('list-a',?,'balance','Bilanz_6_9',2026,'test','draft',1,JSON_SET(?, '$.synthetic_large_history',REPEAT('x',524288)),'{}','list-finance','list-finance','2026-01-01','2026-01-01')");
$json=json_encode(['balance_preparation'=>$binding],JSON_THROW_ON_ERROR);
for($i=1;$i<=48;$i++)$insert->execute([sprintf('%032x',$i),$json]);
$before=$db->query("SELECT COUNT(*) n,SUM(OCTET_LENGTH(form_data)) bytes FROM tax_filings WHERE club_id='list-a'")->fetch();
$list=new TaxFilings($db,'list-finance');gc_collect_cycles();memory_reset_peak_usage();$baseline=memory_get_usage(true);
$rows=$list->recent(2026);$growth=memory_get_peak_usage(true)-$baseline;
check(count($rows)===48,'all synthetic large drafts listed');
check($growth<=8*1024*1024,'list memory growth stays within 8 MiB, independent of retained source size');
foreach($rows as $r)check($r['period_label']==='01.07.2025 – 30.06.2026'&&!isset($r['form_data'])&&!isset($r['period_metadata']),'list has the exact period and no source packet');
check((new TaxFilings($db,'list-other'))->recent(2026)===[],'foreign club cannot see list hints');
denied(fn()=>(new TaxFilings($db,'list-member'))->recent(2026),'member cannot read tax list');
check($list->recent(2025)===[],'different year remains isolated');
$bad=json_encode(['balance_preparation'=>array_replace($binding,['period_id'=>'20260101-20261231'])]);
$db->prepare("UPDATE tax_filings SET form_data=? WHERE club_id='list-a' AND id=?")->execute([$bad,sprintf('%032x',1)]);
$rows=array_column($list->recent(2026),null,'id');check($rows[sprintf('%032x',1)]['period_label']==='Zeitraum prüfen','damaged period is visible without crashing overview');
check($list->get(sprintf('%032x',2))['form_data']['synthetic_large_history']===str_repeat('x',524288),'opening retains the full original evidence');
check((int)$before['bytes']>24*1024*1024&&count($rows)===(int)$before['n'],'no large record was discarded to satisfy the memory bound');
echo "$checks tax-list checks passed; synthetic source bytes {$before['bytes']}; list peak growth $growth bytes; no transport\n";
