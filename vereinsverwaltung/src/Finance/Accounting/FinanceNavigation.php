<?php declare(strict_types=1);
namespace Kulturhus\Verein;
final class FinanceNavigation
{
 public static function groups():array
 {
  $child=static fn(string $label,string $view,string $tab,?string $section=null):array=>['label'=>$label,'route'=>['view'=>$view,'tab'=>$tab]+($section!==null?['section'=>$section]:[])];
  $sets=[
   ['bank','Bank & Kasse',[$child('Bank & Kasse','finance','bank')]],
   ['inbox','Rechnungseingang',[$child('Rechnungseingang','finance','inbox')]],
   ['contributions','Beiträge',[$child('Beitragsabrechnung','finance','contributions'),$child('Beitragsmails & Erinnerungen','finance','contribution-mails'),$child('Lastschriften','finance','debit')]],
   ['receipts','Belege & Spenden',[$child('Belege','finance','receipts'),$child('Ausgangsrechnungen','finance','outgoing'),$child('Geldspenden','donations','money'),$child('Sachspenden','donations','goods'),$child('Zuwendungsbescheinigungen','settings','donation-forms'),$child('Sammelbestätigungen & Aufwandsverzicht','finance','donation-collections')]],
   ['reports','Auswertungen & Steuer',[$child('Auswertungen','finance','reports'),$child('Vereinsvermögen','inventory','assets'),$child('Anschaffungen & Abschreibungen (AfA)','finance','book-values'),$child('Export & DATEV','finance','export'),$child('Jahresabschluss & Steuern','finance','tax'),$child('Steuerkonten','settings','tax-accounts'),$child('Buchungsschlüssel','settings','booking-keys'),$child('Steuerdaten & Freistellung','settings','','tax'),$child('Finanz-Vorauswahl & Kontenanzeige','settings','','finance_preferences')]],
  ];
  return array_map(static fn(array $row):array=>['id'=>$row[0],'label'=>$row[1],'route'=>$row[2][0]['route'],'children'=>$row[2]],$sets);
 }
 public static function active(array $route):?string
 {
  $view=(string)($route['view']??'');$tab=(string)($route['tab']??'');
  if($view==='finance'&&$tab==='')$tab='bank';if($view==='donations'&&$tab==='')$tab='money';
  if($view==='finance'&&in_array($tab,['money','goods'],true))$view='donations';
  foreach(self::groups() as $group)foreach($group['children'] as $child){$r=$child['route'];if($view===$r['view']&&$tab===$r['tab']&&(!isset($r['section'])||($route['section']??'')===$r['section']))return $group['id'];}
  return null;
 }
 public static function visible(WorkspaceAccess $access):array
 {
  $out=[];foreach(self::groups() as $group){$group['children']=array_values(array_filter($group['children'],static fn(array $item):bool=>$access->allows($item['route'])));if(!$group['children'])continue;$group['route']=$group['children'][0]['route'];$out[]=$group;}return $out;
 }
}
