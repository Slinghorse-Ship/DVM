<?php declare(strict_types=1);
require __DIR__.'/balance-period-asset-areas.php';
require_once __DIR__.'/../src/Finance/Balance/BalancePeriodTaxReconciliations.php';
use Kulturhus\Verein\{BalancePeriodAssetUse as UseChange,BalancePeriodAssetAllocation as Usage,BalanceClubIncomeReconciliation as Income};
$base=$n;$n=0;$_SESSION=['actor'=>'asset-admin','csrf'=>'synthetic-use'];$_SERVER['REQUEST_METHOD']='GET';$_POST=[];
$beforeJournal=$l->report($pid);$beforeArea=$areas->view($pid);$nextJournal=$l->report($pid2);
$edit=function(string $op,array $data,?string $target=null)use($areas,$l,$pid):void{$target??=$pid;$st=$areas->view($target);$areas->edit($target,$st['version'],$l->report($target)['closed_sha256'],$op,$data,'Synthetic dated use evidence');};
$edit('asset-use-change',['item'=>'amplifier','on'=>'2026-04-01','weights'=>['groupb'=>1]]);
$changed=$areas->view($pid);check($changed['proof']['complete'],'dated use is fully reconciled without manually re-entering depreciation amounts');
check(!$changed['current_approval']&&$changed['state']==='draft','changing use requires a new board review');
check($l->report($pid)===$beforeJournal&&$l->report($pid2)===$nextJournal,'original journals are untouched');
check(!$areas->view($pid2)['current_approval'],'changed previous use invalidates dependent approval');
$a=$changed['proof']['assets'];$v=$a['schedules']['groupb']['items']['amplifier'];
check($v['book_transfer_cents']>0&&$v['cost_transfer_cents']-$v['accumulated_transfer_cents']===$v['book_transfer_cents'],'internal book transfer is explicit, not a new purchase');
check($a['schedules']['groupa']['items']['amplifier']['book_closing_cents']===0,'departing unit no longer carries the transferred asset');
check($changed['proof']['statement']['units']['groupa']['totals']['difference_cents']===0&&$changed['proof']['statement']['units']['groupb']['totals']['difference_cents']===0,'both area balance sheets remain balanced');
check($v['depreciation_cents']>intdiv($beforeJournal['asset_schedule']['items']['amplifier']['schedule']['depreciation_cents'],3),'later use receives later depreciation');
$original=$beforeArea['proof']['assets']['schedules'];foreach($a['schedules'] as $unit=>$values)foreach(['cost','accumulated','book'] as $f)check($values['items']['amplifier'][$f.'_opening_cents']===$original[$unit]['items']['amplifier'][$f.'_opening_cents'],'unchanged opening '.$unit.' '.$f);
$areas->confirm($pid,$changed['version'],$first['closed_sha256'],$changed['sha256'],true,'Synthetic board use review');
$approvedUse=$areas->view($pid);$incomeUse=$areas->incomeReconciliation($pid);
check($incomeUse['result']===$income['result']&&$incomeUse['scope']['source']['assets']===$approvedUse['proof']['assets'],'income review verifies actual analytical movements without changing sphere results');
$bad=$incomeUse['scope'];$bad['source']['assets']['movements']['amplifier'][0]['parts']['groupa']['book_transfer_cents']++;
denied(fn()=>Income::rebuild($bad),'tampered internal book transfer cannot be accepted by rehashing');
$bundle=(new \Kulturhus\Verein\BalancePeriodExport($db,'asset-admin'))->build($pid);
check(str_contains($bundle['files']['anlagennutzung.csv'],'Nutzungswechsel Buchwert Cent')&&str_contains($bundle['files']['anlagennutzung.json'],'2026-04-01'),'working export includes date, evidence and explicit asset transfer columns');
$_GET=['period_id'=>$pid,'areas'=>'1'];$html=$render();
check(str_contains($html,'Nutzung ab einem Datum ändern')&&str_contains($html,'01.04.2026')&&str_contains($html,'Nutzungswechsel aufheben'),'rendered form includes named dated changes and reversible correction');
check(str_contains($html,'Nutzung bis 31.03.2026')&&str_contains($html,'Vereinsarbeit B: 1 Nutzungsstunde</li>'),'old/new use has clear dates and correct singular');
$dom=new DOMDocument();$dom->loadHTML('<meta charset="utf-8">'.$html,LIBXML_NOERROR|LIBXML_NOWARNING);$xp=new DOMXPath($dom);
check($xp->query('//form[input[@name="action" and @value="balance-period-area-asset"] and input[@name="area_item" and @value="amplifier"]]')->length===0,'dated use cannot be replaced through misleading baseline edit form');
file_put_contents(__DIR__.'/balance-period-asset-use-ui.html',$html);
$dbSnapshot=\Kulturhus\Verein\Tests\MySqlFixture::snapshot($db);
foreach([['on'=>'2026-04-01','weights'=>['groupa'=>1]],['on'=>'2026-04-02','weights'=>['groupa'=>1]],['on'=>'2026-07-01','weights'=>['groupa'=>1]],['on'=>'2026-05-01','weights'=>['foreign'=>1]]] as $data)denied(fn()=>$edit('asset-use-change',['item'=>'amplifier']+$data),'invalid dated use cannot write');
check($dbSnapshot===\Kulturhus\Verein\Tests\MySqlFixture::snapshot($db),'invalid dated edits leave all records and audit unchanged');
$edit('asset-use-remove',['item'=>'amplifier','on'=>'2026-04-01']);$removed=$areas->view($pid);
check($removed['proof']['complete']&&!isset($removed['proof']['assets']['movements']),'removing dated change restores original allocation and reconciles automatically');
check(count($removed['history'])>count($beforeArea['history'])&&$removed['history'][$beforeArea['version']-1]===$beforeArea['history'][$beforeArea['version']-1],'correction is appended; no approved snapshot overwritten');
$edit('asset-use-change',['item'=>'amplifier','on'=>'2026-04-01','weights'=>['groupb'=>1]]);$s2=$areas->view($pid);$areas->confirm($pid,$s2['version'],$first['closed_sha256'],$s2['sha256'],true,'Synthetic restored use review');
// The actual short successor must carry ending weights and adjusted book/capital amounts.
$thirdId=$pid2;$third=$l->report($thirdId);
$areas->prepare($thirdId,$areas->view($thirdId)['version'],$third['closed_sha256'],$units,'Synthetic carry ending use');$thirdArea=$areas->view($thirdId);
check($thirdArea['input']['asset_shares']['amplifier']['weights']===['groupb'=>1]&&!isset($thirdArea['input']['asset_shares']['amplifier']['changes']),'only final use weights carry; dated movements do not repeat');
$expectedThird=Usage::build($thirdArea['source'],$units,[],$thirdArea['input']['asset_shares'],$areas->view($pid)['proof']['assets'],false);
foreach($third['sources'] as $binding){$assetId=$binding['proposal']['snapshot']['item_id'];foreach($binding['entry_ids'] as $entryId)foreach($third['entries'][$entryId]['lines'] as $ordinal=>$line){$parts=[];foreach($units as $u=>$_){$amount=$expectedThird['schedules'][$u]['items'][$assetId]['depreciation_cents']??0;if($amount)$parts[$u]=$amount;}$edit('line',['key'=>$entryId.':'.$ordinal,'parts'=>$parts],$thirdId);}}
$thirdArea=$areas->view($thirdId);check($thirdArea['proof']['complete'],'adjusted asset and capital carry into following year without gaps');
$areas->confirm($thirdId,$thirdArea['version'],$third['closed_sha256'],$thirdArea['sha256'],true,'Synthetic third area review');$thirdIncome=$areas->incomeReconciliation($thirdId);
check($thirdIncome['period']===$third['period']&&Income::rebuild($thirdIncome['scope'])===$thirdIncome,'successor income proof replays use movements and their nonrepeated carry');
$taxService=new \Kulturhus\Verein\BalancePeriodTaxReconciliations($db,'asset-admin');
foreach([$pid,$pid2] as $taxPeriod){$draft=$taxService->draft($taxPeriod);$taxService->save($taxPeriod,0,$draft,'Synthetic tax workpaper after use change');$taxService->confirm($taxPeriod,1,$draft['income_sha256'],true,'Synthetic board tax review');
    check($taxService->get($taxPeriod)['current_approval']&&!$taxService->get($taxPeriod)['tax_export_ready'],'tax workpapers retain actual use/carry without granting transmission');}
// Permission, optimistic-lock and tenant checks use the actual controller/service.
$post=['action'=>'balance-period-area-asset-use-change','period_id'=>$pid,'area_version'=>(string)$areas->view($pid)['version'],'closed_hash'=>$first['closed_sha256'],'area_item'=>'amplifier','use_on'=>'2026-05-01','weights'=>['groupa'=>'1'],'evidence'=>'Synthetic'];
foreach(['asset-reader','other'] as $actor){$_SESSION=['actor'=>$actor,'csrf'=>'synthetic'];denied(fn()=>$control($post),'reader or foreign actor cannot change use');}
$_SESSION=['actor'=>'asset-admin','csrf'=>'synthetic'];putenv('VEREIN_BALANCE_PERIODS=0');denied(fn()=>$control($post),'disabled period feature gate remains closed');putenv('VEREIN_BALANCE_PERIODS=1');
$post['area_version']='0';denied(fn()=>$control($post),'stale form cannot overwrite newer use evidence');
check(\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)===[],'dated use records stay tenant-bound');
echo 'Peak memory: '.memory_get_peak_usage(true).' bytes; configured limit: '.ini_get('memory_limit')."\n";
echo "$n dated asset-use review checks passed plus $base shared-area checks; synthetic MySQL only\n";
