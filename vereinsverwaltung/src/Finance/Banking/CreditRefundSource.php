<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\Finance\Invoices\{OutgoingMailContext,OutgoingCorrectionSource};

/** An issued credit pays its original buyer, using current saved bank details. */
final class CreditRefundSource
{
    /** An issued original is valid evidence; an arbitrary generated preview is not. */
    public static function originals(PDO $db,string $actor,string $entry):?array
    {
        $own=!$db->inTransaction();
        try{
            if($own)$db->exec('START TRANSACTION');$access=Permissions::finance($db,$actor);
            $rows=Db::run($db,'SELECT id,kind FROM outgoing_invoices WHERE club_id=? AND entry_id=? ORDER BY id FOR SHARE NOWAIT',[$access['club_id'],$entry])->fetchAll();
            if(!$rows){if($own)$db->commit();return null;}
            if(count($rows)!==1||$rows[0]['kind']!=='credit')throw new DomainException('Eine ausgegebene Gutschrift mit ursprünglicher Rechnung auswählen.',409);
            $credit=OutgoingMailContext::read($db,$actor,$rows[0]['id']);$parent=OutgoingCorrectionSource::read($db,$actor,(string)$credit['invoice']['parent_id']);
            $c=$credit['invoice'];$p=$parent['invoice'];$current=(new Finance($db,$actor))->currentEntry($entry);
            if($current['kind']!=='expense'||(int)$current['amount_cents']!==($c['data']['total_cents']??null)||$current['display_number']!==$c['number'])throw new DomainException('Gutschrift und aktueller Erstattungsbetrag stimmen nicht mehr überein.',409);
            $contact=(string)($p['data']['contact_id']??'');
            if(($c['data']['contact_id']??'')!==$contact||($c['data']['buyer_name']??null)!==($p['data']['buyer_name']??null))throw new DomainException('Den ursprünglichen Rechnungsempfänger der Gutschrift prüfen. Kein freier Zahlungsempfänger.',409);
            $person=$contact===''?null:(Db::run($db,'SELECT id,club_id,kind,name,version,archived_at FROM contacts WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$access['club_id'],$contact])->fetch()?:null);
            $credited=0;$found=false;foreach($parent['credits'] as $row){$data=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);$credited+=(int)$data['total_cents'];if($row['id']===$c['id'])$found=true;}
            if(!$found||($c['data']['original_number']??null)!==$p['number']||($c['data']['original_date']??null)!==($p['data']['document_date']??null))throw new DomainException('Eine ausgegebene Gutschrift mit ursprünglicher Rechnung auswählen.',409);
            $source=['credit'=>array_intersect_key($credit['source'],array_flip(['invoice','document','folders','file'])),'parent'=>$parent['source'],'current_entry'=>$current,'contact'=>$person];
            $result=compact('access','credit','parent','c','p','current','contact','person','credited','source');if($own)$db->commit();return $result;
        }catch(\Throwable $failure){if($own&&$db->inTransaction())$db->rollBack();if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Rechnungsfreigabe oder Original wird gerade bearbeitet. Erneut prüfen.',503,$failure);throw $failure;}
    }
    public static function read(PDO $db,string $actor,string $entry,MemberBankVault $vault):?array
    {
        if(!$db->inTransaction())throw new DomainException('Gutschrift und Zahlungsquelle gemeinsam erneut prüfen.',503);
        Permissions::finance($db,$actor,true);$original=self::originals($db,$actor,$entry);if(!$original)return null;
        ['access'=>$access,'credit'=>$credit,'parent'=>$parent,'c'=>$c,'p'=>$p,'current'=>$current,'contact'=>$contact,'person'=>$person,'credited'=>$credited,'source'=>$source]=$original;
        if($current['status']==='cancelled'||$current['reversed_on']||!in_array($current['payment_state'],['open','partial'],true))throw new DomainException('Gutschrift und aktueller Erstattungsbetrag stimmen nicht mehr überein.',409);
        if($contact===''||!$person)throw new DomainException('Die Originalrechnung hat keinen gespeicherten Käuferkontakt. Eine tatsächliche Rückzahlung am Beleg zuordnen. Für neue Bankaufträge den Käuferkontakt vor Rechnungsausgabe wählen.',409);
        if($credited>(int)$parent['entry']['allocated_cents'])throw new DomainException('Der tatsächliche ursprüngliche Zahlungseingang deckt die ausgestellten Gutschriften nicht mehr. Zuordnung zuerst klären.',409);
        if(!in_array($person['kind'],['person','organisation'],true)||$person['archived_at']!==null)throw new DomainException('Der ursprüngliche Rechnungsempfänger ist nicht mehr aktiv. Gutschrift prüfen.',409);
        if($person['kind']==='person'){
            $saved=$vault->currentBankForCreditRefund($entry,$contact);$bank=$saved['bank'];$bankVersion=$saved['version'];
        }else{
            $saved=Db::run($db,'SELECT * FROM supplier_bank_details WHERE club_id=? AND contact_id=? FOR SHARE NOWAIT',[$access['club_id'],$contact])->fetch();
            if(!$saved||trim((string)$saved['iban'])==='')throw new DomainException('Gespeicherte Bankverbindung des ursprünglichen Rechnungsempfängers fehlt.',409);
            $bank=['holder'=>$person['name'],'iban'=>$saved['iban'],'bic'=>$saved['bic']];$bankVersion=null;
        }
        $source['bank_source']=$saved;
        $proof=hash('sha256',json_encode($source,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $summary=['id'=>$c['id'],'number'=>$c['number'],'entry_id'=>$entry,'parent_id'=>$p['id'],'parent_number'=>$p['number'],'parent_entry_id'=>$p['entry_id'],'buyer_contact_id'=>$contact,'buyer_name'=>$p['data']['buyer_name'],'current_contact_name'=>$person['name'],'original_cents'=>(int)$parent['entry']['amount_cents'],'paid_cents'=>(int)$parent['entry']['allocated_cents'],'credited_cents'=>$credited,'credit_cents'=>(int)$current['amount_cents'],'remaining_cents'=>(int)$current['remaining_cents'],'bank_version'=>$bankVersion,'source_sha256'=>$proof];
        return ['bank'=>$bank,'summary'=>$summary];
    }
}
