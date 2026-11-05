<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Invoices\Reminder;
use DateTimeImmutable;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Finance,Permissions};
use Kulturhus\Verein\Finance\Invoices\OutgoingCorrectionSource;

/** An original claim cannot support a reminder while another credit refund remains open. */
final class ReminderSource
{
    public static function read(PDO $db,string $actor,string $invoice,string $day):array
    {
        if(!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$day))throw new DomainException('Ganzes Erinnerungsdatum prüfen.',422);
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$day);
        if(!$date||$date->format('Y-m-d')!==$day)throw new DomainException('Ganzes Erinnerungsdatum prüfen.',422);
        if($day>(new DateTimeImmutable('now',new \DateTimeZone('Europe/Berlin')))->format('Y-m-d'))throw new DomainException('Künftigen Zahlungsstand nicht als Erinnerung bestätigen.',422);
        $own=!$db->inTransaction();
        try{
            if($own)$db->exec('START TRANSACTION');
            try{Permissions::finance($db,$actor);}catch(DomainException $failure){if($failure->getCode()===503||$failure->getMessage()==='Ein Speichervorgang läuft bereits. Bitte erneut versuchen.')throw $failure;throw new DomainException('Aktuelle Finanzleseberechtigung erforderlich.',403,$failure);}
            $original=OutgoingCorrectionSource::read($db,$actor,$invoice);
            if($original['source']['document']['state']!=='active')throw new DomainException('Ursprüngliches Rechnungsdokument zuerst wiederherstellen.',409);
            $accountId=$original['invoice']['data']['issuer']['bank']['account_id']??'';if(!is_string($accountId)||$accountId==='')throw new DomainException('Ursprüngliches Rechnungskonto bitte prüfen lassen.',503);
            $account=Db::run($db,'SELECT * FROM finance_accounts WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$original['invoice']['club_id'],$accountId])->fetch();$accountSettings=Db::run($db,'SELECT * FROM finance_account_settings WHERE club_id=? AND account_id=? FOR SHARE NOWAIT',[$original['invoice']['club_id'],$accountId])->fetch()?:null;
            if(!$account||$account['kind']!=='offline'||!(int)($accountSettings['active']??1))throw new DomainException('Ursprüngliches Rechnungskonto ist kein aktives Bankkonto. Keine neue Zahlungsaufforderung erstellen.',409);
            $finance=new Finance($db,$actor);$entry=$finance->currentEntry($original['invoice']['entry_id']);
            if(!in_array($entry['payment_state'],['open','partial'],true)||$entry['remaining_cents']<=0)throw new DomainException('Keine offene ursprüngliche Rechnungsforderung für eine Erinnerung.',409);
            if($entry['due_date']===''||$entry['due_date']>=$day)throw new DomainException('Erinnerung erst nach der tatsächlich fälligen ursprünglichen Rechnung prüfen.',422);
            $credits=[];
            foreach($original['credits']as$credit){
                $refund=$finance->currentEntry($credit['entry_id']);
                if($refund['status']==='cancelled'||$refund['reversed_on']!==null||$refund['remaining_cents']>0)throw new DomainException('Zugehörige Gutschrift und noch offene Erstattung zuerst klären. Keine unvollständige Zahlungsforderung erinnern.',409);
                $credits[]=['invoice'=>$credit,'refund'=>$refund];
            }
            $source=$original['source'];$source['current_entry']=$entry;$source['settled_credits']=$credits;$source['reminder_day']=$day;$source['original_payment_account']=$account;$source['original_payment_account_settings']=$accountSettings;
            $result=['invoice'=>$original['invoice'],'entry'=>$entry,'open_cents'=>$entry['remaining_cents'],'source'=>$source,'original_pdf'=>$original['original_pdf'],'snapshot'=>$original['snapshot']];
            if($own)$db->commit();return $result;
        }catch(\Throwable $failure){
            if($own&&$db->inTransaction())$db->rollBack();
            if(($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))||($failure instanceof DomainException&&$failure->getMessage()==='Ein Speichervorgang läuft bereits. Bitte erneut versuchen.'))throw new DomainException('Rechnung, Zahlung oder Gutschrift wird gerade bearbeitet. Erneut prüfen.',503,$failure);
            throw $failure;
        }
    }
}
