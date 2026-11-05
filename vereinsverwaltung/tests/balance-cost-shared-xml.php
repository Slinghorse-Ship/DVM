<?php declare(strict_types=1);
require __DIR__.'/balance-cost-xml.php';
use Kulturhus\Verein\{BalanceSegments,BalanceSegmentCalculation as S,BalanceAssetAllocation as A,BalanceCostAssetAllocation as Cost,
    BalanceCostAssetSources as Sources,BalanceTaxFiling};
use Kulturhus\Verein\Tests\MySqlFixture;
require_once __DIR__.'/../src/Finance/Balance/BalanceSegmentExport.php';
$segments=new BalanceSegments($db,'admin');$weights=['food'=>2,'shop'=>3];
$units=['ideal'=>['name'=>'Synthetic ideal','sphere'=>'1'],'capital'=>['name'=>'Synthetic capital','sphere'=>'2'],
    'culture'=>['name'=>'Synthetic culture','sphere'=>'3'],'food'=>['name'=>'Synthetic food','sphere'=>'4'],'shop'=>['name'=>'Synthetic shop','sphere'=>'4']];
$allocate=static function(array $report,array $rows=[])use($weights):array{
    $posts=[];
    foreach($report['asset_schedule']['items'] as $item){
        $v=$item['valuation'];$projection=Cost::split($v,$weights);
        foreach($report['entries'] as $entry){
            $fixed=false;$other=null;foreach($entry['lines'] as $line){if($line['account']===$item['account'])$fixed=true;if(str_starts_with($line['account'],'src_asset_valuation_'))$other=$line['account'];}
            if(!$fixed)continue;
            if($other===null){$posts[$entry['id']]=A::cents($entry['lines'][0]['debit_cents']+$entry['lines'][0]['credit_cents'],$weights);continue;}
            foreach($v['source']['events'] as $event){$type=Sources::codes($v['source']['plan']['kind'],$event['kind'],$event['permanent']??true);
                if($event['on']!==$entry['date']||($item['valuation_accounts'][$type['code']]??null)!==$other)continue;
                $parts=[];foreach($projection['events'][$event['id']] as $u=>$p){$amount=$type['group']==='revenue'?$p['writeup_cents']:$p['impairment_cents']+$p['book_disposals_cents'];if($amount)$parts[$u]=$amount;}
                $posts[$entry['id']]=$parts;
            }
        }
    }
    foreach(S::sources($report) as $key=>$row)if(!isset($rows[$key]))$rows[$key]=['parts'=>$posts[$row['voucher']]??['food'=>$row['cents']],'reason'=>'Synthetic original shared cost posting evidence'];
    return $rows;
};
$protected=[];foreach(['balance_years','balance_entries','asset_book_plans','asset_book_years'] as $table)$protected[$table]=MySqlFixture::rows($db,$table);
$segments->save(2025,0,$units,$allocate($firstReport));$before=MySqlFixture::snapshot($db);
denied(fn()=>$segments->asset(2025,1,'land',$weights,'uses','Synthetic original evidence',false),'cost use requires explicit evidence confirmation');
denied(fn()=>(new BalanceSegments($db,'reader'))->asset(2025,1,'land',$weights,'uses','Evidence',true),'reader cannot write cost use');
denied(fn()=>(new BalanceSegments($db,'other'))->asset(2025,1,'land',$weights,'uses','Evidence',true),'foreign club cannot write cost use');
check(MySqlFixture::snapshot($db)===$before,'rejected cost use leaves all source and review rows unchanged');
foreach(['land','financial'] as $item){$state=$segments->get(2025);$segments->asset(2025,$state['version'],$item,$weights,'uses','Synthetic original same-sphere cost use',true);}
$state=$segments->get(2025);check($state['allocation']['complete']&&!$state['current_approval'],'actual shared cost values and individually dated postings reconcile before approval');
$segments->confirm(2025,$state['version'],true,'Synthetic original cost use reviewed by board');
$files=Kulturhus\Verein\BalanceSegmentExport::files($segments->get(2025));
$csv=array_map(static fn($line)=>str_getcsv($line,';','"',''),array_filter(explode("\n",str_replace("\r",'',$files['bereiche/anlagenanteile.csv']))));
check(count($csv)===5&&count($csv[0])===22&&count(array_filter($csv,static fn($row)=>count($row)!==22))===0,'cost use CSV retains every original movement with matching columns');
$lossColumn=array_search('Wertminderung im Jahr Cent',$csv[0],true);$loss=0;foreach(array_slice($csv,1) as $row)$loss+=(int)$row[$lossColumn];
check($loss===7000,'exported unit impairments sum to both original annual losses');
$render=static function(string $actor,int $year=2025)use($db):string{$_SESSION=['actor'=>$actor];$_GET=['asset_item'=>'land'];$_POST=[];ob_start();require __DIR__.'/../views/Finance/Balance/balance-segments.php';return ob_get_clean();};
$html=$render('admin');check(str_contains($html,'Wertminderung im Jahr')&&str_contains($html,'data-label="Wertaufholung"')&&!str_contains($html,'Gleichmäßig über die Nutzungsdauer'),'actual annual cost use view displays evidenced valuation instead of an invented linear plan');
check(!str_contains($render('reader'),'Nutzungsaufteilung speichern'),'reader sees cost values without use write controls');
$priorUse=$segments->get(2025)['allocation']['asset_schedules'];
$cid=$svc->create(2025,BalanceTaxFiling::COMBINED_SOURCE);$profile=array_replace($profile,['tax_privileged'=>true,'whole_business_name'=>'Synthetic full shared cost club','whole_book_standard'=>'HGBM']);
$svc->save($cid,1,$profile,$map,['complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]],$map);$svc->validate($cid,2);$generated=$tax->get($cid);$values=$svc->assertCurrent($generated);
check($values['whole']['book']===$values['whole']['tax'],'actual shared whole original cost books remain unchanged');
check($values['taxable']['asset_schedule']['totals']['book_closing_cents']===14000,'tax projection keeps whole book value instead of fractional acquisition costs');
check($generated['form_data']['balance_preparation']['generated']['asset_items']===2,'normal combined generator retains two whole original cost assets');
file_put_contents(dirname(__DIR__,2).'/cost-shared.xml',$generated['xml_data']);
$segments->carry(2026,0,true);$state=$segments->get(2026);$segments->save(2026,$state['version'],$units,$allocate($nextReport,$state['allocation']['allocations']));$nextState=$segments->get(2026);
check($nextState['allocation']['complete'],'actual following cost year reconciles recovery and full release with original use');
$html=$render('admin',2026);check(str_contains($html,'unverändert aus dem vorherigen Abschluss')&&!str_contains($html,'Nutzungsaufteilung speichern'),'carried cost use view offers only the supported unchanged original shares');
foreach($priorUse as $u=>$schedule)foreach($schedule['items'] as $id=>$part)foreach(['cost','accumulated','book'] as $f)
    check($nextState['allocation']['asset_schedules'][$u]['items'][$id][$f.'_opening_cents']===$part[$f.'_closing_cents'],'carried cost opening remains original '.$u.' '.$id.' '.$f);
denied(fn()=>$segments->asset(2026,$nextState['version'],'land',['food'=>1,'shop'=>1],'uses','Changed use',true),'following cost weights cannot silently replace confirmed prior use');
foreach($protected as $table=>$rows)check(MySqlFixture::rows($db,$table)===$rows,'shared cost workflow preserves original '.$table);
echo "$n shared cost MySQL workflow checks passed; synthetic MySQL, no transport\n";
