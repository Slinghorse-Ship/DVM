<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
/** Public form references, not ERiC identifiers. Never merges several calendar years. */
final class TaxForm2025
{
 public const SOURCE='https://www.elster.de/eportal/helpGlobal?themaGlobal=help_kst_2025';
 public const GUIDE='https://download.elster.de/download/dokumente/Leitfaden_Mein_ELSTER_fuer_Vereine.pdf';
 public static function build(array $snapshot):array
 {
  if(($snapshot['accounting_basis']??'euer')!=='euer')return ['year'=>(int)$snapshot['to'],'state'=>'balance_working_paper','fields'=>[],'calculations'=>[],'issues'=>['Bilanzierung ist als Arbeitsstand vorbereitet. Die amtliche Formularzuordnung wird erst nach vollständiger Bilanzprüfung freigegeben.'],'transmission'=>'unavailable'];
  $year=(int)$snapshot['to'];
  if($year!==2025)return ['year'=>$year,'state'=>'unsupported_year','fields'=>[],'calculations'=>[],'issues'=>['Amtliche Formularzuordnung ist ausschließlich für 2025 geprüft. Andere Jahre bleiben Arbeitsunterlagen.'],'transmission'=>'unavailable'];
  $report=$snapshot['reports'][2025]??null;$notes=$snapshot['year_notes'][2025]??null;
  if(!$report||!$notes)throw new DomainException('Jahresstand 2025 fehlt.');
  $annual=$report['annual'];$spheres=array_column($annual['spheres'],null,'key');$calculations=[];$issues=[];
  $ready=$notes['record_completeness']==='complete'&&$notes['corrections_status']!=='unknown'&&!$annual['issues'];
  if(!$ready)$issues[]='Jahresdaten oder Kontierung/Korrekturen sind noch offen. Rechenwerte sind keine vollständigen Steuererklärungswerte.';
  foreach(['1','2','3','4'] as $sphere){
   $r=$spheres[$sphere];$add=0;$subtract=0;$corrections=[];
   foreach($notes['corrections'] as $c)if($c['sphere']===$sphere){$corrections[]=$c;if($c['direction']==='add')$add+=$c['amount_cents'];else $subtract+=$c['amount_cents'];}
   $calculations[$sphere]=['label'=>$r['label'],'recorded_income_cents'=>$r['income_cents'],'recorded_expense_cents'=>$r['expense_cents'],'cash_surplus_cents'=>$r['result_cents'],'add_cents'=>$add,'subtract_cents'=>$subtract,'corrections'=>$corrections,'reviewed_result_cents'=>$ready?$r['result_cents']+$add-$subtract:null,'formula'=>'Zahlungseinnahmen − Zahlungsausgaben + Hinzurechnungen − Abzüge','basis'=>'Zahlungsjournal 2025 und ausdrücklich erfasste Korrekturen; keine automatische Gewinnermittlung','row_indexes'=>$r['row_indexes']];
  }
  $field=static fn(string $form,?string $line,string $label,mixed $value,string $origin,string $unit='text'):array=>['form'=>$form,'line'=>$line,'label'=>$label,'value'=>$value,'unit'=>$unit,'origin'=>$origin,'state'=>$value===null||$value===''?'unknown':'review','source'=>self::SOURCE];
  $tax=$snapshot['tax']['data'];$q=$snapshot['declaration'];
  $fields=[
   'corporation'=>$field('KSt 1',null,'Bezeichnung der Körperschaft',$snapshot['club_data']['name']??null,'Gespeicherte Vereinsdaten'),
   'tax_number'=>$field('KSt 1',null,'Steuernummer',$tax['number']??null,'Gespeicherte Steuereinstellungen'),
   'period'=>$field('Anlage Gem','1','Prüfungszeitraum',$snapshot['from'].'–'.$snapshot['to'],'Gewählter Prüfungszeitraum; Zahlenfelder betreffen nur das letzte Jahr'),
   'purposes'=>$field('Anlage Gem','2–4','Steuerbegünstigte Zwecke',$tax['purpose']??null,'Gespeicherter Steuerbescheid; keine selbst erfundene Zweck-Kennziffer'),
   'statute'=>$field('Anlage Gem','5–6','Satzungsdatum / bereits eingereichte Satzung',null,'Satzungsnachweis manuell in Mein ELSTER prüfen; Änderungsantwort allein liefert kein Datum'),
   'economic_income'=>$field('Anlage Gem','11–14','Wirtschaftliche Geschäftsbetriebe: Einnahmen einschließlich Umsatzsteuer',$ready?$calculations['4']['recorded_income_cents']:null,'Sphäre 4, Zahlungsjahr 2025; Beteiligungen, Sonderermittlung und Vollständigkeit zusätzlich prüfen','cents'),
   'economic_result'=>$field('Anlage Gem','11–14','Wirtschaftliche Geschäftsbetriebe: Ergebnis zur Prüfung',$calculations['4']['reviewed_result_cents'],'Sphäre 4 nach dokumentierter Überleitung; keine Zuordnung zu einer ungeprüften Einzelkennziffer','cents'),
   'reserves'=>$field('Anlage Gem',null,'Rücklagen (Teilseite 8)',$notes['reserves_status']==='unknown'?null:$notes['reserves'],'Gesonderte Entwicklung; Rücklagenart und amtliche Teilzeile manuell prüfen','rows'),
   'asset_donations'=>$field('Anlage Gem','63','Vermögenszuführungen',$q['asset_donations']['answer']==='unknown'?null:$q['asset_donations'],'Ausdrückliche Antwort, keine Ableitung aus allgemeinen Spenden','answer'),
   'member_benefits'=>$field('Anlage Gem','74–75','Zuwendungen außerhalb des Satzungszwecks',$q['member_benefits']['answer']==='unknown'?null:$q['member_benefits'],'Ausdrückliche Antwort samt Erläuterung','answer'),
  ];
  $income=$fields['economic_income']['value'];$result=$fields['economic_result']['value'];
  $threshold=$income===null?'unknown':($income>4500000?'exceeded':'not_exceeded');
  if($threshold==='exceeded')$issues[]='2025: Einnahmengrenze von 45.000 € überschritten. Gewinnermittlung, Anlagen GK/ZVE und Gewerbesteuer gesondert prüfen (KSt 1 Zeile 10 / Anlage Gem Zeile 12).';
  if($result!==null&&$result>500000&&$threshold==='exceeded')$issues[]='Zusätzlich liegt das übergeleitete Ergebnis über 5.000 €. Das ist ein Prüfhinweis auf weitere Erklärungen, keine berechnete Körperschaftsteuer.';
  foreach($fields as $f)if($f['state']==='unknown')$issues[]=$f['form'].' '.$f['label'].': noch offen.';
  return ['year'=>2025,'state'=>'working_mapping','source'=>self::SOURCE,'guide'=>self::GUIDE,'fields'=>$fields,'calculations'=>$calculations,'economic_income_threshold'=>['year'=>2025,'limit_cents'=>4500000,'comparison'=>$threshold],'issues'=>array_values(array_unique($issues)),'tax_due_cents'=>null,'transmission'=>'unavailable','limitations'=>['Keine vollständige Formular-/Sonderfallabdeckung und keine technische ELSTER-Kennziffernzuordnung.','Keine Übertragung von 2025-Grenzen auf andere Jahre.','SKR42-Konten liefern die dokumentierte Sphäre, keine automatische Gemeinnützigkeitsentscheidung.']];
 }
}
