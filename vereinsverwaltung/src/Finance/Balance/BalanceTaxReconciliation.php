<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Auditable result reconciliation. Does not invent tax adjustments or an XBRL mapping. */
final class BalanceTaxReconciliation
{
    public static function build(array $report,array $notes):array
    {
        $year=(int)($report['year']??0);BalanceCalculation::year($year);
        if(isset($notes['year'])&&(int)$notes['year']!==$year)throw new DomainException('Jahresangaben gehören zu einem anderen Bilanzjahr.');
        $issues=$report['issues']??[];$rows=[];$calculation=$report['calculation']??null;
        if(!$calculation)return ['year'=>$year,'state'=>'incomplete','calculations'=>[],'issues'=>['Bilanzjournal fehlt.'],'tax_due_cents'=>null,'transmission'=>'unavailable'];
        if(($report['state']??'')!=='closed')$issues[]='Bilanzjournal zuerst abschließen.';
        if(($notes['record_completeness']??'unknown')!=='complete')$issues[]='Vollständigkeit der Jahresunterlagen bestätigen.';
        $status=$notes['corrections_status']??'unknown';$corrections=$notes['corrections']??[];
        if(!in_array($status,['unknown','none','listed'],true)||!is_array($corrections)||!array_is_list($corrections)||count($corrections)>TaxYearNotes::MAX_CORRECTIONS)throw new DomainException('Steuerliche Korrekturen prüfen.');
        if($status==='unknown')$issues[]='Steuerliche Hinzurechnungen und Abzüge prüfen.';
        if(($status==='listed'&&!$corrections)||($status!=='listed'&&$corrections))throw new DomainException('Korrekturen und Bestätigung widersprechen sich.');
        foreach(['1','2','3','4'] as $sphere){
            $s=$calculation['spheres'][$sphere];
            $rows[$sphere]=['label'=>TaxCatalog::SPHERES[$sphere],'income_cents'=>$s['income_cents'],'expense_cents'=>$s['expense_cents'],'book_result_cents'=>$s['result_cents'],'add_cents'=>0,'subtract_cents'=>0,'corrections'=>[],'reconciled_result_cents'=>null];
        }
        foreach($corrections as $c){
            if(!is_array($c)||array_diff(array_keys($c),['sphere','direction','amount_cents','reason'])||!isset($rows[$c['sphere']??''])||!in_array($c['direction']??'', ['add','subtract'],true)||!is_int($c['amount_cents']??null)||$c['amount_cents']<0||$c['amount_cents']>BalanceCalculation::LIMIT||!is_string($c['reason']??null)||trim($c['reason'])===''||mb_strlen($c['reason'])>1500)throw new DomainException('Jede steuerliche Korrektur benötigt Betrag, Vereinsbereich, Richtung und Begründung.');
            $rows[$c['sphere']][$c['direction'].'_cents']+=$c['amount_cents'];$rows[$c['sphere']]['corrections'][]=$c;
        }
        foreach($rows as &$r){
            $result=$r['book_result_cents']+$r['add_cents']-$r['subtract_cents'];
            if(max($r['add_cents'],$r['subtract_cents'],abs($result))>BalanceCalculation::LIMIT)throw new DomainException('Betragsgrenze der steuerlichen Überleitung überschritten.');
            if(!$issues)$r['reconciled_result_cents']=$result;
            $r['recorded_income_cents']=$r['income_cents'];$r['recorded_expense_cents']=$r['expense_cents'];
            $r['reviewed_result_cents']=$r['reconciled_result_cents'];
            $r['formula']='Erträge − Aufwendungen + Hinzurechnungen − Abzüge';
            $r['basis']='Festgeschriebenes Bilanzjournal '.$year.' und bestätigte steuerliche Korrekturen.';
        }unset($r);
        return ['year'=>$year,'state'=>$issues?'incomplete':'reconciled_working_paper','journal_sha256'=>$report['closed_sha256']??null,'calculations'=>$rows,'issues'=>array_values(array_unique($issues)),'tax_due_cents'=>null,'transmission'=>'unavailable'];
    }
}
