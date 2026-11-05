<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
/** Read-only guidance; never changes approval or payment state. */
final class InvoiceNextStep
{
 public static function forDocument(PDO $db,string $actor,string $id):array
 {
  $doc=(new InvoiceInbox($db,$actor))->document($id);$links=$doc['entry_links'];$entry=null;$approval=[];$report=[];
  if(count($links)===1){$entry=(new Finance($db,$actor))->entry($links[0]['source_id']);if($entry['kind']==='expense'){$approval=(new InvoiceApprovals($db,$actor))->state($entry['id']);$report=(new InvoicePaymentReports($db,$actor))->summary($entry['id']);}}
  return self::describe($doc,$entry,$approval,$report,(new Finance($db,$actor))->canWrite());
 }
 public static function describe(array $doc,?array $entry,array $approval,array $report,bool $canWrite=true):array
 {
  $out=['title'=>'','text'=>'','label'=>'','route'=>[],'anchor'=>''];
  if($doc['state']==='archived')return array_replace($out,['title'=>'Rechnung archiviert','text'=>'Dieser Originalbeleg ist archiviert. Die bisherigen Zuordnungen bleiben nachvollziehbar.']);
  if(count($doc['entry_links'])>1)return array_replace($out,['title'=>'Mehrere Belege zugeordnet','text'=>'Dieser Originalbeleg gehört zu mehreren Vorgängen. Prüfen Sie jeden zugeordneten Beleg einzeln; ein gemeinsamer Zahlungsstatus wäre irreführend.']);
  if(!$entry)return array_replace($out,['title'=>'Rechnung noch nicht erfasst','text'=>'Prüfen Sie die Angaben am Original und speichern Sie die Rechnung.','label'=>'Angaben prüfen','anchor'=>'rechnung-erfassen']);
  $route=['view'=>'finance','tab'=>'receipts','entry'=>$entry['id']];
  if(($entry['kind']??'expense')!=='expense')return array_replace($out,['title'=>'Zuordnung prüfen','text'=>'Der verknüpfte Vorgang ist keine Ausgabenrechnung. Prüfen Sie die Belegzuordnung.','label'=>'Beleg ansehen','route'=>$route]);
  if(in_array($entry['payment_state'],['cancelled','reversed'],true))return array_replace($out,['title'=>$entry['payment_state']==='reversed'?'Rechnung gegengebucht':'Beleg verworfen','text'=>'Dieser Vorgang ist geschlossen. Prüfen Sie bei Bedarf den erhaltenen Verlauf.','label'=>'Belegverlauf ansehen','route'=>$route]);
  if($entry['payment_state']==='paid')return array_replace($out,['title'=>'Bezahlt – Kontoumsätze vollständig zugeordnet','text'=>'Die Zahlung ist tatsächlich zugeordnet. Prüfen Sie vor dem Export weiterhin die steuerliche Sphäre und das Steuerkonto.','label'=>'Belegdaten ansehen','route'=>$route]);
  $reported=($report['state']??'')==='reported';$prefix=$reported?'Als bezahlt gemeldet – die tatsächliche Kontozuordnung fehlt noch. ':'';
   if(($approval['status']??'pending')!=='approved')return array_replace($out,['title'=>$reported?'Zahlung gemeldet – prüfen':(($approval['status']??'')==='clarified'?'Klärung offen':'Prüfen'),'text'=>$prefix.'Originalrechnung, Empfänger, IBAN und Betrag prüfen.','label'=>$canWrite?'Prüfen':'Prüfstand ansehen','route'=>$route,'anchor'=>'invoice-approval']);
   return array_replace($out,['title'=>$reported?'Zahlung gemeldet – zuordnen':'Zahlungszuordnung offen','text'=>$prefix.($canWrite?'Vorhandenen Kontoumsatz am zugeordneten Beleg prüfen und zuordnen. Eine Überweisung ist nur aus diesem Beleg und nach den dortigen Prüfungen möglich.':'Zahlungsstand und Zuordnungen am ursprünglichen Beleg ansehen.'),'label'=>$canWrite?'Zahlungsbereich öffnen':'Zahlungsstand ansehen','route'=>$route+($canWrite?['payment_match'=>'1']:['payment_history'=>'1'])]);
 }
}
