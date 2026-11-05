<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;use PDO;
use Kulturhus\Verein\{ReimbursementActionOriginals as Originals,ReimbursementCommands,Permissions};
/** Complete scalar forms anchored in the opened expense, with an explicit review. */
final class ReimbursementForm
{
    public const COMPLETE='reimbursement_complete';
    public static function requested(array $post,array $query):bool
    {
        if(is_string($post['action']??null)&&!str_starts_with($post['action'],'reimbursement-')&&!array_key_exists(self::COMPLETE,$post))return false;
        return array_key_exists('reimbursement_operation',$query)||array_key_exists(self::COMPLETE,$post)||(is_string($post['action']??null)&&str_starts_with($post['action'],'reimbursement-'));
    }
    public static function source(array $query):array
    {
        if(($query['view']??null)!=='finance'||($query['tab']??null)!=='receipts')throw new DomainException('Ursprüngliche Ausgabe und Erstattungsformular vollständig öffnen.',422);
        $entry=Originals::id($query['entry']??null);$key=Originals::key($query['reimbursement_key']??null);$operation=$query['reimbursement_operation']??null;
        if(!is_string($operation)||!in_array($operation,['create','associate','correct','cancel','reopen'],true))throw new DomainException('Ursprüngliche Ausgabe und Erstattungsformular vollständig öffnen.',422);
        return compact('entry','key','operation');
    }
    public static function fields(array $post,array $source):array
    {
        $keys=['action','id','request_key','entry_version'];$keys=array_merge($keys,match($source['operation']){
            'create'=>['reimbursement_contact','invoice_recipient','notes'],
            'associate'=>['claim_id','claim_version','transaction_id','transaction_version','amount'],
            'correct','reopen'=>['claim_id','claim_version','reimbursement_contact','invoice_recipient','notes','reason'],
            'cancel'=>['claim_id','claim_version','reason','reimbursement_mistake'],
        });$out=[];
        if(($post[self::COMPLETE]??null)!=='1')throw new DomainException('Erstattungsformular ist unvollständig. Bekannte ursprüngliche Angaben bleiben erhalten.',422);
        foreach($keys as$key){if(!is_string($post[$key]??null))throw new DomainException('Erstattungsformular ist unvollständig. Bekannte ursprüngliche Angaben bleiben erhalten.',422);$out[$key]=$post[$key];}
        if($out['action']!==Originals::ACTIONS[$source['operation']]||$out['id']!==$source['entry']||$out['request_key']!==$source['key'])throw new DomainException('Erstattungsformular gehört zu einer anderen ursprünglichen Ausgabe.',409);
        foreach(['entry_version','claim_version','transaction_version']as$key)if(isset($out[$key])&&!preg_match('/\A[1-9][0-9]{0,9}\z/D',$out[$key]))throw new DomainException('Ursprüngliche Versionsangaben vollständig prüfen.',422);
        return$out;
    }
    public static function original(array $post):array
    {
        return array_intersect_key($post,array_flip(['action','id','request_key','entry_version','reimbursement_contact','invoice_recipient','notes','claim_id','claim_version','transaction_id','transaction_version','amount','reason','reimbursement_mistake','reimbursement_review_hash','reimbursement_review_signature',self::COMPLETE,'reimbursement_confirmed']));
    }
    private static function signature(string $actor,array $source,array $fields,string $hash,string $csrf):string{return hash_hmac('sha256',Originals::json([$actor,$source,$fields,$hash]),$csrf);}
    public static function handle(PDO $db,string $actor,array $query,array $post,string $csrf):array
    {
        if(!is_string($post['csrf']??null)||!hash_equals($csrf,$post['csrf']))throw new DomainException('Sitzung abgelaufen. Bitte neu anmelden; Ihre Eingaben bleiben erhalten.',403);
        $source=self::source($query);$fields=self::fields($post,$source);try{Permissions::finance($db,$actor,true);}catch(DomainException$failure){throw new DomainException($failure->getMessage(),403,$failure);}
        $service=new ReimbursementCommands($db,$actor);
        $first=Originals::read($db,$actor,$source['entry'],$source['key']);
        if($first){if($first['actor']!==$actor||$first['operation']!==$source['operation']||$first['request']['fields']!==$fields)throw new DomainException('Diese ursprüngliche Kennung wurde mit anderen Angaben gespeichert. Gespeicherten Ausgang lesen.',409);return['saved'=>$first];}
        $refresh=array_key_exists('reimbursement_recheck',$post);
        if($refresh&&($post['reimbursement_recheck']!=='1'||array_key_exists('reimbursement_confirmed',$post)))throw new DomainException('Erstattungsprüfung vollständig bestätigen.',422);
        $review=$service->review($source['entry'],$source['operation'],$fields,$refresh);
        if($refresh){$fields['entry_version']=(string)$review['entry']['version'];if($source['operation']!=='create')$fields['claim_version']=(string)$review['claim']['version'];if($source['operation']==='associate')$fields['transaction_version']=(string)$review['transaction']['version'];}
        $hash=hash('sha256',Originals::json($review));
        if((string)$review['entry']['version']!==$fields['entry_version']||($source['operation']!=='create'&&(string)$review['claim']['version']!==$fields['claim_version'])||($source['operation']==='associate'&&(string)$review['transaction']['version']!==$fields['transaction_version']))throw new DomainException('Ausgabe, Mitglied oder Zahlung inzwischen geändert. Angaben erneut prüfen.',409);
        if(array_key_exists('reimbursement_confirmed',$post)){
            foreach(['reimbursement_review_hash','reimbursement_review_signature','reimbursement_confirmed']as$key)if(!is_string($post[$key]??null))throw new DomainException('Erstattungsprüfung vollständig bestätigen.',422);
            if($post['reimbursement_confirmed']!=='1'||!preg_match('/\A[a-f0-9]{64}\z/D',$post['reimbursement_review_hash'])||!hash_equals(self::signature($actor,$source,$fields,$post['reimbursement_review_hash'],$csrf),$post['reimbursement_review_signature']))throw new DomainException('Geprüfte Erstattungsangaben wurden verändert. Angaben erneut prüfen.',409);
            return['saved'=>$service->execute($source['entry'],$source['operation'],$source['key'],$fields,$post['reimbursement_review_hash'])];
        }
        return['source'=>$source,'fields'=>$fields,'review'=>$review,'hash'=>$hash,'signature'=>self::signature($actor,$source,$fields,$hash,$csrf)];
    }
    public static function search(array $query,string $prefix):array
    {
        $search=$query[$prefix.'_search']??'';$page=$query[$prefix.'_page']??'1';
        if(!is_string($search)||strlen($search)>200||!mb_check_encoding($search,'UTF-8')||!is_string($page)||!preg_match('/\A[1-9][0-9]{0,6}\z/D',$page)||(int)$page>1000000)throw new DomainException('Erstattungssuche und Seitenangabe vollständig prüfen.',422);
        return[$search,(int)$page];
    }
}
