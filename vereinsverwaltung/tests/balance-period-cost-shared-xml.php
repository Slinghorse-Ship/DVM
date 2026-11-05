<?php declare(strict_types=1);
require __DIR__.'/balance-period-cost-xml.php';
require_once __DIR__.'/../src/Finance/Balance/BalancePeriodAreas.php';
use Kulturhus\Verein\{BalancePeriodAreas as Areas,BalanceSegmentCalculation as S,BalanceAssetAllocation as A,BalanceTaxFiling as F};
use Kulturhus\Verein\Tests\MySqlFixture;
$areas=new Areas($db,'admin');$weights=['food'=>2,'shop'=>3];
$units=['ideal'=>['name'=>'Synthetic ideal','sphere'=>'1'],'capital'=>['name'=>'Synthetic capital','sphere'=>'2'],
    'culture'=>['name'=>'Synthetic culture','sphere'=>'3'],'food'=>['name'=>'Synthetic food','sphere'=>'4'],'shop'=>['name'=>'Synthetic shop','sphere'=>'4']];
$protected=[];foreach(['balance_periods','balance_period_sources','balance_period_events','asset_book_plans','asset_book_years'] as $table)$protected[$table]=MySqlFixture::rows($db,$table);
$prepare=static function(string $target,array $report)use($areas,$units,$weights):array{
    $areas->prepare($target,0,$report['closed_sha256'],$units,'Synthetic shared cost areas');$state=$areas->view($target);
    foreach(S::sources($state['source']) as $key=>$row){
        if(str_starts_with($key,'opening:')&&$state['prior']!==null)continue;
        $areas->edit($target,$state['version'],$report['closed_sha256'],'line',['key'=>$key,'parts'=>A::cents($row['cents'],$weights)],'Synthetic original cost posting including retained reversal');$state=$areas->view($target);
    }
    if($state['prior']===null)foreach(['land','financial'] as $item){$areas->edit($target,$state['version'],$report['closed_sha256'],'asset',['item'=>$item,'weights'=>$weights,'measure'=>'uses'],'Synthetic same-sphere original cost use');$state=$areas->view($target);}
    check($state['proof']['complete'],'actual dated shared cost and original posting amounts reconcile');
    $areas->confirm($target,$state['version'],$report['closed_sha256'],$state['sha256'],true,'Synthetic original cost use board approval');return $areas->view($target);
};
$firstAreas=$prepare($pid,$firstReport);$before=MySqlFixture::snapshot($db);
foreach(['reader','other'] as $actor)denied(fn()=>(new Areas($db,$actor))->edit($pid,$firstAreas['version'],$firstReport['closed_sha256'],'asset',['item'=>'land','weights'=>$weights,'measure'=>'uses'],'Synthetic denied use'),'role and tenant guard for dated cost use '.$actor);
denied(fn()=>$areas->edit($pid,$firstAreas['version'],$firstReport['closed_sha256'],'asset-use-change',['item'=>'land','on'=>'2025-08-01','weights'=>['food'=>1,'shop'=>1]],'Synthetic unsupported changed use'),'unsupported cost use change cannot replace original rounded shares');
check(MySqlFixture::snapshot($db)===$before,'rejected dated cost use leaves source and review rows unchanged');
$cid=$svc->createPeriod($pid,F::COMBINED_SOURCE);$filing=$tax->get($cid);$review=$filing['form_data']['balance_preparation']['review'];unset($review['year'],$review['journal_sha256'],$review['period']);$review['complete']=true;
$profile=array_replace($profile,['tax_privileged'=>true,'whole_business_name'=>'Synthetic whole dated shared cost club','whole_book_standard'=>'HGBM']);
$svc->save($cid,1,$profile,$map,$review,$map);$svc->validate($cid,2);$generated=$tax->get($cid);$values=$svc->assertCurrent($generated);
check($values['whole']['book']===$values['whole']['tax'],'dated shared whole books preserve original corrected cost valuation');
$originalItems=$values['taxable']['asset_schedule']['items'];$originalIds=array_keys($originalItems);sort($originalIds);
check($originalIds===['financial','land'],'dated taxable report retains exactly the two original cost assets');
foreach($originalItems as $item=>$original)check($original===$firstReport['asset_schedule']['items'][$item],'dated taxable original asset and every valuation component unchanged '.$item);
$originalClosing=array_sum(array_map(static fn($item)=>$item['schedule']['book_closing_cents'],$originalItems));
check($originalClosing===21000,'dated taxable total retains both whole original assets');
check($values['taxable']['asset_tax']['tax_schedule']['totals']['book_closing_cents']===24000,'separate dated tax total retains the evidenced 3000 cent difference');
file_put_contents(dirname(__DIR__,2).'/cost-period-shared.xml',$generated['xml_data']);
$followingAreas=$prepare($next,$nextReport);
foreach($firstAreas['proof']['assets']['schedules'] as $unit=>$schedule)foreach($schedule['items'] as $item=>$part)foreach(['cost','accumulated','book'] as $field)
    check($followingAreas['proof']['assets']['schedules'][$unit]['items'][$item][$field.'_opening_cents']===$part[$field.'_closing_cents'],'dated original shared opening '.$unit.' '.$item.' '.$field);
$bundle=(new Kulturhus\Verein\BalancePeriodExport($db,'admin'))->build($next);$csv=array_map(static fn($line)=>str_getcsv($line,';','"',''),array_filter(explode("\n",str_replace("\r",'',$bundle['files']['anlagennutzung.csv']))));
check(count($csv)===5&&count($csv[0])===19&&count(array_filter($csv,static fn($row)=>count($row)!==19))===0,'dated cost use CSV retains every valuation component with matching columns');
$column=array_search('Wertaufholung Zeitraum Cent',$csv[0],true);$recovery=0;foreach(array_slice($csv,1) as $row)$recovery+=(int)$row[$column];check($recovery===2000,'exported unit recoveries sum to original dated recovery');
$render=static function(string $actor)use($db,$next):string{$_SESSION=['actor'=>$actor];$_GET=['period_id'=>$next,'areas'=>'1'];$_POST=[];$_SERVER['REQUEST_METHOD']='GET';ob_start();require __DIR__.'/../views/Finance/Balance/balance-periods.php';return ob_get_clean();};
$html=$render('admin');check(str_contains($html,'Wertaufholung im Zeitraum')&&str_contains($html,'Nutzungsanteile werden unverändert vorgetragen')&&!str_contains($html,'Nutzung ab einem Datum ändern'),'actual dated cost use view displays valuation and supported carry controls');
check(!str_contains($render('reader'),'Nutzung speichern'),'reader has no dated cost write controls');
foreach($protected as $table=>$rows)check(MySqlFixture::rows($db,$table)===$rows,'dated shared cost preserves original '.$table);
echo "$n actual dated shared cost workflow checks passed; synthetic MySQL, no transport\n";
