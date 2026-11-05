<?php declare(strict_types=1);
require __DIR__.'/balance-period-depreciation.php';
require_once __DIR__.'/../src/Finance/Balance/BalancePeriodAreas.php';
use Kulturhus\Verein\{BalancePeriodAreas as AreaService,BalancePeriodAssetAllocation as Usage,BalanceSegmentCalculation as Allocation,BalanceAssetAllocation as Parts,BalanceClubIncomeReconciliation as Income};
$base=$n;$n=0;
$areas=new AreaService($db,'asset-admin');$weights=['groupa'=>2,'groupb'=>1];$units=['groupa'=>['name'=>'Vereinsarbeit A','sphere'=>$assetSphere],'groupb'=>['name'=>'Vereinsarbeit B','sphere'=>$assetSphere]];
$prepare=function(string $target,?array $old=null)use($areas,$l,$units,$weights):array{
    $r=$l->report($target);$areas->prepare($target,$areas->view($target)['version'],$r['closed_sha256'],$units,'Synthetic original asset use');
    $shares=[];foreach($r['asset_schedule']['items'] as $id=>$item)$shares[$id]=['item_sha256'=>Allocation::hash($item),'weights'=>$weights,'measure'=>'hours','reason'=>'Synthetic use log'];
    $proof=Usage::build($areas->view($target)['source'],$units,[],$shares,$old);return [$r,$proof];
};
$assign=function(string $target,array $proof)use($l,$areas,$weights):void{
    $r=$l->report($target);$hash=$r['closed_sha256'];
    foreach($r['sources'] as $binding){$p=$binding['proposal'];
        foreach($binding['entry_ids'] as $entryId){$entry=$r['entries'][$entryId];
            foreach($entry['lines'] as $ordinal=>$line){
                if($p['type']==='depreciation'){
                    $item=$p['snapshot']['item_id'];$parts=[];foreach($weights as $u=>$_)$parts[$u]=$proof['schedules'][$u]['items'][$item]['depreciation_cents'];
                }else $parts=Parts::cents($line['debit_cents']+$line['credit_cents'],$weights);
                $parts=array_filter($parts,static fn($v)=>$v>0);$st=$areas->view($target);$areas->edit($target,$st['version'],$hash,'line',['key'=>$entryId.':'.$ordinal,'parts'=>$parts],'Synthetic original source and use');
            }
        }
    }
    foreach($r['asset_schedule']['items'] as $itemId=>$item){$st=$areas->view($target);$areas->edit($target,$st['version'],$hash,'asset',['item'=>$itemId,'weights'=>$weights,'measure'=>'hours'],'Synthetic reviewed use log');}
};
[$first,$expected]=$prepare($pid);$st=$areas->view($pid);check(!$st['proof']['complete']&&!empty($st['proof']['assets']['issues']),'missing use shares block review even with original asset values');
$assign($pid,$expected);$st=$areas->view($pid);check($st['proof']['complete'],'shared linear GWG and degressive assets reconcile with actual journal');
foreach($first['asset_schedule']['items'] as $id=>$item)foreach(Usage::FIELDS as $field){$sum=0;foreach($st['proof']['assets']['schedules'] as $v)$sum+=$v['items'][$id][$field];check($sum===($item['schedule'][$field]??0),'whole-asset cents retained '.$id.' '.$field);}
$areas->confirm($pid,$st['version'],$first['closed_sha256'],$st['sha256'],true,'Synthetic original usage board');$approved=$areas->view($pid);$income=$areas->incomeReconciliation($pid);check($areas->originalIncome($pid,$approved['version'],$approved['sha256'])===$income,'original asset income exactly matches actual reviewed shares and rounded cents');
check($income['schema']===3&&$income['period']===$first['period']&&$income['scope']['source']['assets']===$approved['proof']['assets'],'income proof binds dated use evidence rather than ignoring assets');
check($income['result']['whole_cents']===$first['calculation']['annual_result_cents']&&!$income['tax_export_ready'],'whole assets and expense totals unchanged by use split');
$export=(new \Kulturhus\Verein\BalancePeriodExport($db,'asset-admin'))->build($pid);check(isset($export['files']['anlagennutzung.csv'],$export['files']['anlagennutzung.json'])&&str_contains($export['files']['anlagennutzung.csv'],'Vereinsarbeit A'),'working export includes per-area cost and depreciation movement');
[$next,$nextExpected]=$prepare($pid2,$approved['proof']['assets']);$assign($pid2,$nextExpected);$st2=$areas->view($pid2);check($st2['proof']['complete'],'shared use carries into separate short year');
foreach($weights as $u=>$_)foreach($st2['proof']['assets']['schedules'][$u]['items'] as $id=>$v)foreach(['cost','accumulated','book'] as $field)check($v[$field.'_opening_cents']===$approved['proof']['assets']['schedules'][$u]['items'][$id][$field.'_closing_cents'],'no historical cent rewritten '.$u.' '.$id.' '.$field);
$areas->confirm($pid2,$st2['version'],$next['closed_sha256'],$st2['sha256'],true,'Synthetic second usage board');$income2=$areas->incomeReconciliation($pid2);check($income2['scope']['previous']===$income['scope'],'two asset periods with same end year have distinct income evidence');
$st2=$areas->view($pid2);denied(fn()=>$areas->edit($pid2,$st2['version'],$next['closed_sha256'],'asset',['item'=>'amplifier','weights'=>['groupa'=>1,'groupb'=>2],'measure'=>'hours'],'Synthetic changed use'),'changing carried use is not silently accepted');
$bad=$income2['scope'];$bad['source']['assets']['schedules']['groupa']['items']['amplifier']['book_closing_cents']++;denied(fn()=>Income::rebuild($bad),'rehashed asset result cannot replace original rounded share');
$bad=$income['scope'];$bad['schema']=2;unset($bad['source']['assets']);denied(fn()=>Income::rebuild($bad),'older result schema cannot bypass missing dated use proof');
$badReport=$approved['source'];$badReport['asset_schedule']['items']['amplifier']['schedule']['depreciation_cents']++;denied(fn()=>Usage::build($badReport,$units,[],$approved['proof']['assets']['shares']),'changed original depreciation rejected before allocating');
$badShares=$approved['proof']['assets']['shares'];$badShares['amplifier']['weights']=['groupa'=>1,'foreign'=>1];denied(fn()=>Usage::build($approved['source'],$units,[],$badShares),'unknown use area rejected');
$foreignSphere=$units;$foreignSphere['groupb']['sphere']=$assetSphere==='4'?'1':'4';denied(fn()=>Usage::build($approved['source'],$foreignSphere,[],$approved['proof']['assets']['shares']),'use split cannot silently change tax sphere');
$_SESSION=['actor'=>'asset-admin','csrf'=>'synthetic-asset-use'];$_GET=['period_id'=>$pid,'areas'=>'1'];$_POST=[];$_SERVER['REQUEST_METHOD']='GET';$render=static function()use($db):string{ob_start();require __DIR__.'/../views/Finance/Balance/balance-periods.php';return ob_get_clean();};$html=$render();
check(str_contains($html,'Gegenstände und ihre Nutzung')&&str_contains($html,'Nutzung zuordnen')&&str_contains($html,'Nutzungsstunden'),'actual use UI shows named assets and comprehensible measure');
check(str_contains($html,'1 Nutzungsstunde</li>')&&!str_contains($html,'1 Nutzungsstunden</li>'),'singular use measure is readable');
file_put_contents(__DIR__.'/balance-period-asset-areas-ui.html',$html);
$dom=new DOMDocument();$dom->loadHTML('<meta charset="utf-8">'.$html,LIBXML_NOERROR|LIBXML_NOWARNING);$xp=new DOMXPath($dom);
check($xp->query('//form[input[@name="action" and @value="balance-period-area-asset"]]/input[@name="csrf"]')->length===4,'each asset form retains central CSRF and original item');
$_SERVER['REQUEST_METHOD']='POST';$_POST=['period_id'=>$pid,'closed_hash'=>$first['closed_sha256'],'area_prior_period'=>'','area_prior_revision'=>'','area_prior_sha256'=>'','area_request_key'=>str_repeat('4',32),'area_form_complete'=>'1','action'=>'balance-period-area-asset','area_item'=>'amplifier','area_version'=>(string)$approved['version'],'weights'=>['groupa'=>'<script>','groupb'=>'9'],'measure'=>'days','evidence'=>'<evidence>'];$failed=$render();
check(str_contains($failed,'value="&lt;script&gt;"')&&str_contains($failed,'value="&lt;evidence&gt;"')&&!str_contains($failed,'value="<script>"'),'failed form retains and escapes use and evidence');
$control=static function(array $post)use($db):void{$_POST=$post;$action=$post['action'];require __DIR__.'/../src/Finance/Balance/BalanceActions.php';};
$post=['area_prior_period'=>'','area_prior_revision'=>'','area_prior_sha256'=>'','area_request_key'=>str_repeat('5',32),'area_form_complete'=>'1','action'=>'balance-period-area-asset','period_id'=>$pid,'area_version'=>(string)$areas->view($pid)['version'],'closed_hash'=>$first['closed_sha256'],'area_item'=>'amplifier','measure'=>'hours','weights'=>['groupa'=>'2','groupb'=>'1'],'evidence'=>'Synthetic input test'];
$snapshot=\Kulturhus\Verein\Tests\MySqlFixture::snapshot($db);
foreach([['groupa'=>'1.5'],['groupa'=>'-1'],['groupa'=>'1000001'],['foreign'=>'1'],['groupa'=>['2']]] as $badWeights)denied(fn()=>$control(array_replace($post,['weights'=>$badWeights])),'controller rejects invalid or foreign use units');
check($snapshot===\Kulturhus\Verein\Tests\MySqlFixture::snapshot($db),'rejected asset form writes no review or audit row');
$db->exec("INSERT INTO actors(id,club_id,role,is_superuser) VALUES('asset-reader','assets','member',0); INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,inventory_access,settings_access,version) VALUES('assets','asset-read','Synthetic read','','none','read','read',0,1); INSERT INTO settings_role_assignments VALUES('asset-reader','assets','asset-read',1)");
$_SESSION=['actor'=>'asset-reader','csrf'=>'synthetic-read'];$_POST=[];$_SERVER['REQUEST_METHOD']='GET';$readHtml=$render();
check(str_contains($readHtml,'Gegenstände und ihre Nutzung')&&!str_contains($readHtml,'value="balance-period-area-asset"'),'reader sees asset usage without editing controls');
denied(fn()=>$control($post),'controller independently denies reader asset writes');
$_SESSION=['actor'=>'other','csrf'=>'synthetic-other'];denied(fn()=>$control($post),'controller denies cross-club asset request');
$_SESSION=['actor'=>'asset-admin','csrf'=>'synthetic'];putenv('VEREIN_BALANCE_PERIODS=0');denied(fn()=>$control($post),'disabled period asset-area workflow cannot write');putenv('VEREIN_BALANCE_PERIODS=1');
check(\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)===[],'shared-asset area references remain tenant bound');
echo "$n dated shared-asset area checks passed plus $base depreciation checks; synthetic MySQL only\n";
