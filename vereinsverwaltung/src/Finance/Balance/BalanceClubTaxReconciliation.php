<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
require_once __DIR__.'/BalanceClubIncomeReconciliation.php';
require_once __DIR__.'/BalanceTaxValues.php';
require_once __DIR__.'/BalancePeriodTaxValues.php';
require_once __DIR__.'/BalancePeriodAssetExpenseSources.php';

/** Reviewed subsidiary computation, NOT a fictitious separately balanced business. */
final class BalanceClubTaxReconciliation
{
    private const REVIEW=['year','journal_sha256','income_sha256','complete','opening_adjustments','current_adjustments','asset_tax_plans','off_balance_adjustments'];

    private static function require(bool $ok,string $message='Steuerliche Nebenrechnung und zugehörige Nachweise prüfen.'):void
    {
        if(!$ok)throw new DomainException($message);
    }
    private static function add(int $a,int $b):int
    {
        $n=$a+$b;self::require(abs($n)<=BalanceCalculation::LIMIT,'Betragsgrenze der steuerlichen Nebenrechnung überschritten.');return $n;
    }
    private static function source(array $income):array
    {
        self::require(BalanceClubIncomeReconciliation::rebuild($income['scope']??[])===$income);
        if(isset($income['period']))return $income['scope']['source'];
        $r=BalanceWholeClubReport::fromReport($income['scope']['source']);
        // Keep the REAL whole-ledger counter-postings solely as arithmetic evidence.
        // This is never declared a whole taxable club or exported as its tax balance.
        unset($r['whole_club_scope']);return $r;
    }
    private static function valuationReview(array $review):array
    {
        return array_intersect_key($review,array_flip(['year','journal_sha256','complete','opening_adjustments','current_adjustments','asset_tax_plans','period']));
    }
    /** The caller binds this declaration to a permission-checked, immutable review. */
    public static function build(array $income,array $review,?array $previous=null,int $depth=0):array
    {
        self::require($depth<=100,'Zu viele Vorjahre der steuerlichen Nebenrechnung.');
        $r=self::source($income);
        $dated=isset($r['period']);
        self::require(array_keys($review)===($dated?[...self::REVIEW,'period']:self::REVIEW)&&(!$dated||$review['period']===$r['period'])&&$review['year']===$r['year']&&$review['journal_sha256']===$r['closed_sha256']
            &&$review['income_sha256']===$income['sha256']&&$review['complete']===true,'Genau diesen Bereichsabgleich und die Steuerwerte bestätigen.');
        self::require(is_array($review['asset_tax_plans'])&&is_array($review['off_balance_adjustments']));
        if($previous!==null)self::verify($previous,$depth+1);
        if($r['previous_sha256']!==null){
            self::require($previous!==null&&$income['scope']['previous']===$previous['income']['scope'],'Unveränderte steuerliche Nebenrechnung des zugehörigen Vorjahres nachweisen.');
            $carry=self::carryFromVerified($previous,$income);
            self::require($review['opening_adjustments']===($carry['entry']===null?[]:[$carry['entry']]),'Steuerliche Anfangsunterschiede unverändert aus dem Vorjahr übernehmen.');
            foreach($carry['asset_tax_plans']??[] as $id=>$plan)self::require(($review['asset_tax_plans'][$id]??null)===$plan,'Steuerliche Nutzungsdauer des Vorjahres darf nicht stillschweigend geändert werden.');
        }else self::require($previous===null,'Dieser Erstabschluss hat keinen steuerlichen Vorjahresbezug.');

        // Asset valuation must retain item-specific plans, not a manual aggregate
        // that would leave the gross asset schedule at the old value.
        $assetAccounts=[];
        foreach($r['accounts'] as $id=>$account)if($account['group']==='fixed_assets'
            ||in_array($account['skr_code'],['62000','62200','62600','48450','48490','48550','68850','68890','68950','68960'],true))$assetAccounts[$id]=true;
        foreach($r['entries'] as $entry){
            $assetEntry=false;foreach($entry['lines'] as $line)if($r['accounts'][$line['account']]['group']==='fixed_assets')$assetEntry=true;
            if($assetEntry)foreach($entry['lines'] as $line)if(in_array($r['accounts'][$line['account']]['group'],['expense','revenue'],true))$assetAccounts[$line['account']]=true;
        }
        foreach(['opening_adjustments','current_adjustments'] as $period){
            self::require(is_array($review[$period])&&array_is_list($review[$period]));
            foreach($review[$period] as $entry){
                self::require(is_array($entry)&&is_array($entry['lines']??null));
                foreach($entry['lines'] as $line)if(isset($assetAccounts[$line['account']??'']))self::require($period==='opening_adjustments'&&$previous!==null,'Anlagenwerte über den beleggebundenen Anlagenplan ändern, nicht durch eine pauschale Wertbuchung.');
            }
        }
        $previousValuation=$previous['valuation']??null;
        if($dated&&$previous!==null&&!isset($previous['income']['period']))$previousValuation['asset_tax']=BalancePeriodTaxAssetValues::calendar($previous['income'],$previousValuation,$previous['review']['asset_tax_plans']);
        $valuation=$dated?BalancePeriodTaxValues::build($r,self::valuationReview($review),$previousValuation,false,'subsidiary'):BalanceTaxValues::build($r,self::valuationReview($review));
        // Reject even mutually cancelling adjustments in exempt spheres.
        foreach($valuation['current_adjustments'] as $entry)foreach($entry['lines'] as $line){
            $pl=in_array($valuation['accounts'][$line['account']]['group'],['expense','revenue'],true);
            self::require(!$pl||$line['sphere']==='4','Steuerliche Wertänderungen ausschließlich den steuerpflichtigen Betrieben zuordnen.');
        }
        foreach(['1','2','3'] as $sphere)self::require($valuation['book']['spheres'][$sphere]===$valuation['tax']['spheres'][$sphere],'Steuerfreie Vereinsbereiche wurden durch die Überleitung verändert.');
        $rows=[];$result=0;
        $incomeAccounts=$income['accounts'];
        foreach($valuation['accounts'] as $id=>$a)if(!isset($incomeAccounts[$id])&&in_array($a['group'],['expense','revenue'],true))$incomeAccounts[$id]=$a+['taxable_book_cents'=>0];
        foreach($incomeAccounts as $id=>$a){
            $difference=$valuation['accounts'][$id]['difference_cents'];$tax=self::add($a['taxable_book_cents'],$difference);
            $rows[$id]=['name'=>$a['name'],'group'=>$a['group'],'skr_code'=>$a['skr_code'],'book_cents'=>$a['taxable_book_cents'],
                'valuation_difference_cents'=>$difference,'tax_cents'=>$tax];
            $result=self::add($result,$a['group']==='expense'?-$tax:$tax);
        }
        $taxSphere=$valuation['tax']['spheres']['4'];
        self::require($result===$taxSphere['income_cents']-$taxSphere['expense_cents']
            &&$result-$income['result']['taxable_book_cents']===$valuation['result_difference_cents']);
        // These are subsidiary-only value/capital changes. Never post their
        // contributions, withdrawals or gains into the original whole-club ledger.
        $sphere=$dated?BalancePeriodSphereTax::build($r):null;
        $sphereDifference=$sphere['valuation_difference_cents']??0;
        $result=self::add($result,$sphereDifference);
        $off=self::offBalance($r,$valuation,$review['off_balance_adjustments']);
        $payload=['schema'=>1,'kind'=>'club_tax_reconciliation','year'=>$r['year'],'journal_sha256'=>$r['closed_sha256'],
            'income'=>$income,'review'=>$review,'previous'=>$previous,'valuation'=>$valuation,'accounts'=>$rows,
            'off_balance'=>$off,'result'=>['book_cents'=>$income['result']['taxable_book_cents'],
                'valuation_difference_cents'=>self::add($valuation['result_difference_cents'],$sphereDifference),'tax_book_cents'=>$result,
                'additions_cents'=>$off['additions_cents'],'deductions_cents'=>$off['deductions_cents'],
                'taxable_income_cents'=>self::add(self::add($result,$off['additions_cents']),-$off['deductions_cents'])],
            'tax_export_ready'=>false];
        if($dated){$payload['schema']=2;$payload['period']=$r['period'];}
        if($sphere!==null)$payload['sphere_reconciliation']=$sphere;
        return $payload+['sha256'=>BalanceSegmentCalculation::hash($payload)];
    }
    public static function verify(array $value,int $depth=0):void
    {
        self::require(isset($value['income'],$value['review'])&&is_array($value['income'])&&is_array($value['review']));
        self::require(self::build($value['income'],$value['review'],$value['previous']??null,$depth)===$value);
    }
    private static function carryFromVerified(array $prior,array $income):array
    {
        self::require($income['scope']['previous']===$prior['income']['scope']);
        if(isset($income['period'])){
            $carry=BalancePeriodTaxValues::carryForward(self::source($prior['income']),$prior['valuation'],self::source($income));
            if($prior['review']['asset_tax_plans'])$carry['asset_tax_plans']=$prior['review']['asset_tax_plans'];return $carry;
        }
        return BalanceTaxValues::carryForward(self::source($prior['income']),self::valuationReview($prior['review']),self::source($income));
    }
    public static function carryForward(array $prior,array $income):array
    {
        self::verify($prior);return self::carryFromVerified($prior,$income);
    }

