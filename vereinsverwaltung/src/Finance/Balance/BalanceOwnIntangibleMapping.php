<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once __DIR__.'/BalanceOwnIntangibleBooks.php';
/** Resolve only replayed original project and complete actual counterpart movements. */
final class BalanceOwnIntangibleMapping
{
 public const TARGET='bs.ass.fixAss.intan.selfmade';
 public const COUNTERS=['48250'=>'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.ownWork.intan','62010'=>'is.netIncome.regular.operatingTC.deprAmort.fixAss.otherIntan','62110'=>'is.netIncome.regular.operatingTC.deprAmort.fixAss.specific.except.otherIntan','49110'=>'is.netIncome.regular.operatingTC.otherOpRevenue.revalFixAss.intan','68960'=>'is.netIncome.regular.operatingTC.otherCost.disposFixAss.bookValue.intan'];
 public static function targets():array{
  $out=['fixed_assets'=>[self::TARGET=>'Selbst geschaffene immaterielle Anlagen · HGB-Originalregister'],'revenue'=>[],'expense'=>[]];
  foreach(self::COUNTERS as $code=>$target)$out[in_array((string)$code,['48250','49110'],true)?'revenue':'expense'][$target]='Originale Entwicklungsbewegung · SKR42 '.$code;return $out;
 }
 public static function mapping(array $values):array{
  $hgb=$values['own_hgb_sources']??null;$fiscal=$values['own_intangible_sources']??null;if($hgb===null&&$fiscal===null)return [];
  BalanceOwnIntangibleValues::need(($hgb===null)!==($fiscal===null));
  if($hgb!==null){$report=BalanceWholeClubReport::rebuild($values['whole_club_scope']);BalanceOwnIntangibleValues::need(($values['annual_section']??null)==='books'&&$values['book']===$values['tax']&&$values['opening_adjustments']===[]&&$values['current_adjustments']===[]);$bound=BalanceOwnIntangibleBooks::bind($report,$hgb['sources'],$values['book']);$proof=$hgb;}
  else{
   $report=isset($values['club_scope'])?BalanceClubTaxReport::rebuild($values['club_scope']):BalanceWholeClubReport::rebuild($values['whole_club_scope']);
   $review=['year'=>$values['year'],'journal_sha256'=>$values['journal_sha256'],'complete'=>true,'opening_adjustments'=>$values['opening_adjustments'],'current_adjustments'=>$values['current_adjustments'],'own_intangible_sources'=>$fiscal['sources']];$bound=BalanceOwnIntangibleValues::bind($report,$review,$values['book'],$values['tax']);$proof=$fiscal;
  }
  BalanceOwnIntangibleValues::need($values['year']===$report['year']&&$values['journal_sha256']===$report['closed_sha256']&&BalanceOwnIntangibleValues::same($bound,$proof));$out=[];
  foreach($proof['sources'] as $p){$key=$p['journal']['account_key'];if($fiscal!==null){$a=$values['tax']['accounts'][$key];BalanceOwnIntangibleValues::need($a['opening_cents']===$a['closing_cents']&&$a['closing_cents']===0&&$a['amount_cents']===0);}$out[$key]=self::TARGET;$totals=[];
   foreach((array)$p['original_book_lines'] as $trace){$counter=$trace['counter_key'];$code=$p['journal']['chart'][$counter]['skr_code'];BalanceOwnIntangibleValues::need(isset(self::COUNTERS[$code]));$out[$counter]=self::COUNTERS[$code];foreach($trace['original']['lines'] as $l)if($l['account']===$counter){$totals[$counter]['debit']=($totals[$counter]['debit']??0)+$l['debit_cents'];$totals[$counter]['credit']=($totals[$counter]['credit']??0)+$l['credit_cents'];}}
   foreach($totals as $counter=>$s){$a=$values['book']['accounts'][$counter];BalanceOwnIntangibleValues::need($a['opening_cents']===0&&$a['debit_cents']===$s['debit']&&$a['credit_cents']===$s['credit']);if($fiscal!==null){$a=$values['tax']['accounts'][$counter];BalanceOwnIntangibleValues::need($a['opening_cents']===$a['closing_cents']&&$a['closing_cents']===0&&$a['amount_cents']===0);}}
  }
  return $out;
 }
}
