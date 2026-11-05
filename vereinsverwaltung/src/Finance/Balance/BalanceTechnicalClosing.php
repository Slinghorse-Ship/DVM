<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
/** Original technical accounts require actual complete closing, never a fictitious zero. */
final class BalanceTechnicalClosing {
 public static function special():array{return ['29840'=>['deferred','bs.eqLiab.otherSpecRes.other.other'],'29860'=>['deferred','bs.eqLiab.otherSpecRes.other.other'],'29870'=>['deferred','bs.eqLiab.otherSpecRes.subsidies']];}
 public static function contracts():array{return ['14980'=>['other_assets','bs.ass.currAss.receiv.other.other'],'39500'=>['liabilities','bs.eqLiab.liab.other.other']];}
 private static function rules(int $year):array {
  require_once __DIR__.'/BalanceTaxMapping.php';BalanceTaxMapping::preview($year,[]);$out=[];
  foreach(json_decode(file_get_contents(dirname(__DIR__, 2).'/../resources/ebilanz/skr42-6.9.json'),true,64,JSON_THROW_ON_ERROR)['rules'] as $rule)if(!isset($rule['years'])||in_array($year,$rule['years'],true))foreach($rule['codes'] as $c){
   if(isset($rule['technical_closing_contract'])){$e=self::contracts()[$c]??null;if(!$e||$rule['codes']!==[(string)$c]||$rule['technical_closing_contract']!==$c.'@1'||$rule['group']!==$e[0]||($rule['condition']??null)!=='detail_choice'||($rule['allowed_targets']??null)!==[$e[1]]||$rule['target']!==$e[1]||!empty($rule['disclosures'])||isset($rule['book_only']))throw new DomainException('Originale technische Abschlussregel prüfen.');}
   if(isset($rule['special_post_contract'])){$e=self::special()[$c]??null;if(!$e||$rule['codes']!==[(string)$c]||$rule['special_post_contract']!==$c.'@1'||$rule['group']!==$e[0]||($rule['condition']??null)!=='detail_choice'||($rule['allowed_targets']??null)!==[$e[1]]||$rule['target']!==$e[1]||!empty($rule['disclosures'])||isset($rule['book_only'])||isset($rule['technical_closing_contract']))throw new DomainException('Originalen Sonderpostenvertrag prüfen.');}
   $out[$c]=$rule;
  }return $out;
 }
 public static function issues(int $year,array $rows):array {
  $selected=array_filter($rows,static fn($r)=>isset((self::contracts()+self::special())[$r['skr_code']]));if(!$selected)return [];$rules=self::rules($year);$errors=[];
  foreach($selected as $r){$c=$r['skr_code'];if(isset(self::special()[$c])){if(($rules[$c]['special_post_contract']??null)!==$c.'@1'||$r['group']!=='deferred')throw new DomainException('Originalen Sonderposten und Jahresquelle prüfen.');if($r['amount_cents']<0)$errors[]='Sonderposten benötigt einen tatsächlichen nichtnegativen Bestand.';if(in_array($c,['29840','29860'],true)&&($r['opening_cents']>0||$r['amount_cents']>-$r['opening_cents']))$errors[]='Sonderposten mit Rücklageanteil: belegten Altbestand fortführen oder auflösen; keine neue Zuführung aus Kontonummer ableiten.';continue;}if(($rules[$c]['technical_closing_contract']??null)!==$c.'@1'||$r['group']!==self::contracts()[$c][0])throw new DomainException('Technisches Originalkonto und Jahresregel prüfen.');
   if($r['opening_cents']!==0||$r['closing_cents']!==0||$r['amount_cents']!==0||$r['debit_cents']!==$r['credit_cents'])$errors[]='Technisches Originalkonto '.$c.': Tatsächliche Überleitung beziehungsweise pauschale BWA-Abschreibung vollständig abschließen. Originalbuchungen berichtigen; keinen Saldo auf null ersetzen.';
  }return array_values(array_unique($errors));
 }
}
