<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
/** Preparation questions only. No ELSTER field IDs, transmission or eligibility decision. */
final class TaxDeclarationGuide
{
 public const SOURCE_YEAR=2025;
 public const SOURCE_URL='https://www.elster.de/eportal/helpGlobal?themaGlobal=help_kst_2025';
 public static function questions():array
 {
  return [
   'statute_changed'=>['question'=>'Wurde die Satzung im Prüfungszeitraum geändert?','help'=>'Datum und zugehörigen Satzungsnachweis nennen. Prüfen, ob die aktuelle Fassung dem Finanzamt vorliegt.','notes_when'=>'yes'],
   'purpose_fulfilled'=>['question'=>'Wurden die satzungsmäßigen Zwecke tatsächlich verwirklicht?','help'=>'Tätigkeiten und vorhandene Jahresberichte nennen. Eine Satzung allein beschreibt noch nicht die tatsächliche Tätigkeit.','notes_when'=>'always'],
   'member_benefits'=>['question'=>'Gab es unentgeltliche Zuwendungen oder Vergünstigungen außerhalb der Erfüllung des Satzungszwecks?','help'=>'Auch Leistungen an Mitglieder prüfen. Bei Ja: Anlass, Art und Betrag erläutern; die Antwort allein bewertet die Zulässigkeit nicht.','notes_when'=>'yes'],
   'economic_activities'=>['question'=>'Gab es wirtschaftliche Tätigkeiten mit Einnahmen, zum Beispiel Verkauf oder Bewirtung?','help'=>'Tätigkeiten nennen. Die Einordnung als Zweckbetrieb oder steuerpflichtiger Geschäftsbetrieb wird anschließend getrennt geprüft.','notes_when'=>'yes'],
   'reserves'=>['question'=>'Bestanden oder entstanden Rücklagen im Prüfungszeitraum?','help'=>'Art, Zweck und Entwicklung erläutern und die gesonderte Rücklagenaufstellung beifügen.','notes_when'=>'yes'],
   'asset_donations'=>['question'=>'Wurden Zuwendungen ausdrücklich zur Ausstattung oder Erhöhung des Vereinsvermögens erhalten?','help'=>'Bestimmung durch die zuwendende Person und den Nachweis nennen; gewöhnliche Spenden nicht automatisch so behandeln.','notes_when'=>'yes'],
   'funds_forwarded'=>['question'=>'Wurden Mittel zur Vermögensausstattung anderer Körperschaften weitergegeben?','help'=>'Empfänger, Zweck, Betrag und Nachweise nennen. Das ist eine Prüfungsfrage, keine Bestätigung der Zulässigkeit.','notes_when'=>'yes'],
  ];
 }
 public static function normalize(array $input):array
 {
  $questions=self::questions();
  if(array_diff(array_keys($input),array_keys($questions)))throw new DomainException('Unbekannte Frage in der Steuer-Vorbereitung.');
  $result=[];
  foreach($questions as $id=>$question){
   $row=$input[$id]??[];
   if(!is_array($row)||array_diff(array_keys($row),['answer','note']))throw new DomainException('Antwort in der Steuer-Vorbereitung prüfen.');
   $answer=$row['answer']??'unknown';$note=$row['note']??'';
   if(!is_string($answer)||!in_array($answer,['unknown','no','yes'],true)||!is_string($note)||!mb_check_encoding($note,'UTF-8')||mb_strlen($note)>2000||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$note))throw new DomainException('Antwort oder Erläuterung in der Steuer-Vorbereitung prüfen (höchstens 2.000 Zeichen).');
   $result[$id]=['answer'=>$answer,'note'=>trim($note)];
  }
  return $result;
 }
 /** Completeness hints are not fiscal approval and never turn unknown into no. */
 public static function issues(array $input):array
 {
  $rows=self::normalize($input);$issues=[];
  foreach(self::questions() as $id=>$q){$row=$rows[$id];
   if($row['answer']==='unknown')$issues[]=$q['question'].' – Noch zu klären.';
   elseif($row['note']===''&&($q['notes_when']==='always'||$row['answer']==='yes'))$issues[]=$q['question'].' – Bitte erläutern und passende Nachweise nennen.';
  }
  return $issues;
 }
}
