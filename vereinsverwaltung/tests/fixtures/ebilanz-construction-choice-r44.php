<?php declare(strict_types=1);
require __DIR__.'/../../src/bootstrap.php';
require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodFilingValues.php';
require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodWholeFiling.php';
use Kulturhus\Verein\{AssetValuation as V,BalanceCalculation as C,BalanceCostAssetSources as Sources,BalanceTaxValues as T,BalanceWholeClubReport as Whole,
    BalancePeriodTaxValues as PT,BalancePeriodFilingValues as PF,BalanceTaxMapping as Mapping,BalancePeriodWholeFiling as PW};
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$code=$argv[1]??'02150';$variant=$argv[2]??'impairment';$mode=$argv[3]??'annual';$whole=in_array($mode,['whole','period-whole','mixed-whole'],true);$dated=str_starts_with($mode,'period');$mixed=str_starts_with($mode,'mixed');
if(!in_array($code,['07000', '09300', '09350', '09400', '09900', '02150', '02350', '09000', '09200', '07050', '07100', '07200', '07250', '07350', '07400', '07500', '07550', '07650', '07700', '07800', '07850', '07950'],true)||!in_array($variant,['unchanged','impairment','tax-only','multiple','writeup','writeup-release','fully','temporary'],true)
    ||($variant==='temporary'&&$code!=='09200'))throw new RuntimeException('Synthetic case only');
$kind=in_array($code,['02150','02350'],true)?'land':(in_array($code,['07000','07050', '07100', '07200', '07250', '07350', '07400', '07500', '07550', '07650', '07700', '07800', '07850', '07950'],true)?'construction':'financial');
$doc=static fn($name)=>['document_id'=>md5('synthetic-'.$name),'revision'=>1,'sha256'=>hash('sha256','synthetic-'.$name)];
$original=$doc('original');$proof=$doc('decision');$split=$doc('split');
$plan=['basis_cents'=>12000,'residual_cents'=>0,'acquired_on'=>'2025-02-15','months'=>0,'sphere'=>'4','account'=>$code,
    'reason'=>'Synthetic original cost asset','method'=>'cost','valuation_kind'=>$kind,'valuation_document'=>$original];
$event=static fn($name,$type,$on,$book,$tax,$extra=[])=>['id'=>md5('synthetic-'.$name),'on'=>$on,'kind'=>$type,'book_cents'=>$book,'tax_cents'=>$tax,
    'reason'=>'Synthetic documented '.$name,'legal_basis'=>'Synthetic evidenced HGB 253 / EStG 6 decision','document'=>$proof,'confirmed'=>true]+$extra;
if($code==='07000'&&array_key_exists(4,$argv))$plan['target']=$argv[4]==='null'?null:$argv[4];
$first=[];
if($variant!=='unchanged')$first[]=$event('first','impairment','2025-05-01',8000,$variant==='tax-only'?9000:12000,['permanent'=>$variant!=='temporary','tax_election'=>$variant==='tax-only']);
if($variant==='multiple'){
    $first[]=$event('tax-only','impairment','2025-06-01',8000,9000,['permanent'=>true,'tax_election'=>true]);
    $first[]=$event('part-one','disposal','2025-07-01',2000,2200,['cost_cents'=>3000,'allocation_document'=>$split,'no_proceeds'=>true]);
    $first[]=$event('part-two','disposal','2025-09-01',1000,1000,['cost_cents'=>1000,'allocation_document'=>$split,'no_proceeds'=>true]);
}
if($variant==='fully')$first[]=$event('all','disposal','2025-09-01',8000,12000,['cost_cents'=>12000,'allocation_document'=>$split,'no_proceeds'=>true]);
$line=static fn($a,$d,$c,$s='')=>['account'=>$a,'debit_cents'=>$d,'credit_cents'=>$c,'sphere'=>$s];
$baseChart=['bank'=>['name'=>'Synthetic bank','group'=>'cash','skr_code'=>'18000'],'capital'=>['name'=>'Synthetic retained earnings','group'=>'equity','skr_code'=>''],
    'fixed'=>['name'=>'Synthetic original cost asset','group'=>'fixed_assets','skr_code'=>$code],
    'unused-fixed'=>['name'=>'Synthetic unused chart account','group'=>'fixed_assets','skr_code'=>'06300']];
