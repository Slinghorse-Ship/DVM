<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
require_once __DIR__.'/BalancePeriod.php';

/** Double-entry arithmetic. No inferred opening capital, cash-to-profit conversion or tax assessment. */
final class BalanceCalculation
{
    public const LIMIT=100000000000;
    public const GROUPS=[
        'fixed_assets'=>['Anlagevermögen','asset'], 'inventory'=>['Vorräte','asset'],
        'receivables'=>['Forderungen','asset'], 'cash'=>['Bank und Kasse','asset'],
        'other_assets'=>['Sonstiges Vermögen','asset'], 'prepaid'=>['Aktive Rechnungsabgrenzung','asset'],
        'equity'=>['Vereinskapital / Ergebnisvortrag','capital'], 'reserves'=>['Rücklagen','capital'], 'result_use'=>['Ergebnisverwendung','capital'],
        'provisions'=>['Rückstellungen','liability'], 'liabilities'=>['Verbindlichkeiten','liability'],
        'member_payables'=>['Erstattungen an Mitglieder','liability'], 'deferred'=>['Passive Abgrenzung und Sonderposten','liability'],
        'revenue'=>['Erträge','income'], 'expense'=>['Aufwendungen','expense'],
    ];

    public static function year(int $year):void
    {
        if($year<2000||$year>(int)date('Y'))throw new DomainException('Kalenderjahr zwischen 2000 und dem laufenden Jahr wählen.');
    }

    public static function chart(array $accounts):array
    {
        if(!$accounts||count($accounts)>500)throw new DomainException('Zwischen 1 und 500 Bilanzkonten einrichten.');
        $out=[];
        foreach($accounts as $id=>$a){
            if(!is_string($id)||!preg_match('/^[a-z][a-z0-9_-]{0,39}$/D',$id)||!is_array($a)||array_diff(array_keys($a),['name','group','skr_code']))throw new DomainException('Bilanzkonto prüfen.');
            $name=$a['name']??null;$group=$a['group']??null;$code=$a['skr_code']??'';
            if(!is_string($name)||trim($name)===''||mb_strlen($name)>160||preg_match('/[\x00-\x1f]/',$name)||!is_string($group)||!isset(self::GROUPS[$group])||!is_string($code)||($code!==''&&!preg_match('/^[0-9]{5}$/D',$code)))throw new DomainException('Kontobezeichnung, Bilanzbereich und SKR42-Konto prüfen.');
            $out[$id]=['name'=>trim($name),'group'=>$group,'skr_code'=>$code];
        }
        ksort($out,SORT_STRING);return $out;
    }

    public static function lines(array $lines,array $accounts,bool $opening=false):array
    {
        if(!array_is_list($lines)||count($lines)>1000||(!$opening&&count($lines)<2))throw new DomainException('Eine Buchung benötigt mindestens zwei Buchungszeilen.');
        $out=[];$debit=0;$credit=0;$openingSeen=[];
        foreach($lines as $line){
            if(!is_array($line)||array_diff(array_keys($line),['account','debit_cents','credit_cents','sphere']))throw new DomainException('Buchungszeile prüfen.');
            $id=$line['account']??null;$d=$line['debit_cents']??null;$c=$line['credit_cents']??null;$sphere=$line['sphere']??'';
            if(!is_string($id)||!isset($accounts[$id])||!is_int($d)||!is_int($c)||$d<0||$c<0||$d>self::LIMIT||$c>self::LIMIT||(($d>0)===($c>0)))throw new DomainException('Konto und positiven Betrag entweder im Soll oder im Haben angeben.');
            $pl=in_array($accounts[$id]['group'],['revenue','expense'],true);
            if($opening&&$pl)throw new DomainException('Eröffnungsbestände dürfen keine Ertrags- oder Aufwandskonten enthalten.');
            if($opening&&isset($openingSeen[$id]))throw new DomainException('Pro Konto genau einen Anfangssaldo angeben.');
            $openingSeen[$id]=true;
            if(!is_string($sphere)||($pl?!in_array($sphere,['1','2','3','4'],true):$sphere!==''))throw new DomainException('Vereinsbereich bei Erträgen und Aufwendungen angeben; Bestandskonten ohne Bereich buchen.');
            $debit=self::add($debit,$d);$credit=self::add($credit,$c);
            $out[]=['account'=>$id,'debit_cents'=>$d,'credit_cents'=>$c,'sphere'=>$sphere];
        }
        if($debit!==$credit)throw new DomainException('Soll und Haben stimmen nicht überein. Fehlende Beträge werden nicht automatisch ausgeglichen.');
        return $out;
    }

