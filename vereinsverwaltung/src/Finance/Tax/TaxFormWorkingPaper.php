<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once dirname(__DIR__, 2).'/Finance/Balance/BalanceTaxReconciliation.php';

/**
 * Year-independent local working paper for KSt 1 / Anlage Gem.
 *
 * It deliberately contains no ELSTER line numbers or ERiC identifiers. The
 * official form for the selected filing year must still be checked manually.
 */
final class TaxFormWorkingPaper
{
    public static function build(array $snapshot):array
    {
        $year=(int)($snapshot['to']??0);
        if(($snapshot['accounting_basis']??'euer')==='balance'){
            $report=$snapshot['reports'][$year]['balance']??['year'=>$year,'state'=>'missing','calculation'=>null,'issues'=>[]];
            $report['issues']=array_merge($report['issues'],$snapshot['reports'][$year]['balance_source_issues']??[],$snapshot['reports'][$year]['balance_asset_issues']??[]);
            $reconciliation=BalanceTaxReconciliation::build($report,$snapshot['year_notes'][$year]??[]);
            return $reconciliation+['fields'=>[],'basis'=>'Bilanzjournal und ausdrücklich bestätigte steuerliche Korrekturen; keine Berechnung aus dem Zahlungsüberschuss.'];
        }
        if($year===2025)return TaxForm2025::build($snapshot);
        $report=$snapshot['reports'][$year]??null;
        $notes=$snapshot['year_notes'][$year]??null;
        $spheres=$report?array_column($report['annual']['spheres'],null,'key'):[];
        $calculations=[];$issues=[];
        $ready=is_array($notes)&&($notes['record_completeness']??'')==='complete'&&($notes['corrections_status']??'')!=='unknown'&&empty($report['annual']['issues']);
        if(!$report||!$notes)$issues[]='Jahresstand '.$year.' fehlt.';
        else{
            if(!$ready)$issues[]='Jahresdaten, Kontierung oder Korrekturen sind noch offen. Die Werte bleiben Arbeitswerte.';
            $row=$spheres['4']??['label'=>'Wirtschaftlicher Geschäftsbetrieb','income_cents'=>0,'expense_cents'=>0,'result_cents'=>0,'row_indexes'=>[]];
            $add=0;$subtract=0;$corrections=[];
            foreach($notes['corrections']??[] as $correction)if(($correction['sphere']??'')==='4'){
                $amount=(int)($correction['amount_cents']??0);$corrections[]=$correction;
                if(($correction['direction']??'')==='add')$add+=$amount;else$subtract+=$amount;
            }
            $calculations[4]=['label'=>$row['label'],'recorded_income_cents'=>(int)$row['income_cents'],'recorded_expense_cents'=>(int)$row['expense_cents'],'cash_surplus_cents'=>(int)$row['result_cents'],'add_cents'=>$add,'subtract_cents'=>$subtract,'corrections'=>$corrections,'reviewed_result_cents'=>$ready?(int)$row['result_cents']+$add-$subtract:null,'formula'=>'Zahlungseinnahmen − Zahlungsausgaben + Hinzurechnungen − Abzüge','basis'=>'Zahlungsjournal '.$year.' und ausdrücklich erfasste Korrekturen; keine automatische Gewinnermittlung','row_indexes'=>$row['row_indexes']??[]];
            $issues[]='Amtliche KSt-1-/Anlage-Gem-Version für '.$year.' und die zugehörigen ELSTER-Felder manuell prüfen.';
        }
        $field=static fn(string $form,?string $line,string $label,mixed $value,string $origin,string $unit='text'):array=>['form'=>$form,'line'=>$line,'label'=>$label,'value'=>$value,'unit'=>$unit,'origin'=>$origin,'state'=>$value===null||$value===''?'unknown':'review','source'=>TaxPreparation::ELSTER];
        $tax=$snapshot['tax']['data']??[];$questions=$snapshot['declaration']??[];
        $fields=[
            'corporation'=>$field('KSt 1',null,'Bezeichnung der Körperschaft',$snapshot['club_data']['name']??null,'Gespeicherte Vereinsdaten; Formularfeld manuell abgleichen.'),
            'tax_number'=>$field('KSt 1',null,'Steuernummer',$tax['number']??null,'Gespeicherte Steuereinstellungen; Formularfeld manuell abgleichen.'),
            'period'=>$field('Anlage Gem',null,'Prüfungszeitraum',$snapshot['from'].'–'.$snapshot['to'],'Gewählter Zeitraum; amtliche Zeilen des Abgabejahres manuell prüfen.'),
            'purposes'=>$field('Anlage Gem',null,'Steuerbegünstigte Zwecke',$tax['purpose']??null,'Gespeicherter Steuerbescheid; keine Zweckkennziffer erfunden.'),
            'statute'=>$field('Anlage Gem',null,'Satzungsnachweis',null,'Satzung und Nachweis für das Abgabejahr manuell prüfen.'),
            'economic_income'=>$field('Anlage Gem',null,'Wirtschaftlicher Geschäftsbetrieb: Einnahmen',$calculations[4]['recorded_income_cents']??null,'Sphäre 4 aus dem Zahlungsjournal; Vollständigkeit und Umsatzsteuer zusätzlich prüfen.','cents'),
            'economic_result'=>$field('Anlage Gem',null,'Wirtschaftlicher Geschäftsbetrieb: Ergebnis',$calculations[4]['reviewed_result_cents']??null,'Arbeitsrechnung nach dokumentierten Korrekturen; keine festgesetzte Steuer.','cents'),
            'reserves'=>$field('Anlage Gem',null,'Rücklagen',$notes&&($notes['reserves_status']??'unknown')!=='unknown'?($notes['reserves']??[]):null,'Rücklagenart, Entwicklung und amtliche Teilzeile manuell prüfen.','rows'),
            'asset_donations'=>$field('Anlage Gem',null,'Zuwendungen zur Vermögensausstattung',($questions['asset_donations']['answer']??'unknown')==='unknown'?null:$questions['asset_donations'],'Ausdrückliche Vereinsantwort; keine Ableitung aus allgemeinen Spenden.','answer'),
            'member_benefits'=>$field('Anlage Gem',null,'Zuwendungen außerhalb des Satzungszwecks',($questions['member_benefits']['answer']??'unknown')==='unknown'?null:$questions['member_benefits'],'Ausdrückliche Vereinsantwort; rechtliche Einordnung bleibt offen.','answer'),
        ];
        foreach($fields as $entry)if($entry['state']==='unknown')$issues[]=$entry['form'].' '.$entry['label'].': noch offen.';
        return ['year'=>$year,'state'=>'working_paper','source'=>TaxPreparation::ELSTER,'guide'=>TaxPreparation::ELSTER,'fields'=>$fields,'calculations'=>$calculations,'economic_income_threshold'=>['year'=>$year,'limit_cents'=>null,'comparison'=>'not_calculated'],'issues'=>array_values(array_unique($issues)),'tax_due_cents'=>null,'transmission'=>'manual_elster_required','limitations'=>['Keine amtlichen Zeilen- oder XML-Kennziffern für dieses Abgabejahr.','Keine automatische Steuerberechnung oder Gemeinnützigkeitsentscheidung.','Keine ERiC-Prüfung und keine Übermittlung.']];
    }
}