$year=2025;$previous=null;$priorReport=null;$priorReview=null;$priorValue=null;$out=[];
do{
    $period=$dated?($year===2025?['start'=>'2025-02-15','end'=>'2025-12-15']:['start'=>'2025-12-16','end'=>'2026-08-20']):['start'=>$year.'-01-01','end'=>$year.'-12-31'];$events=$year===2025?$first:[$event('recovery','writeup','2026-03-01',11000,12000,['permanent'=>false,'tax_election'=>false])];
    if($year===2026&&$variant==='writeup-release')$events[]=$event('recovery-release','disposal','2026-04-01',11000,12000,['cost_cents'=>12000,'allocation_document'=>$split,'no_proceeds'=>true]);
    $value=V::calculate(V::fromBookPlan($plan),$period,$events,$previous);
    $proposal=['year'=>$year,'accounts'=>['fixed'=>$baseChart['fixed']],'vouchers'=>[],'snapshot'=>[]];Sources::vouchers($proposal,$plan,$value,'fixed');
    $chart=$baseChart+$proposal['accounts'];if($priorReport!==null)$chart+=$priorReport['accounts'];
    $entries=$proposal['vouchers'];foreach($entries as $i=>&$entry)$entry['id']=md5('synthetic-valuation-'.$year.'-'.$i);unset($entry);
    if($year===2025){array_unshift($entries,['id'=>md5('synthetic-purchase'),'date'=>'2025-02-15','lines'=>[$line('fixed',12000,0),$line('bank',0,12000)]]);$opening=[$line('bank',$mixed?24000:12000,0),$line('capital',0,$mixed?24000:12000)];}
    else $opening=C::carryForward($priorReport['calculation'],'capital');
    $item=['item_id'=>'synthetic-cost','invoice_id'=>md5('synthetic-invoice'),'receipt_number'=>'Synthetic invoice','name'=>'Synthetic cost asset',
        'account'=>'fixed','skr_code'=>$code,'sphere'=>'4','acquired_on'=>$plan['acquired_on'],'months'=>0,'residual_cents'=>0,'method'=>'cost',
        'basis_cents'=>12000,'expected_depreciation_cents'=>0,'depreciation_cents'=>0,'acquisition_hash'=>hash('sha256','synthetic-acquisition'),
        'depreciation_hash'=>hash('sha256','synthetic-confirmation-'.$year),'cost_plan'=>$plan,'valuation'=>$value,
        'valuation_accounts'=>$proposal['snapshot']['valuation_accounts']??[]]+$value['book'];
    if(!$dated)$item=Sources::item($plan,['source_id'=>$item['invoice_id'],'source_hash'=>$item['acquisition_hash'],'snapshot'=>['entry'=>['display_number'=>'Synthetic invoice']]],
        'synthetic-cost','fixed',$year,['source_hash'=>$item['depreciation_hash'],'snapshot'=>['annual'=>['snapshot'=>json_encode(['plan'=>$plan,'valuation'=>$value])],'valuation_accounts'=>$item['valuation_accounts']]]);
    $retained=['synthetic-cost'=>$item];
    if($mixed){
        $normalPlan=['basis_cents'=>12000,'residual_cents'=>0,'months'=>60,'acquired_on'=>'2025-02-15','sphere'=>'4','account'=>'06300','method'=>'linear_monthly','reason'=>'Synthetic equipment'];
        $chart['equipment-normal']=['name'=>'Synthetic equipment','group'=>'fixed_assets','skr_code'=>'06300'];$chart['equipment-normal-depreciation']=['name'=>'Synthetic ordinary depreciation','group'=>'expense','skr_code'=>'62200'];
        $scrap=$variant==='multiple';$through=$scrap?10:12;$math=Kulturhus\Verein\AssetBooks::schedule($normalPlan,$year,$through);
        $before=$year===2025?0:2200;$closing=$math['closing_cents'];$dep=$math['depreciation_cents'];
        $normal=['item_id'=>'synthetic-equipment','invoice_id'=>md5('synthetic-equipment-invoice'),'receipt_number'=>'Synthetic equipment invoice','name'=>'Synthetic equipment',
            'account'=>'equipment-normal','skr_code'=>'06300','sphere'=>'4','acquired_on'=>'2025-02-15','months'=>60,'residual_cents'=>0,'method'=>'linear_monthly',
            'basis_cents'=>12000,'expected_depreciation_cents'=>$dep,'acquisition_hash'=>hash('sha256','synthetic-equipment-acquisition'),'depreciation_hash'=>hash('sha256','synthetic-equipment-confirmation'),
            'cost_opening_cents'=>$year===2025?0:12000,'additions_cents'=>$year===2025?12000:0,'cost_closing_cents'=>12000,
            'accumulated_opening_cents'=>$before,'depreciation_cents'=>$dep,'accumulated_closing_cents'=>12000-$closing,'book_opening_cents'=>$year===2025?0:12000-$before,'book_closing_cents'=>$closing];
        if($year===2025)$entries[]=['id'=>md5('synthetic-equipment-purchase'),'date'=>'2025-02-15','lines'=>[$line('equipment-normal',12000,0),$line('bank',0,12000)]];
        $entries[]=['id'=>md5('synthetic-equipment-depreciation-'.$year),'date'=>$year.($scrap?'-10-15':'-12-31'),'lines'=>[$line('equipment-normal-depreciation',$dep,0,'4'),$line('equipment-normal',0,$dep)]];
        if($scrap){
            $chart['equipment-normal-disposal']=['name'=>'Synthetic equipment disposal','group'=>'expense','skr_code'=>'68950'];
            $entries[]=['id'=>md5('synthetic-equipment-disposal'),'date'=>'2025-10-15','lines'=>[$line('equipment-normal-disposal',$closing,0,'4'),$line('equipment-normal',0,$closing)]];
            $normal=array_replace($normal,['cost_closing_cents'=>0,'accumulated_closing_cents'=>0,'book_closing_cents'=>0,'disposals_cents'=>12000,'accumulated_disposals_cents'=>12000-$closing,'book_disposals_cents'=>$closing,
                'disposal'=>['kind'=>'scrap','date'=>'2025-10-15','reason'=>'Synthetic equipment disposal','document_id'=>$proof['document_id'],'revision'=>1,'sha256'=>$proof['sha256'],'complete'=>true,'no_proceeds'=>true],
                'disposal_accounts'=>['68950'=>'equipment-normal-disposal'],'disposal_hash'=>hash('sha256','synthetic-equipment-disposal')]);
        }else unset($normal['basis_cents']);
        $retained['synthetic-equipment']=$normal;
    }
    $totals=['depreciation_cents'=>0]+$value['book'];foreach($totals as $field=>$_)$totals[$field]=array_sum(array_map(static fn($i)=>$i[$field]??0,$retained));
    $report=['year'=>$year,'state'=>'closed','closed_sha256'=>hash('sha256','synthetic-closed-cost-'.$year),'previous_sha256'=>$priorReport['closed_sha256']??null,
        'result_account'=>'capital','issues'=>[],'accounts'=>$chart,'opening'=>$opening,'entries'=>$entries,'calculation'=>C::build($year,$chart,$opening,$entries,$dated?$period:null),
        'asset_schedule'=>['year'=>$year,'items'=>$retained,'totals'=>$totals,'issues'=>[]]];
    if($dated){
        $report['period']=$period;$report['asset_schedule']=['period'=>$period,'issues'=>[],'totals'=>['depreciation_cents'=>0]+$value['book'],
            'items'=>['synthetic-cost'=>['item_id'=>'synthetic-cost','name'=>'Synthetic original cost asset','posted'=>true,'fixed_account'=>'fixed',
                'plan'=>$plan,'schedule'=>['period'=>$period,'depreciation_cents'=>0]+$value['book'],'valuation'=>$value,'valuation_accounts'=>$item['valuation_accounts']]]];
    }elseif($whole)$report=Whole::fromReport($report);
    $review=['year'=>$year,'journal_sha256'=>$report['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]];
    if($dated){$review['period']=$period;$review['asset_tax_plans']=[];if($whole)$review['capital_movements']=[];}
    if($priorReport!==null){$carry=$dated?PT::carryForward($priorReport,PT::build($priorReport,$priorReview,null,$whole),$report):T::carryForward($priorReport,$priorReview,$report);if($carry['entry'])$review['opening_adjustments']=[$carry['entry']];}
    $built=$dated?($whole?PF::whole($report,$review,$priorValue):PF::build($report,$review,$priorValue)):T::build($report,$review);
    $mapping=Mapping::preview($year,$built['tax']['accounts'],$dated?PW::mappingSchedule($built):$built['asset_tax']['tax_schedule']);
    $out[]=['code'=>$code,'variant'=>$variant,'values'=>$built,'automatic'=>$mapping['mapping'],'source'=>$report,'review'=>$review];$priorValue=$built;$priorReport=$report;$priorReview=$review;$previous=$value;$year++;
}while($year===2026&&in_array($variant,['writeup','writeup-release'],true));
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
