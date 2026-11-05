<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

/** Admission effects are durable; provisioning and transport occur only after the admission commits. */
final class MembershipWelcome
{
    public const INVOICE_NOTICE='Die Rechnung über deinen jährlichen Mitgliedsbeitrag erhältst du in einer separaten E-Mail.';
    /** Board status view contains no credentials, mail body or unrelated financial documents. */
    public static function status(PDO $db,string $actorId,string $applicationId):?array
    {
        $actor=BoardAccess::require($db,$actorId);
        $row=DocumentCatalog::query($db,'SELECT w.application_id,w.entry_id,w.welcome_status,w.invoice_status,w.welcome_sent_at,w.invoice_sent_at,i.state AS invoice_state,i.missing_fields,e.display_number,e.due_date FROM membership_welcome w LEFT JOIN contribution_invoices i ON i.club_id=w.club_id AND i.entry_id=w.entry_id LEFT JOIN finance_entries e ON e.club_id=w.club_id AND e.id=w.entry_id WHERE w.club_id=? AND w.application_id=?',[$actor['club_id'],$applicationId])->fetch();
        if(!$row)return null;
        $row['missing_fields']=json_decode($row['missing_fields']??'[]',true,32,JSON_THROW_ON_ERROR);
        return $row;
    }
    public static function enqueue(PDO $db,array $actor,string $applicationId,string $membershipId):void
    {
        $club=$actor['club_id'];
        if(DocumentCatalog::query($db,'SELECT 1 FROM membership_welcome WHERE club_id=? AND application_id=?',[$club,$applicationId])->fetchColumn())return;
        $application=DocumentCatalog::query($db,"SELECT * FROM membership_applications WHERE club_id=? AND id=? AND status='accepted' AND reviewed_by=?",[$club,$applicationId,$actor['id']])->fetch();
        if(!$application)throw new DomainException('Bestätigte Vorstandsaufnahme erforderlich.');
        BoardAccess::require($db,$actor['id']);
        $contact=DocumentCatalog::raw($db,$club,'contact',$application['contact_id']);
        $membership=DocumentCatalog::query($db,'SELECT * FROM memberships WHERE club_id=? AND id=? AND contact_id=?',[$club,$membershipId,$application['contact_id']])->fetch();
        if(!$contact||!$membership)throw new DomainException('Aufnahmeprofil fehlt.');
        $entry=(new Finance($db,$actor['id']))->createAdmissionContribution($applicationId,$membershipId);
        if($entry!==null)(new ContributionInvoices($db,$actor['id']))->issueForAdmission($applicationId,$entry);
        $manual=$application['access_mail']!==null;
        if($manual||ProductionRuntime::nativeManager())ManualMemberAccounts::enqueue($db,$actor,$contact['id'],$manual&&(bool)$application['access_mail'],$applicationId);
        $payload=['name'=>$contact['name'],'member_number'=>$membership['member_number'],'starts_on'=>$membership['starts_on'],'invoice_follows'=>$entry!==null,'club_name'=>DocumentCatalog::query($db,'SELECT name FROM clubs WHERE id=?',[$club])->fetchColumn()?:'Mein Verein'];
        DocumentCatalog::query($db,'INSERT INTO membership_welcome(club_id,application_id,contact_id,membership_id,email,entry_id,payload,welcome_status,invoice_status,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$club,$applicationId,$contact['id'],$membershipId,$contact['email'],$entry,json_encode($payload,JSON_THROW_ON_ERROR),$manual?'manual_access':(filter_var($contact['email'],FILTER_VALIDATE_EMAIL)?'pending':'needs_address'),$entry!==null?'waiting':'skipped',gmdate('c')]);
    }
    public static function message(array $payload,array $account,string $publicUrl):array
    {
        $club=$payload['club_name']??'Mein Verein';
        $text='Hallo '.$payload['name'].",\n\nherzlich willkommen bei ".$club."! Der Vorstand hat deinem Mitgliedsantrag zugestimmt. Deine Mitgliedschaft beginnt am ".date('d.m.Y',strtotime($payload['starts_on'])).'. Deine Mitgliedsnummer lautet '.$payload['member_number'].'.';
        if(!empty($payload['invoice_follows']))$text.="\n\n".self::INVOICE_NOTICE;
        $text.="\n\nDein Zugang zum Mitgliederbereich:\n".rtrim($publicUrl,'/')."/login/\nBenutzername: ".$account['username'];
        if(!empty($account['setup_url']))$text.="\nLege über diesen einmaligen Link dein eigenes Passwort fest. Der Link ist zwei Tage gültig:\n".$account['setup_url'];
        elseif(!empty($account['initial_password']))$text.="\nStartpasswort: ".$account['initial_password']."\nBitte ändere dieses Startpasswort bei deiner ersten Anmeldung.";
        else $text.="\nDu kannst dein vorhandenes Passwort weiterverwenden.";
        $text.="\n\nWir freuen uns auf dich!\nDein ".$club;
        return ['subject'=>'Willkommen bei '.$club,'text'=>$text];
    }
    public static function dispatch(PDO $db,string $club,string $publicUrl,callable $send,callable $provision,callable $acknowledge,?callable $invoiceSend=null):int
    {
        if(!MailPolicy::allowed($publicUrl))return 0;
        $clubName=DocumentCatalog::query($db,'SELECT name FROM clubs WHERE id=?',[$club])->fetchColumn()?:'Mein Verein';
        $sent=0;
        foreach(DocumentCatalog::query($db,"SELECT w.* FROM membership_welcome w JOIN membership_applications a ON a.club_id=w.club_id AND a.id=w.application_id WHERE w.club_id=? AND w.welcome_status='pending' AND a.status='accepted' ORDER BY w.created_at,w.application_id LIMIT 20",[$club])->fetchAll() as $row){
            if(DocumentCatalog::query($db,"UPDATE membership_welcome SET welcome_status='preparing' WHERE club_id=? AND application_id=? AND welcome_status='pending'",[$club,$row['application_id']])->rowCount()!==1)continue;
            try{
                $account=$provision($row['application_id'],$row['contact_id']);
                if(!is_array($account)||!is_string($account['username']??null)||$account['username']==='')throw new DomainException('Mitgliedszugang muss zugeordnet werden.');
                $mail=self::message(json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR),$account,$publicUrl);
            }catch(\Throwable){DocumentCatalog::query($db,"UPDATE membership_welcome SET welcome_status='needs_account' WHERE club_id=? AND application_id=?",[$club,$row['application_id']]);continue;}
            DocumentCatalog::query($db,"UPDATE membership_welcome SET welcome_status='sending' WHERE club_id=? AND application_id=?",[$club,$row['application_id']]);
            try{$send($row['email'],$mail['subject'],$mail['text'],ApplicationMail::html($mail['text'],$publicUrl,$mail['subject'],rtrim($publicUrl,'/').'/login/','Zum Mitgliederbereich','',$clubName),[]);}
            catch(\Kulturhus\Native\Delivery\DeliveryNotStarted $e){DocumentCatalog::query($db,"UPDATE membership_welcome SET welcome_status=? WHERE club_id=? AND application_id=? AND welcome_status='sending'",[$e->retryable?'pending':'blocked',$club,$row['application_id']]);continue;}
            catch(\Throwable){DocumentCatalog::query($db,"UPDATE membership_welcome SET welcome_status='uncertain' WHERE club_id=? AND application_id=?",[$club,$row['application_id']]);continue;}
            DocumentCatalog::query($db,"UPDATE membership_welcome SET welcome_status='sent',welcome_sent_at=? WHERE club_id=? AND application_id=?",[gmdate('c'),$club,$row['application_id']]);$sent++;
            unset($account,$mail);
        }
        // A failed escrow cleanup must never turn a successful mail into another send attempt.
        foreach(DocumentCatalog::query($db,"SELECT application_id FROM membership_welcome WHERE club_id=? AND welcome_status='sent' AND account_ack_at IS NULL ORDER BY created_at LIMIT 20",[$club])->fetchAll() as $row){
            try{$acknowledge($row['application_id']);DocumentCatalog::query($db,'UPDATE membership_welcome SET account_ack_at=? WHERE club_id=? AND application_id=?',[gmdate('c'),$club,$row['application_id']]);}catch(\Throwable){}
        }
        foreach(DocumentCatalog::query($db,"SELECT w.application_id,w.entry_id,i.document_id,i.recipient_email,e.display_number,e.due_date,e.amount_cents,e.status,".PaymentFunding::paidSql($db,'e')." allocated, EXISTS(SELECT 1 FROM finance_reversals r WHERE r.club_id=e.club_id AND r.entry_id=e.id) reversed FROM membership_welcome w JOIN contribution_invoices i ON i.club_id=w.club_id AND i.entry_id=w.entry_id JOIN finance_entries e ON e.club_id=w.club_id AND e.id=w.entry_id WHERE w.club_id=? AND w.welcome_status='sent' AND w.invoice_status='waiting' AND i.state='issued' ORDER BY w.created_at LIMIT 20",[$club])->fetchAll() as $row){
            if($row['status']==='cancelled'||$row['reversed']||(int)$row['allocated']>=(int)$row['amount_cents']){DocumentCatalog::query($db,"UPDATE membership_welcome SET invoice_status='skipped' WHERE club_id=? AND application_id=?",[$club,$row['application_id']]);continue;}
            if(!filter_var($row['recipient_email'],FILTER_VALIDATE_EMAIL))continue;
            $file=DocumentCatalog::query($db,'SELECT name,mime,bytes FROM document_files WHERE club_id=? AND document_id=? ORDER BY revision DESC LIMIT 1',[$club,$row['document_id']])->fetch();
            if(!$file)continue;
            if(DocumentCatalog::query($db,"UPDATE membership_welcome SET invoice_status='sending' WHERE club_id=? AND application_id=? AND invoice_status='waiting'",[$club,$row['application_id']])->rowCount()!==1)continue;
            $mail=ContributionMail::build($db,$club,$row['entry_id'],'invoice');$text=$mail['text'];$subject=$mail['subject'];
            try{($invoiceSend??$send)($row['recipient_email'],$subject,$text,ApplicationMail::html($text,$publicUrl,$subject,'','Mitgliedsantrag bestätigen','',$clubName),[$file]);DocumentCatalog::query($db,"UPDATE membership_welcome SET invoice_status='sent',invoice_sent_at=? WHERE club_id=? AND application_id=?",[gmdate('c'),$club,$row['application_id']]);$sent++;}
            catch(\Kulturhus\Native\Delivery\DeliveryNotStarted $e){DocumentCatalog::query($db,"UPDATE membership_welcome SET invoice_status=? WHERE club_id=? AND application_id=? AND invoice_status='sending'",[$e->retryable?'waiting':'blocked',$club,$row['application_id']]);}
            catch(\Throwable){if(DocumentCatalog::query($db,"SELECT 1 FROM membership_welcome WHERE club_id=? AND application_id=? AND invoice_status='sent' AND invoice_sent_at IS NOT NULL",[$club,$row['application_id']])->fetchColumn()){$sent++;continue;}DocumentCatalog::query($db,"UPDATE membership_welcome SET invoice_status='uncertain' WHERE club_id=? AND application_id=? AND invoice_status='sending'",[$club,$row['application_id']]);}
        }
        return $sent;
    }
}