    private static function add(int $a,int $b):int
    {
        $sum=$a+$b;if(abs($sum)>self::LIMIT)throw new DomainException('Betragsgrenze der Bilanzrechnung überschritten.');return $sum;
    }

    /** Opening amounts are signed debit balances; every credit balance remains negative. */
    public static function build(int $year,array $accounts,array $opening,array $vouchers,?array $period=null):array
    {
        self::year($year);$accounts=self::chart($accounts);$opening=self::lines($opening,$accounts,true);
        $dates=$period===null?BalancePeriod::calendar($year):BalancePeriod::check($period,$year);
        if(!array_is_list($vouchers)||count($vouchers)>10000)throw new DomainException('Zu viele Buchungen für ein Bilanzjahr.');
        $rows=[];$spheres=[];
        foreach($accounts as $id=>$a)$rows[$id]=$a+['id'=>$id,'opening_cents'=>0,'debit_cents'=>0,'credit_cents'=>0,'closing_cents'=>0];
        foreach($opening as $l)$rows[$l['account']]['opening_cents']=self::add($rows[$l['account']]['opening_cents'],$l['debit_cents']-$l['credit_cents']);
        foreach(['1','2','3','4'] as $sphere)$spheres[$sphere]=['income_cents'=>0,'expense_cents'=>0,'result_cents'=>0];
        $seen=[];$totalDebit=0;$totalCredit=0;
        foreach($vouchers as $v){
            $date=$v['date']??null;$id=$v['id']??null;
            if(!is_string($id)||$id===''||isset($seen[$id])||!is_string($date)||!BalancePeriod::contains($dates,$date))throw new DomainException('Buchungsdatum im Abschlusszeitraum und eindeutige Buchungsnummer prüfen.');
            $seen[$id]=true;
            foreach(self::lines($v['lines']??[],$accounts) as $l){
                $r=&$rows[$l['account']];$d=$l['debit_cents'];$c=$l['credit_cents'];
                $r['debit_cents']=self::add($r['debit_cents'],$d);$r['credit_cents']=self::add($r['credit_cents'],$c);
                $totalDebit=self::add($totalDebit,$d);$totalCredit=self::add($totalCredit,$c);
                if($r['group']==='revenue')$spheres[$l['sphere']]['income_cents']=self::add($spheres[$l['sphere']]['income_cents'],$c-$d);
                if($r['group']==='expense')$spheres[$l['sphere']]['expense_cents']=self::add($spheres[$l['sphere']]['expense_cents'],$d-$c);
                unset($r);
            }
        }
        $assets=[];$capital=[];$liabilities=[];$pl=[];$issues=[];$assetTotal=0;$capitalTotal=0;$liabilityTotal=0;$income=0;$expense=0;$trial=0;
        foreach($rows as $id=>&$r){
            $r['closing_cents']=self::add($r['opening_cents'],$r['debit_cents']-$r['credit_cents']);$trial=self::add($trial,$r['closing_cents']);
            $originalSide=self::GROUPS[$r['group']][1];$side=$originalSide;$r['amount_cents']=in_array($side,['asset','expense'],true)?$r['closing_cents']:-$r['closing_cents'];$amount=$r['amount_cents'];
            require_once __DIR__.'/BalanceDirectionalClosing.php';require_once __DIR__.'/BalanceOriginalOffsets.php';require_once __DIR__.'/BalanceOriginalReclassification.php';require_once __DIR__.'/BalanceTechnicalClosing.php';$display=BalanceDirectionalClosing::presentation($year,$r)??$r;$side=self::GROUPS[$display['group']][1];$amount=$display['amount_cents'];
            if($side==='asset'){$assets[]=$display;$assetTotal=self::add($assetTotal,$amount);}
            elseif($side==='capital'){$capital[]=$display;$capitalTotal=self::add($capitalTotal,$amount);}
            elseif($side==='liability'){$liabilities[]=$display;$liabilityTotal=self::add($liabilityTotal,$amount);}
            else{$pl[]=$display;if($side==='income')$income=self::add($income,$amount);else $expense=self::add($expense,$amount);}
            if(in_array($side,['asset','liability'],true)&&$amount<0&&!self::isReceivableAllowance($year,$r)&&!BalanceOriginalOffsets::isInventoryContra($year,$r)&&!BalanceOriginalReclassification::isContra($year,$r))$issues[]=$r['name'].': Saldo auf der Gegenseite. Getrennten Ausweis prüfen und umbuchen.';
        }unset($r);
        if(array_filter($rows,static fn($r)=>in_array($r['skr_code'],['12460','12470','12480','12490','12760','12770','12960','12970'],true))){require_once __DIR__.'/BalanceAccountClosing.php';$issues=array_merge($issues,BalanceAccountClosing::receivables($year,$rows));}
        $issues=array_merge($issues,BalanceOriginalOffsets::issues($year,$rows),BalanceOriginalReclassification::issues($year,$rows),BalanceTechnicalClosing::issues($year,$rows));
        foreach($spheres as &$s)$s['result_cents']=$s['income_cents']-$s['expense_cents'];unset($s);
        $profit=self::add($income,-$expense);$passive=self::add(self::add($capitalTotal,$liabilityTotal),$profit);
        if($trial!==0||$totalDebit!==$totalCredit||$assetTotal!==$passive)throw new DomainException('Bilanz und Buchungsjournal konnten nicht abgestimmt werden.');
        return ['year'=>$year,'accounts'=>$rows,'assets'=>$assets,'capital'=>$capital,'liabilities'=>$liabilities,'profit_and_loss'=>$pl,'spheres'=>$spheres,
            'asset_total_cents'=>$assetTotal,'capital_total_cents'=>$capitalTotal,'liability_total_cents'=>$liabilityTotal,'income_cents'=>$income,'expense_cents'=>$expense,
            'annual_result_cents'=>$profit,'passive_total_cents'=>$passive,'debit_cents'=>$totalDebit,'credit_cents'=>$totalCredit,'difference_cents'=>0,'issues'=>$issues,'entry_count'=>count($vouchers)]
            +($period===null?[]:['period'=>$dates]);
    }

