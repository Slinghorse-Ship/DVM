<?php declare(strict_types=1);
namespace Kulturhus\Verein {function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2029-01-15 UTC'));}}
namespace {
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/mysql-bootstrap.php';require __DIR__.'/fixtures/balance-club-tax-reconciliation.php';
require_once __DIR__.'/../src/Finance/Balance/BalancePeriodTaxReconciliations.php';
use Kulturhus\Verein\{BalanceLedger as L,BalanceSegments as S,BalanceClearings as W,BalanceTaxReconciliations as T,BalancePeriodLedger as P,BalancePeriodAreas as A,BalancePeriodTaxReconciliations as PT,Database,SettingsSchema};
use Kulturhus\Verein\Tests\MySqlFixture;
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$n=0;function check(bool $ok,string $why):void{global $n;if(!$ok)throw new RuntimeException($why);$n++;echo "PASS $why\n";}
function denied(callable $fn,string $why):void{try{$fn();}catch(DomainException){check(true,$why);return;}throw new RuntimeException($why);}
$db=MySqlFixture::connect();Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic calendar tax bridge'); INSERT INTO actors(id,club_id,role,is_superuser) VALUES('board','a','treasurer',1)");
$settings=array_replace(SettingsSchema::defaults('tax'),['accounting_basis'=>'balance']);$db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('a','tax',?,1,?)")->execute([json_encode($settings),gmdate('c')]);
putenv('VEREIN_BALANCE_WORKSPACE=1');putenv('VEREIN_BALANCE_PERIODS=1');putenv('VEREIN_MODE=synthetic');
[$r,$units,$alloc,$instructions]=clearingFixture();$l=new L($db,'board');$s=new S($db,'board');$w=new W($db,'board');$tax=new T($db,'board');
$l->create(2025);$l->setup(2025,1,$r['accounts'],$r['opening'],'equity','Synthetic original opening',true);$version=2;
foreach($r['entries'] as $entry)$l->post(2025,$version++,$entry['id'],$entry['date'],$entry['description'],'Synthetic original source',$entry['lines']);
$checks=['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true];$l->close(2025,$version,$checks,'Synthetic calendar close');$s->save(2025,0,$units,$alloc);
$w->save(2025,0,1,$instructions,true,'Synthetic original advances');$w->confirm(2025,1,1,true,'Synthetic calendar review');
$income=$w->incomeReconciliation(2025);$review=taxIncomeReview($income);
$review['current_adjustments']=[['id'=>'taxvalue','reason'=>'Synthetic original tax evidence','lines'=>[['account'=>'receivables','debit_cents'=>501,'credit_cents'=>0,'sphere'=>''],['account'=>'expense','debit_cents'=>0,'credit_cents'=>501,'sphere'=>'4']]]];
$tax->save(2025,0,$review,'Synthetic calendar tax draft');$tax->confirm(2025,1,$income['sha256'],true,'Synthetic original tax approval');
$calendar=$tax->get(2025);$originals=[];foreach(['balance_years','balance_entries','balance_segment_reviews','balance_clearing_reviews'] as $table)$originals[$table]=MySqlFixture::rows($db,$table);
$p=new P($db,'board');$a=new A($db,'board');$pt=new PT($db,'board');$id=$p->create(['start'=>'2026-01-01','end'=>'2026-06-30'],'Synthetic short successor',true);
$r=$p->report($id);check($r['previous']['kind']==='calendar'&&$r['previous']['id']==='2025','dated journal retains exact calendar predecessor');
$p->close($id,$r['version'],$r['journal_sha256'],$checks,'Synthetic dated close');$r=$p->report($id);$a->prepare($id,0,$r['closed_sha256'],$units,'Synthetic dated area carry');$area=$a->view($id);$a->confirm($id,$area['version'],$r['closed_sha256'],$area['sha256'],true,'Synthetic dated areas reviewed');
$draft=$pt->draft($id);$pt->save($id,0,$draft,'Synthetic original tax carry');$originalDatedTax=$pt->confirm($id,1,$draft['income_sha256'],true,'Synthetic dated tax approval');$out=$pt->get($id);
check($out['current_approval']&&$out['values']['previous']===$calendar['values'],'stored calendar tax source and dated source are exactly bound');
check(end($out['history'])['prior_source']===['kind'=>'calendar','id'=>'2025','revision'=>2,'sha256'=>end($calendar['history'])['sha256']],'actual immutable calendar approval reference retained');
check($out['values']['valuation']['accounts']['receivables']['tax_opening_cents']===501&&$out['values']['result']['valuation_difference_cents']===0,'calendar difference carried without duplicate dated profit');
foreach($originals as $table=>$rows)check(MySqlFixture::rows($db,$table)===$rows,'dated tax bridge never rewrites '.$table);
$review['current_adjustments'][0]['reason']='Synthetic revised calendar tax evidence';$tax->save(2025,2,$review,'Synthetic calendar revision');
check(!$pt->get($id)['carry_current']&&!$pt->get($id)['current_approval'],'changed calendar tax approval invalidates dependent dated approval');
denied(fn()=>$pt->files($id),'stale calendar tax carry cannot be exported as current');
check($pt->original($id,2,$originalDatedTax['sha256'])===$originalDatedTax,'actual original dated tax and calendar prior remain readable while later calendar tax approval is pending');
$tax->confirm(2025,3,$income['sha256'],true,'Synthetic calendar tax reaffirmed');$draft=$pt->draft($id);$pt->save($id,2,$draft,'Synthetic rechecked original carry');$pt->confirm($id,3,$draft['income_sha256'],true,'Synthetic dated carry reaffirmed');
check($pt->get($id)['current_approval'],'explicit dated review restores approval after calendar revision');
check(!MySqlFixture::foreignKeyErrors($db),'calendar and period links remain club-bound');
echo "$n calendar-to-period tax persistence checks passed; synthetic MySQL only, no transport\n";
}
