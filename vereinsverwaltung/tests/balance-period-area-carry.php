<?php declare(strict_types=1);
namespace Kulturhus\Verein {function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2029-01-15 UTC'));}}
namespace {
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/mysql-bootstrap.php';require_once __DIR__.'/../src/Finance/Balance/BalancePeriodAreas.php';require_once __DIR__.'/../src/Finance/Balance/BalancePeriodExport.php';require __DIR__.'/fixtures/balance-internal-clearing.php';
use Kulturhus\Verein\{BalancePeriodAreas as A,BalanceCalendarAreaSource as H,BalancePeriodLedger as P,BalanceLedger as L,BalanceSegments as S,BalanceClearings as W,BalancePeriodExport as E,Database,SettingsSchema};
use Kulturhus\Verein\Tests\MySqlFixture;
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$checks=0;function check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);$checks++;echo "PASS $why\n";}
function denied(callable $fn,string $why):void{try{$fn();}catch(DomainException){check(true,$why);return;}throw new RuntimeException($why);}
$db=MySqlFixture::connect();Database::migrate($db);
putenv('VEREIN_BALANCE_WORKSPACE=1');putenv('VEREIN_BALANCE_PERIODS=1');putenv('VEREIN_MODE=synthetic');
$settings=array_replace(SettingsSchema::defaults('tax'),['accounting_basis'=>'balance']);$all=['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true];
foreach(['a','plain','other'] as $club){$db->prepare('INSERT INTO clubs VALUES(?,?)')->execute([$club,'Synthetic calendar bridge']);$db->prepare("INSERT INTO actors(id,club_id,role,is_superuser) VALUES(?,?,'treasurer',1)")->execute([$club.'-board',$club]);$db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES(?,'tax',?,1,?)")->execute([$club,json_encode($settings),gmdate('c')]);}
[$fixture,$units,$alloc,$instructions]=clearingFixture(100);
$legacy=new L($db,'a-board');$legacy->create(2025);$legacy->setup(2025,1,$fixture['accounts'],$fixture['opening'],'equity','Synthetic original',true);$version=2;
foreach($fixture['entries'] as $entry)$legacy->post(2025,$version++,$entry['id'],$entry['date'],$entry['description'],'Synthetic source',$entry['lines']);
$legacy->close(2025,$version,$all,'Synthetic original close');$s=new S($db,'a-board');$w=new W($db,'a-board');$h=new H($db,'a-board');
$s->save(2025,0,$units,$alloc);$w->save(2025,0,1,$instructions,true,'Synthetic original advances');$w->confirm(2025,1,1,true,'Synthetic original board');
$calendar=$h->current(2025);check($calendar['current_approval']&&$calendar['proof']['clearing']['totals']['closing_cents']===903,'calendar bridge retains nonzero reviewed advances');
check($h->income($calendar)===$w->incomeReconciliation(2025),'legacy income proof retains identical schema and digest');
$before=[];foreach(['balance_years','balance_entries','balance_segment_reviews','balance_clearing_reviews'] as $table)$before[$table]=MySqlFixture::rows($db,$table);
$p=new P($db,'a-board');$a=new A($db,'a-board');$period=['start'=>'2026-01-01','end'=>'2026-06-30'];$id=$p->create($period,'Synthetic short transition',true);$pay=str_repeat('d',32);$r=$p->report($id);
$p->post($id,$r['version'],$pay,'2026-02-01','Synthetic repayment','Synthetic bank proof',[['account'=>'cash','debit_cents'=>234,'credit_cents'=>0,'sphere'=>''],['account'=>'cash','debit_cents'=>0,'credit_cents'=>234,'sphere'=>'']]);
$r=$p->report($id);$p->close($id,$r['version'],$r['journal_sha256'],$all,'Synthetic short close');$r=$p->report($id);$hash=$r['closed_sha256'];
function input(array $a,string $key,string $default=''):string{return is_string($a[$key]??null)?$a[$key]:$default;}
function e(mixed $value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function hidden(string $key,mixed $value):string{return '<input type="hidden" name="'.e($key).'" value="'.e($value).'">';}function csrf():string{return hidden('csrf','synthetic');}function url(array $value):string{return '?'.http_build_query($value);}
final class CarryRedirect extends RuntimeException{public function __construct(public array $target){parent::__construct('Synthetic redirect');}}
function redirectTo(array $target):never{throw new CarryRedirect($target);}
$render=static function()use($db,$id):string{$_SESSION=['actor'=>'a-board'];$_POST=[];$_GET=['period_id'=>$id,'areas'=>'1'];$_SERVER['REQUEST_METHOD']='GET';ob_start();require __DIR__.'/../views/Finance/Balance/balance-periods.php';return ob_get_clean();};$html=$render();
check(str_contains($html,'Gastronomie')&&str_contains($html,'Verkauf'),'initial period UI displays actual prior businesses without re-entry');
$control=static function(array $post)use($db):array{$_SESSION=['actor'=>'a-board'];$_POST=$post;$action=$post['action'];try{require __DIR__.'/../src/Finance/Balance/BalanceActions.php';throw new RuntimeException('No redirect');}catch(CarryRedirect $redirect){return $redirect->target;}};
$route=$control(['area_prior_period'=>$calendar['period_id'],'area_prior_revision'=>(string)$calendar['version'],'area_prior_sha256'=>$calendar['sha256'],'area_request_key'=>str_repeat('3',32),'area_form_complete'=>'1','action'=>'balance-period-area-prepare','period_id'=>$id,'area_version'=>'0','closed_hash'=>$hash,'evidence'=>'Synthetic controller calendar carry']);check(($route['period_id']??null)===$id,'controller stays on exact transition period');
$state=$a->view($id);check($state['input']['units']===$calendar['input']['units'],'controller automatically reuses original business identifiers');
check($state['prior']['period_id']==='calendar:2025:clearing'&&$state['history'][0]['carry']['sha256']===$calendar['sha256'],'persisted carry points to real calendar review, not invented period');
foreach(['club','food'] as $ordinal=>$unit)$a->edit($id,$a->view($id)['version'],$hash,'line',['key'=>$pay.':'.$ordinal,'parts'=>[$unit=>234]],'Synthetic repayment allocation');
$food=substr(hash('sha256','clearing food'),0,32);$a->edit($id,$a->view($id)['version'],$hash,'repayment',['id'=>str_repeat('e',32),'voucher'=>$pay,'advance'=>$food,'cents'=>234,'reason'=>'Synthetic repay'],'Synthetic source');
$state=$a->view($id);check($state['proof']['complete']&&$state['proof']['clearing']['totals']===['opening_cents'=>903,'advanced_cents'=>0,'repaid_cents'=>234,'closing_cents'=>669],'legacy advance repaid once in actual short period');
check($state['proof']['statement']['club_calculation']['annual_result_cents']===0,'calendar-to-period carry adds no income or expense');
$a->confirm($id,$state['version'],$hash,$state['sha256'],true,'Synthetic board review');$first=$a->view($id);$income=$a->incomeReconciliation($id);check($a->originalIncome($id,$first['version'],$first['sha256'])===$income,'original calendar-to-period scope exactly matches actual selected clearing chain');
check($income['scope']['previous']===$w->incomeReconciliation(2025)['scope']&&$income['result']['whole_cents']===0,'dated result binds original calendar scope without rewriting hashes');
check(isset((new E($db,'a-board'))->build($id)['files']['ergebnisabgleich.json']),'mixed calendar and period review can export workpapers');
foreach($before as $table=>$rows)check(MySqlFixture::rows($db,$table)===$rows,'bridge did not modify '.$table);
denied(fn()=>(new A($db,'other-board'))->view($id),'foreign club cannot access calendar bridge');
// New predecessor signature invalidates the successor without erasing its history.
$w->save(2025,2,1,$instructions,true,'Synthetic later review');denied(fn()=>$a->prepare($id,$first['version'],$hash,$units,'No unreviewed source'),'unconfirmed calendar rereview cannot replace carry');$w->confirm(2025,3,1,true,'Synthetic later board');
check(!$a->view($id)['current_approval']&&!$a->view($id)['carry_current'],'new calendar signature makes successor stale');
denied(fn()=>(new E($db,'a-board'))->build($id),'stale calendar source blocks export');
$a->prepare($id,$a->view($id)['version'],$hash,$units,'Synthetic deliberate rebase');$state=$a->view($id);$a->confirm($id,$state['version'],$hash,$state['sha256'],true,'Synthetic rebased board');
check($a->view($id)['current_approval']&&array_slice($a->view($id)['history'],0,count($first['history']))===$first['history'],'old reference remains reconstructible after calendar rereview');
// A changed calendar closing also requires a real correction of the short-period opening.
require_once __DIR__.'/../src/Finance/Balance/BalanceClosingCorrections.php';$cc=new \Kulturhus\Verein\BalanceClosingCorrections($db,'a-board');$original=$legacy->report(2025);$periodOriginal=$p->report($id);
$proposal=['kind'=>'error','reason'=>'Synthetic missing legacy expense','legal_basis'=>'Synthetic original proof','confirmed'=>true,'entries'=>[['id'=>str_repeat('f',32),'date'=>'2025-12-31','description'=>'Synthetic late accrual','evidence'=>'Synthetic original','lines'=>[['account'=>'expense','debit_cents'=>7,'credit_cents'=>0,'sphere'=>'1'],['account'=>'liabilities','debit_cents'=>0,'credit_cents'=>7,'sphere'=>'']]]]];
$change=$cc->save(2025,0,$original['closed_sha256'],$proposal);$cc->confirm(2025,1,$original['closed_sha256'],$change['proof']['sha256'],true,'Synthetic corrected calendar');
denied(fn()=>(new E($db,'a-board'))->build($id),'changed calendar closing blocks old short-period export');
$corrected=$legacy->report(2025);$newAlloc=$alloc;foreach(\Kulturhus\Verein\BalanceSegmentCalculation::sources($corrected) as $key=>$line)if(!isset($newAlloc[$key]))$newAlloc[$key]=['parts'=>['club'=>$line['cents']],'reason'=>'Synthetic correction source'];
$s->save(2025,$s->get(2025)['version'],$units,$newAlloc);$sv=$s->get(2025)['version'];$wv=$w->get(2025)['version'];$w->save(2025,$wv,$sv,$instructions,true,'Synthetic corrected allocation');$w->confirm(2025,$wv+1,$sv,true,'Synthetic corrected board');
$carryCorrection=['kind'=>'carry','reason'=>'Synthetic corrected calendar carry','legal_basis'=>'Synthetic reviewed predecessor','confirmed'=>true,'entries'=>[]];$pr=$p->report($id);$p->prepareClosingCorrection($id,$pr['version'],$pr['closed_sha256'],$carryCorrection);$pr=$p->report($id);$p->confirmClosingCorrection($id,$pr['version'],$pr['closed_sha256'],$pr['correction_draft']['proof']['sha256'],true,'Synthetic corrected short opening');
$hash=$p->report($id)['closed_sha256'];$a->prepare($id,$a->view($id)['version'],$hash,$units,'Synthetic corrected calendar areas');$st=$a->view($id);$a->confirm($id,$st['version'],$hash,$st['sha256'],true,'Synthetic corrected transition areas');
$newIncome=$a->incomeReconciliation($id);check($newIncome['result']['whole_cents']===0&&$newIncome['internal_balances']['closing_received_cents']===669,'corrected calendar expense and advance not duplicated in short period');
check($newIncome['scope']['source']['calculation']['accounts']['liabilities']['opening_cents']===-7,'corrected opening includes real seven-cent legacy liability');
check($legacy->archivedReport(2025,$original['closed_sha256'])===$original&&$p->report($id)['original_closed_sha256']===$periodOriginal['closed_sha256'],'both original closing identities remain unchanged');
check(array_slice($a->view($id)['history'],0,count($first['history']))===$first['history'],'calendar correction preserves original area snapshots');
check($a->originalIncome($id,$first['version'],$first['sha256'])===$income,'original calendar-to-period scope survives actual calendar correction and newer reviews');
// The ordinary case does not require creating an empty advance-review record.
$plain=new L($db,'plain-board');$ps=new S($db,'plain-board');$opening=array_filter($alloc,static fn($k)=>str_starts_with($k,'opening:'),ARRAY_FILTER_USE_KEY);
$plain->create(2024);$plain->setup(2024,1,$fixture['accounts'],$fixture['opening'],'equity','Synthetic plain opening',true);$plain->close(2024,2,$all,'Synthetic plain close');$ps->save(2024,0,$units,$opening);$ps->confirm(2024,1,true,'Synthetic plain areas');
$plain->create(2025);$plain->close(2025,1,$all,'Synthetic plain next');$ps->carry(2025,0,true);$ps->confirm(2025,1,true,'Synthetic plain carry');
$ph=new H($db,'plain-board');$state=$ph->current(2025);check($state['period_id']==='calendar:2025:segments'&&$state['current_approval']&&$state['prior']['year']===2024,'plain reviewed areas carry across legacy years without new advance records');
$pi=$ph->income($state);check($pi['result']['whole_cents']===0&&$pi['scope']['previous']['source']['year']===2024,'plain multi-year original income chain retained');
$pp=new P($db,'plain-board');$pa=new A($db,'plain-board');$pid=$pp->create($period,'Synthetic plain switch',true);$r=$pp->report($pid);$pp->close($pid,$r['version'],$r['journal_sha256'],$all,'Synthetic short close');$pr=$pp->report($pid);$pa->prepare($pid,0,$pr['closed_sha256'],$units,'Synthetic plain carry');$st=$pa->view($pid);$pa->confirm($pid,$st['version'],$pr['closed_sha256'],$st['sha256'],true,'Synthetic plain board');
check($pa->incomeReconciliation($pid)['scope']['previous']===$pi['scope'],'plain legacy areas join dated result chain');
check(!(bool)$db->query("SELECT 1 FROM balance_clearing_reviews WHERE club_id='plain'")->fetchColumn(),'read-only bridge does not invent an empty advance review');
// A newly introduced explicit clearing review supersedes the plain source.
$pw=new W($db,'plain-board');$empty=['advances'=>[],'repayments'=>[]];$pw->save(2024,0,2,$empty,true,'Synthetic empty review');$pw->confirm(2024,1,2,true,'Synthetic empty board');
check(!$ph->read('calendar:2024:segments')['current_approval'],'plain source cannot bypass a newly introduced clearing review');
check($pa->view($pid)['history'][0]['carry']['period_id']==='calendar:2025:segments','historical plain reference retained even after superseding review');
// Original references, not merely recomputed payload hashes, are required.
$ref=$first['history'][0]['carry'];$bad=$ref;$bad['revision']=99999;denied(fn()=>$h->read($bad['period_id'],$bad),'invented calendar revision denied');
$bad=$ref;$bad['sha256']=str_repeat('0',64);denied(fn()=>$h->read($bad['period_id'],$bad),'wrong calendar review digest denied');
$bad=$ref;$bad['proof']['complete']=false;denied(fn()=>$h->read($bad['period_id'],$bad),'altered retained calendar proof denied');
check(MySqlFixture::foreignKeyErrors($db)===[],'calendar-to-period tenant boundaries intact');
echo "$checks calendar-to-period area checks passed; synthetic MySQL only, no transport\n";
}