    private static function isReceivableAllowance(int $year,array $row):bool
    {
        if(!in_array($row['skr_code'],['12460','12470','12480','12490','12760','12770','12960','12970'],true))return false;
        require_once __DIR__.'/BalanceAccountClosing.php';return BalanceAccountClosing::isAllowance($year,$row);
    }

    /** Carry the posted result into a nominated equity account, never infer missing capital. */
    public static function carryForward(array $report,string $resultAccount):array
    {
        if(($report['accounts'][$resultAccount]['group']??'')!=='equity')throw new DomainException('Ein Vereinskapitalkonto für den Ergebnisvortrag wählen.');
        $use=0;foreach($report['accounts'] as $a)if($a['group']==='result_use')$use=self::add($use,$a['amount_cents']);
        $lines=[];
        foreach($report['accounts'] as $id=>$r){
            if(in_array($r['group'],['revenue','expense','result_use'],true))continue;
            $net=$r['closing_cents'];if($id===$resultAccount)$net=self::add($net,-self::add($report['annual_result_cents'],$use));
            if($net!==0)$lines[]=['account'=>$id,'debit_cents'=>max(0,$net),'credit_cents'=>max(0,-$net),'sphere'=>''];
        }
        return self::lines($lines,$report['accounts'],true);
    }
}