    /** Off-ledger corrections never change tax book values or create balances. */
    private static function offBalance(array $r,array $valuation,array $entries):array
    {
        self::require(array_is_list($entries)&&count($entries)<=200,'Außerbilanzielle Korrekturen vollständig angeben.');
        $assetExpenses=BalancePeriodAssetExpenseSources::build($r,$valuation);$sphereDep=$assetExpenses['blocked_codes'];
        $sources=[];
        foreach(['journal'=>$r['entries'],'tax'=>$valuation['current_adjustments']] as $type=>$vouchers)foreach($vouchers as $voucher)foreach($voucher['lines'] as $i=>$line){
            $group=$valuation['accounts'][$line['account']]['group'];
            if(!in_array($group,['expense','revenue'],true)||$line['sphere']!=='4')continue;
            $sources[$type.':'.$voucher['id'].':'.$i]=['account'=>$line['account'],'group'=>$group,
                'cents'=>($group==='expense'?1:-1)*($line['debit_cents']-$line['credit_cents'])];
        }
        $seen=[];$used=[];$out=[];$add=0;$deduct=0;
        // Keep existing immutable snapshots byte-identical. New evidence is part
        // of a snapshot only when an explicit correction selects an item source.
        foreach($entries as $entry){
            self::require(is_array($entry)&&is_array($entry['sources']??null),'Korrektur mit Belegbezug angeben.');
            foreach(array_keys($entry['sources']) as $key)if(is_string($key)&&str_starts_with($key,BalancePeriodAssetExpenseSources::PREFIX))$sources+=$assetExpenses['sources'];
        }
        foreach($entries as $entry){
            self::require(is_array($entry)&&array_keys($entry)===['id','kind','sources','reason','legal_basis','confirmed']
                &&is_string($entry['id'])&&preg_match('/^[a-z0-9_-]{1,40}$/D',$entry['id'])===1&&!isset($seen[$entry['id']])
                &&in_array($entry['kind'],['non_deductible_expense','tax_exempt_income'],true)&&$entry['confirmed']===true
                &&is_array($entry['sources'])&&count($entry['sources'])>0&&count($entry['sources'])<=1000,'Korrektur mit eindeutiger Nummer, Belegbezug und ausdrücklicher Bestätigung angeben.');
            foreach(['reason','legal_basis'] as $field)self::require(is_string($entry[$field])&&trim($entry[$field])!==''&&mb_strlen($entry[$field])<=1500&&!preg_match('/[\x00-\x1f]/',$entry[$field]),'Korrektur begründen und die geprüfte steuerliche Grundlage angeben.');
            $seen[$entry['id']]=true;$cents=0;$group=$entry['kind']==='non_deductible_expense'?'expense':'revenue';
            foreach($entry['sources'] as $key=>$amount){
                $source=$sources[$key]??null;
                self::require($source!==null&&$source['group']===$group&&is_int($amount)&&$amount!==0
                    &&(($amount>0)===($source['cents']>0)),'Nur passende Original- oder Steuerwertzeilen des steuerpflichtigen Bereichs verwenden.');
                self::require(isset($assetExpenses['sources'][$key])||!isset($sphereDep[$valuation['accounts'][$source['account']]['skr_code']]),'Für diese Abschreibung den Gegenstand mit seinem Steuerbetrag auswählen, nicht die ursprüngliche Buchungszeile.');
                $used[$key]=self::add($used[$key]??0,$amount);
                self::require(abs($used[$key])<=abs($source['cents']),'Belegbetrag nicht mehrfach oder über seinen Wert hinaus korrigieren.');
                $cents=self::add($cents,$amount);
            }
            // Negative results are legitimate refunds/reversals, not forced to zero.
            if($group==='expense')$add=self::add($add,$cents);else $deduct=self::add($deduct,$cents);
            $out[]=$entry+['cents'=>$cents];
        }
        return ['sources'=>$sources,'entries'=>$out,'additions_cents'=>$add,'deductions_cents'=>$deduct];
    }
    /** Read-only choices from an already verified service snapshot. Saving always rebuilds them. */
    public static function correctionSources(array $value):array
    {
        $e=BalancePeriodAssetExpenseSources::build($value['income']['scope']['source'],$value['valuation']);$sources=$value['off_balance']['sources'];
        foreach($sources as $key=>$row)if($row['account']!==null&&isset($e['blocked_codes'][$value['valuation']['accounts'][$row['account']]['skr_code']]))unset($sources[$key]);
        return $sources+$e['sources'];
    }
    public static function summary(array $value):string
    {
        self::verify($value);$m=static fn(int $n):string=>number_format($n/100,2,',','.').' EUR';$r=$value['result'];
        $lines=['Steuerliche Nebenrechnung '.(isset($value['period'])?$value['period']['start'].' bis '.$value['period']['end']:$value['year']),'',
            'Buchführungsergebnis der steuerpflichtigen Betriebe: '.$m($r['book_cents']),
            'Steuerliche Bewertungsunterschiede: '.$m($r['valuation_difference_cents']),
            'Ergebnis zu Steuerbuchwerten: '.$m($r['tax_book_cents']),
            'Außerbilanzielle Hinzurechnungen: '.$m($r['additions_cents']),
            'Außerbilanzielle Kürzungen: '.$m($r['deductions_cents']),
            'Ergebnis nach den bestätigten Korrekturen: '.$m($r['taxable_income_cents']),''];
        foreach($value['off_balance']['entries'] as $entry)$lines[]=$entry['reason'].' – '.$m($entry['cents']).' – '.$entry['legal_basis'];
        foreach($value['sphere_reconciliation']['items']??[] as $id=>$item){
            $lines[]='';$lines[]='Anlagenwechsel: '.$value['valuation']['asset_schedule']['items'][$id]['name'];
            foreach(['opening_cents'=>'Steuerwert am Anfang','additions_cents'=>'Anschaffungen','contributions_cents'=>'Einlagen','withdrawals_cents'=>'Entnahmen',
                'valuation_result_cents'=>'Bewertungsergebnis beim Wechsel','depreciation_cents'=>'Steuerliche Abschreibung','closing_cents'=>'Steuerwert am Ende'] as $key=>$label)$lines[]=$label.': '.$m($item['tax'][$key]);
            $lines[]='Änderung des steuerpflichtigen Ergebnisses: '.$m($item['valuation_difference_cents']);
            foreach($item['movements'] as $movement)$lines[]=$movement['on'].' – '.$movement['reason'];
        }
        $lines[]='Interne Vorschüsse und Rückzahlungen bleiben erfolgsneutral. Die Originalbuchführung ist unverändert.';
        $lines[]='Keine Berechnung der festzusetzenden Steuer, kein Verlustabzug oder Freibetrag und keine Übermittlungsfreigabe.';
        $lines[]='Nachweis: '.$value['sha256'];return implode("\n",$lines)."\n";
    }
}
