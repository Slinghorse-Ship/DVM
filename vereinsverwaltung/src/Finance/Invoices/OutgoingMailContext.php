<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Invoices;

use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Documents,Permissions,SettingsSchema,OutgoingInvoices,PaymentFunding};

/** Current financial authority and payment facts, with the actual immutable issued file. */
final class OutgoingMailContext
{
    public static function read(PDO $db,string $actorId,string $id):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new DomainException('Eigene ausgegebene Rechnung auswählen.',404);
        $own=!$db->inTransaction();
        try{
            if($own)$db->exec('START TRANSACTION');
            try{$actor=Permissions::finance($db,$actorId);}catch(DomainException$failure){throw new DomainException('Aktuelle Finanzleseberechtigung erforderlich.',403,$failure);}
            $invoice=Db::run($db,'SELECT * FROM outgoing_invoices WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$actor['club_id'],$id])->fetch();
            if(!$invoice)throw new DomainException('Eigene ausgegebene Rechnung auswählen.',404);
            $invoice['data']=json_decode($invoice['payload'],true,64,JSON_THROW_ON_ERROR);
            if(!in_array($invoice['kind'],['invoice','credit','cancellation'],true)||$invoice['state']!=='issued'||!is_string($invoice['document_id'])||$invoice['document_id']==='')throw new DomainException('Eine ausgegebene Rechnung oder ihr ausgegebenes Korrekturdokument auswählen.',409);
            $documents=new Documents($db,$actorId);$read=$documents->currentFinancialOriginal($invoice['document_id'],1);$document=$read['document'];
            if($read['trashed'])throw new DomainException('Ursprüngliches Rechnungs-PDF zuerst aus dem Papierkorb wiederherstellen.',409);
            $file=Db::run($db,'SELECT * FROM document_files WHERE club_id=? AND document_id=? AND revision=1 FOR SHARE NOWAIT',[$actor['club_id'],$invoice['document_id']])->fetch();
            if(!$file||$file['sha256']!==$read['file']['sha256'])throw new DomainException('Ursprüngliches Rechnungs-PDF bitte prüfen lassen.',503);
            if(!str_starts_with($file['bytes'],'%PDF-')||!hash_equals($file['sha256'],hash('sha256',$file['bytes']))||$file['mime']!=='application/pdf')throw new DomainException('Ursprüngliches Rechnungs-PDF bitte prüfen lassen.',503);
            $snapshot=json_decode($file['snapshot'],true,64,JSON_THROW_ON_ERROR);
            $title=match($invoice['kind']){'credit'=>'Gutschrift','cancellation'=>'Stornorechnung',default=>'Rechnung'}.' '.$invoice['number'];
            if(!is_array($snapshot)||($snapshot['draft']??null)!==false||($snapshot['general_invoice']??null)!==true||($snapshot['title']??null)!==$title||($snapshot['payment_reference']??null)!==($invoice['kind']==='invoice'?$invoice['number']:'')||$document['kind']!=='invoice'||$document['origin']!=='generated'||(int)$file['revision']!==1||$file['document_id']!==$invoice['document_id'])throw new DomainException('Das ursprüngliche ausgegebene Rechnungs-PDF fehlt.',503);
            if(($invoice['data']['invoice_format']??'')==='hybrid-xrechnung'){
                $proof=$snapshot['einvoice']??[];$xml=$proof['xml']??null;
                if(!is_string($xml)||$xml===''||($proof['kosit']['state']??null)!=='valid'||($proof['validation']['state']??null)!=='valid'||!hash_equals((string)($proof['xml_sha256']??''),hash('sha256',$xml))||!hash_equals((string)($proof['validation']['sha256']??''),$file['sha256']))throw new DomainException('Die ursprüngliche geprüfte E-Rechnung fehlt oder ist beschädigt.',503);
            }
            $delivery=Db::run($db,'SELECT * FROM outgoing_invoice_deliveries WHERE club_id=? AND invoice_id=? FOR SHARE NOWAIT',[$actor['club_id'],$id])->fetch()?:null;
            $payment=null;
            if($invoice['kind']==='invoice'){
                $entry=Db::run($db,'SELECT e.*,r.reversed_on,r.reason AS reversal_reason FROM finance_entries e LEFT JOIN finance_reversals r ON r.club_id=e.club_id AND r.entry_id=e.id WHERE e.club_id=? AND e.id=? FOR SHARE NOWAIT',[$actor['club_id'],$invoice['entry_id']])->fetch();
                if(!$entry||$entry['kind']!=='income'||$entry['status']==='cancelled'||$entry['reversed_on']!==null||(int)$entry['amount_cents']!==($invoice['data']['total_cents']??null)||$entry['display_number']!==$invoice['number'])throw new DomainException('Aktiver ursprünglicher Rechnungsbeleg stimmt nicht mehr überein.',409);
                // Preserve original allocation identities while checking the current net payment ledger.
                $allocations=Db::run($db,'SELECT l.id,l.transaction_id,l.cents,l.voided_at,returned.id AS return_id FROM finance_allocations l LEFT JOIN finance_transactions returned ON returned.club_id=l.club_id AND returned.reversal_of=l.transaction_id WHERE l.club_id=? AND l.entry_id=? ORDER BY l.id,returned.id FOR SHARE NOWAIT',[$actor['club_id'],$entry['id']])->fetchAll();
                $paid=PaymentFunding::paid($db,$actor['club_id'],$entry['id'],(int)$entry['amount_cents'],true);
                $payment=['entry'=>array_intersect_key($entry,array_flip(['id','display_number','kind','status','amount_cents','version','reversed_on'])),'allocations'=>$allocations,'allocated_cents'=>$paid,'open_cents'=>max(0,(int)$entry['amount_cents']-$paid)];
            }
            $values=is_array($snapshot['values']??null)?$snapshot['values']:[];
            $values['RECHNUNGSNUMMER']=$invoice['number'];$values['VERWENDUNGSZWECK']=$invoice['number'];
            if($payment!==null)$values['ZAHLBETRAG']=number_format($payment['open_cents']/100,2,',','.').' €';
            $clubName=(string)($invoice['data']['issuer']['club']['name']??$values['VEREINSNAME']??'Mein Verein');
            $defaults=['subject'=>(string)($snapshot['title']??('Dokument '.$invoice['number'])),'message'=>'Guten Tag '.$invoice['data']['buyer_name'].",\n\nim Anhang finden Sie das Dokument ".$invoice['number'].'.'];$defaultSource='neutral-original-document';
            if($invoice['kind']==='invoice'&&$payment['open_cents']>0){
                $original=$invoice['data']['issuer']['texts']['invoice']??null;
                if(is_array($original)&&is_string($original['subject']??null)&&is_string($original['message']??null)){$defaults=array_intersect_key($original,array_flip(['subject','message']));$defaultSource='issued-original-template';}
                elseif(is_string($snapshot['text']['subject']??null)&&is_string($snapshot['text']['message']??null)){$defaults=['subject'=>$snapshot['text']['subject'],'message'=>$snapshot['text']['message']];$defaultSource='issued-rendered-text';}
            }
            $source=['invoice'=>$invoice,'document'=>array_intersect_key($document,array_flip(['id','kind','origin','state','version','folder_id','scopes','admission_only'])),'folders'=>array_map(static fn(array$row):array=>array_intersect_key($row,array_flip(['id','version','parent_id','scope','admission_only'])),$read['folders']),'file'=>array_intersect_key($file,array_flip(['document_id','revision','name','mime','sha256']))+['bytes'=>strlen($file['bytes']),'snapshot_sha256'=>hash('sha256',$file['snapshot'])],'payment'=>$payment,'delivery'=>$delivery,'values'=>$values,'club_name'=>$clubName,'default_source'=>$defaultSource];
            if($own)$db->commit();
            return compact('invoice','document','file','snapshot','delivery','payment','values','clubName','defaults','source');
        }catch(\Throwable$failure){if($own&&$db->inTransaction())$db->rollBack();if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Rechnung oder Versandauftrag wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);throw$failure;}
    }
}
