<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;
final class ContributionMail
{
    public static function build(PDO $db,string $club,string $entryId,string $type):array
    {
        if(!in_array($type,['invoice','reminder'],true))throw new DomainException('Beitragsmail prüfen.');
        $own=!$db->inTransaction();
        try{
        if($own)$db->exec('START TRANSACTION');
        $q=$db->prepare('SELECT i.document_id,e.display_number,e.amount_cents,e.due_date,e.document_date,e.notes FROM contribution_invoices i JOIN finance_entries e ON e.club_id=i.club_id AND e.id=i.entry_id WHERE i.club_id=? AND i.entry_id=? FOR SHARE NOWAIT');$q->execute([$club,$entryId]);$r=$q->fetch();
        if(!$r)throw new DomainException('Beitragsrechnung fehlt.');
        $r['paid']=PaymentFunding::paid($db,$club,$entryId,(int)$r['amount_cents'],true);
        $q=$db->prepare('SELECT snapshot FROM document_files WHERE club_id=? AND document_id=? ORDER BY revision DESC LIMIT 1 FOR SHARE NOWAIT');$q->execute([$club,$r['document_id']]);$snapshot=json_decode((string)$q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
        $values=$snapshot['values']??[];
        // Use the invoice's bank details and current balance; never redirect payment of an issued invoice.
        $remaining=(int)$r['amount_cents']-(int)$r['paid'];$values['ZAHLBETRAG']=ContributionRates::label($remaining);$values['RECHNUNGSNUMMER']=$r['display_number'];$values['VERWENDUNGSZWECK']=$r['display_number'];
        $q=$db->prepare("SELECT payload FROM club_settings WHERE club_id=? AND section='texts_contributions' FOR SHARE NOWAIT");$q->execute([$club]);$raw=$q->fetchColumn();$templates=$raw?json_decode($raw,true,64,JSON_THROW_ON_ERROR):SettingsSchema::templates('texts_contributions');
        $template=$templates[$type];$subject=SettingsSchema::interpolate($template['subject'],$values);$text=SettingsSchema::interpolate($template['message'],$values);
        // Always keep the reference visible for bank reconciliation even in a customized message.
        $text.="\n\nVerwendungszweck bei Überweisung (Rechnungsnummer): ".$r['display_number']."\nOffener Betrag: ".$values['ZAHLBETRAG'];
        $result=['remaining_cents'=>$remaining,'subject'=>$subject,'text'=>$text,'attach_pdf'=>$type==='invoice'||!empty($template['attach_pdf']),'html'=>SettingsSchema::html($text),'club_name'=>(string)($values['VEREINSNAME']??'Mein Verein')];if($own)$db->commit();return $result;
        }catch(\Throwable$failure){if($own&&$db->inTransaction())$db->rollBack();if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Beitragsrechnung oder Zahlung wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);throw $failure;}
    }
}
