<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once __DIR__.'/BalanceSourceRead.php';
use DomainException;
require_once __DIR__.'/BalanceTaxAssetValues.php';
require_once __DIR__.'/BalanceClubTaxReport.php';
require_once __DIR__.'/BalanceWholeClubReport.php';
require_once __DIR__.'/BalanceCapitalComparison.php';
require_once __DIR__.'/BalancePartnershipHoldings.php';
require_once __DIR__.'/BalanceOwnIntangibleValues.php';
require_once __DIR__.'/BalanceOwnIntangibleBooks.php';
require_once __DIR__.'/BalancePoolOriginalValues.php';
require_once dirname(__DIR__, 2).'/Finance/Assets/OriginalPensionOffsets.php';
require_once dirname(__DIR__, 2).'/Finance/Assets/OriginalSpecialTax7g.php';
require_once dirname(__DIR__, 2).'/Finance/Assets/OriginalSpecialTax7b.php';

/** Separate tax-value ledger; never changes the commercial journal or duplicates off-balance corrections. */
final class BalanceTaxValues
{
    /** Roll forward reviewed differences, including their prior profit effect, not current-year income. */
    public static function carryForward(array $prior,array $priorReview,array $report):array
    {
        $values=self::build($prior,$priorReview);
        self::build($report,['year'=>$report['year'],'journal_sha256'=>$report['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[],'asset_tax_plans'=>$priorReview['asset_tax_plans']??[]]);
        if($report['year']!==$prior['year']+1||($report['previous_sha256']??null)!==$prior['closed_sha256']
            ||($report['result_account']??null)!==($prior['result_account']??null))throw new DomainException('Vorjahr und Ergebnisvortrag stimmen nicht mit dem aktuellen Abschluss überein.');
        $chart=BalanceCalculation::chart($report['accounts']);
        foreach($prior['accounts'] as $id=>$account)if(!isset($chart[$id])||$account['group']!==$chart[$id]['group']||$account['skr_code']!==$chart[$id]['skr_code'])throw new DomainException('Konten des Vorjahres wurden verändert. Steuerlichen Vortrag gesondert prüfen.');
        $net=static function(array $lines)use($chart):array{$out=array_fill_keys(array_keys($chart),0);foreach($lines as $line)$out[$line['account']]+=$line['debit_cents']-$line['credit_cents'];ksort($out);return $out;};
        $book=$net(BalanceCalculation::carryForward($values['book'],$report['result_account']));
        if($book!==$net($report['opening']))throw new DomainException('Anfangsbestände entsprechen nicht dem festgeschriebenen Vorjahr.');
        $tax=$net(BalanceCalculation::carryForward($values['tax'],$report['result_account']));$lines=[];
        foreach($tax as $id=>$amount){$difference=$amount-$book[$id];if($difference)$lines[]=['account'=>$id,'debit_cents'=>max(0,$difference),'credit_cents'=>max(0,-$difference),'sphere'=>''];}
        if($lines)$lines=BalanceCalculation::lines($lines,$chart,true);
        $result=['source_values_sha256'=>$values['sha256'],'source_journal_sha256'=>$prior['closed_sha256'],
            'entry'=>$lines?['id'=>'carry-'.substr($values['sha256'],0,32),'reason'=>'Unveränderter steuerlicher Wertvortrag aus '.$prior['year'].'.','lines'=>$lines]:null];
        if(!empty($priorReview['asset_tax_plans']))$result['asset_tax_plans']=$priorReview['asset_tax_plans'];
        return $result;
    }

    public static function build(array $report,array $review):array
    {
        return BalanceSourceRead::run(static fn()=>self::buildFromSources($report,$review));
    }
    private static function buildFromSources(array $report,array $review):array
    {return self::values($report,$review,false);}

    /** Original whole-club books for the checked composite output. */
    public static function books(array $report,?array $ownSources=null,?array $pensionSources=null):array
    {
        if(!isset($report['whole_club_scope']))throw new DomainException('Originalbuchwerte benötigen den vollständigen Vereinsabschluss.');
        $own=false;$cost=false;foreach($report['asset_schedule']['items']??[] as $item){if(($item['method']??null)===OwnIntangiblePlans::METHOD)$own=true;if(($item['method']??null)==='cost'||($item['skr_code']??null)==='01500')$cost=true;}
        if($own)BalanceOwnIntangibleValues::need(is_array($ownSources)&&BalanceOwnIntangibleValues::same(BalanceOwnIntangibleBooks::bind($report,$ownSources['sources'],$report['calculation']),$ownSources));
        else BalanceOwnIntangibleValues::need($ownSources===null);
        return self::values($report,['year'=>$report['year'],'journal_sha256'=>$report['closed_sha256'],'complete'=>true,
            'opening_adjustments'=>[],'current_adjustments'=>[]]+($own?['own_hgb_sources'=>$ownSources]:[])+($pensionSources!==null?['pension_hgb_sources'=>$pensionSources]:[]),$cost||$own||$pensionSources!==null);
    }

    private static function values(array $report,array $review,bool $books):array
    {
        if(isset($review['own_hgb_sources']))BalanceOwnIntangibleValues::need($books);
        if(isset($review['pension_hgb_sources']))OriginalPensionOffsets::need($books);
        if(isset($review['pension_offsets']))OriginalPensionOffsets::need(!$books);
        if(isset($review['special_tax_7g']))OriginalSpecialTax7g::need(!$books);
        if(isset($review['special_tax_7b']))OriginalSpecialTax7b::need(!$books);
        // Period arithmetic is explicit; never silently export it as Jan 1 .. Dec 31.
        if(array_key_exists('period',$report)||(is_array($report['calculation']??null)&&array_key_exists('period',$report['calculation'])))throw new DomainException('Zeitraumbezogenen Abschluss erst nach vollständiger Anbindung von Anlagen, Vorträgen und Steuerexport freigeben.');
        if(isset($report['whole_club_scope'])&&(isset($report['club_scope'])||BalanceWholeClubReport::rebuild($report['whole_club_scope'])!==$report))throw new DomainException('Gesamtvereinsbilanz stimmt nicht mit dem vollständigen Quelljournal überein.');
        if(isset($report['club_scope'])&&BalanceClubTaxReport::rebuild($report['club_scope'])!==$report)throw new DomainException('Steuerlicher Teilausweis stimmt nicht mit der freigegebenen Vereinsaufteilung überein.');
        if(($report['state']??'')!=='closed'||!empty($report['issues'])||!preg_match('/^[a-f0-9]{64}$/D',$report['closed_sha256']??''))throw new DomainException('Festgeschriebenes, abgestimmtes Bilanzjournal erforderlich.');
        $year=$report['year'];$chart=BalanceCalculation::chart($report['accounts']);
        $book=BalanceCalculation::build($year,$chart,$report['opening'],$report['entries']);
        if($book!==$report['calculation']||$book['issues'])throw new DomainException('Bilanzwerte stimmen nicht mit den Buchungen überein.');
        if(($review['journal_sha256']??null)!==$report['closed_sha256']||($review['year']??null)!==$year||($review['complete']??null)!==true)throw new DomainException('Steuerwerte für genau diesen Jahresabschluss bestätigen.');
        $assetTax=$books?['review'=>$review,'evidence'=>null]:BalanceTaxAssetValues::prepare($report,$review);$review=$assetTax['review'];
        $opening=$review['opening_adjustments']??null;$current=$review['current_adjustments']??null;
        if(!is_array($opening)||!array_is_list($opening)||!is_array($current)||!array_is_list($current)||count($opening)+count($current)>200)throw new DomainException('Steuerliche Wertänderungen vollständig angeben, auch wenn keine vorliegen.');
        $openingNet=array_fill_keys(array_keys($chart),0);$seen=[];
        foreach($report['opening'] as $line)$openingNet[$line['account']]+=$line['debit_cents']-$line['credit_cents'];
        foreach($opening as $entry){
            self::entry($entry,$seen);
            foreach(BalanceCalculation::lines($entry['lines']??[],$chart,true) as $line)$openingNet[$line['account']]+=$line['debit_cents']-$line['credit_cents'];
        }
        $taxOpening=[];
        foreach($openingNet as $id=>$amount)if($amount!==0)$taxOpening[]=['account'=>$id,'debit_cents'=>max(0,$amount),'credit_cents'=>max(0,-$amount),'sphere'=>''];
        $vouchers=$report['entries'];
        foreach($current as $entry){
            self::entry($entry,$seen);
            $vouchers[]=['id'=>'tax-adjustment-'.$entry['id'],'date'=>$year.'-12-31','lines'=>$entry['lines']??[]];
        }
        $sourceSpheres=[];
        foreach($vouchers as $entry)foreach($entry['lines'] as $line)if(($line['sphere']??'')!=='')$sourceSpheres[$line['sphere']]=true;
        $sourceSpheres=array_map('strval',array_keys($sourceSpheres));sort($sourceSpheres,SORT_STRING);
        $tax=BalanceCalculation::build($year,$chart,$taxOpening,$vouchers);
        if($tax['issues'])throw new DomainException(implode(' ',$tax['issues']));
        require_once __DIR__.'/BalanceAccountClosing.php';BalanceAccountClosing::calculative($year,$chart,$report['entries']);BalanceAccountClosing::calculative($year,$chart,$vouchers);
        if($assetTax['evidence']!==null)BalanceDisposalAccounts::checkMovements($tax['accounts'],$assetTax['evidence']['tax_schedule']['items'],$year);
        if($assetTax['evidence']!==null)BalanceCostAssetSources::checkMovements($tax['accounts'],$assetTax['evidence']['tax_schedule']['items']);
        $rows=[];
        foreach($book['accounts'] as $id=>$row)$rows[$id]=['name'=>$row['name'],'group'=>$row['group'],'skr_code'=>$row['skr_code'],
            'book_opening_cents'=>$row['opening_cents'],'tax_opening_cents'=>$tax['accounts'][$id]['opening_cents'],
            'book_cents'=>$row['amount_cents'],'tax_cents'=>$tax['accounts'][$id]['amount_cents'],
            'difference_cents'=>$tax['accounts'][$id]['amount_cents']-$row['amount_cents']];
        $payload=['year'=>$year,'journal_sha256'=>$report['closed_sha256'],'accounts'=>$rows,'book'=>$book,'tax'=>$tax,
            'opening_adjustments'=>$opening,'current_adjustments'=>$current,
            'result_difference_cents'=>$tax['annual_result_cents']-$book['annual_result_cents'],'source_spheres'=>$sourceSpheres,
            // The generator checks the retained schedule against both ledgers.
            // Missing historical schedules remain missing, never a fabricated zero.
            'asset_schedule'=>$report['asset_schedule']??null];
        if(array_filter($book['accounts'],static fn($a)=>$a['group']==='result_use')){
            $source=[];foreach(BalanceWholeClubReport::FIELDS as $key){if(!array_key_exists($key,$report))throw new DomainException('Ergebnisverwendung benötigt den vollständigen ursprünglichen Abschluss.');$source[$key]=$report[$key];}
            $payload['income_use_source']=['schema'=>1,'source'=>$source];
        }
        if($assetTax['evidence']!==null)$payload['asset_tax']=$assetTax['evidence'];
        if($books)$payload['annual_section']='books';
        if(isset($review['own_hgb_sources']))$payload['own_hgb_sources']=$review['own_hgb_sources'];
        if(isset($report['club_scope']))$payload['club_scope']=$report['club_scope'];
        if(isset($report['whole_club_scope']))$payload['whole_club_scope']=$report['whole_club_scope'];
        if(isset($report['whole_club_scope'])&&!$books)$payload['capital_comparison']=BalanceCapitalComparison::build($report,$review,$tax);
        $holdings=BalancePartnershipHoldings::bind($report,$review,$book,$tax);
        if($holdings!==null)$payload['partnership_holdings']=$holdings;
        $own=BalanceOwnIntangibleValues::bind($report,$review,$book,$tax);
        if($own!==null)$payload['own_intangible_sources']=$own;
        if(isset($review['pool_original_sources'])){
            if($books||$opening||$current||$book!==$tax||!empty($assetTax['evidence']))throw new DomainException('Abweichende Sammelpostenwerte benötigen den unabhängigen Überleitungsweg.');
            $payload['pool_original_sources']=BalancePoolOriginalValues::bind($report,$review['pool_original_sources']);
        }
        $pension=$review[$books?'pension_hgb_sources':'pension_offsets']??null;
        if($pension!==null)$payload['pension_offsets']=OriginalPensionOffsets::bind($report,$pension,$book,!$books);
        if(isset($review['basis_reduction_6b'])){require_once dirname(__DIR__, 2).'/Finance/Assets/OriginalBasisReduction6b.php';$payload['basis_reduction_6b']=OriginalBasisReduction6b::bind($report,$review['basis_reduction_6b']);}
        if(isset($review['basis_reduction_7g'])){require_once dirname(__DIR__, 2).'/Finance/Assets/OriginalBasisReduction7g.php';$payload['basis_reduction_7g']=OriginalBasisReduction7g::bind($report,$review['basis_reduction_7g']);}
        if(isset($review['special_tax_7b']))$payload['special_tax_7b']=OriginalSpecialTax7b::bind($report,$review['special_tax_7b']);
        if(isset($review['special_tax_7g']))$payload['special_tax_7g']=OriginalSpecialTax7g::bind($report,$review['special_tax_7g']);
        if(isset($review['tax_equalization'])){require_once dirname(__DIR__, 2).'/Finance/Assets/OriginalTaxEqualization.php';$payload['tax_equalization']=OriginalTaxEqualization::bind($report,$review['tax_equalization']);OriginalTaxEqualization::check($payload);}
        $payload=OwnIntangibleStorage::normal($payload);
        return $payload+['sha256'=>hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
            'transmission'=>'unavailable'];
    }

    private static function entry(mixed $entry,array &$seen):void
    {
        if(!is_array($entry)||array_diff(array_keys($entry),['id','reason','lines'])||!is_string($entry['id']??null)||!preg_match('/^[a-z0-9_-]{1,40}$/D',$entry['id'])||isset($seen[$entry['id']])||!is_string($entry['reason']??null)||trim($entry['reason'])===''||mb_strlen($entry['reason'])>1500)throw new DomainException('Jede Wertänderung benötigt eine eindeutige Nummer, Begründung und ausgeglichene Buchungszeilen.');
        $seen[$entry['id']]=true;
    }
}
