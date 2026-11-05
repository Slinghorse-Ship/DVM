<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

use DomainException;
use PDO;
use Kulturhus\Verein\{Finance,ExpenseReimbursements,ReimbursementActionOriginals as Originals,Permissions};
use Kulturhus\Verein\Infrastructure\Database\Transactions;

/** One actual return and one original outgoing allocation, anchored to their opened expense. */
final class ReimbursementReturnForm
{
    public const COMPLETE='reimbursement_return_complete';
    public const ACTIONS=['return'=>'reimbursement-return','void'=>'reimbursement-return-void'];
    public static function requested(array $post,array $query):bool
    {
        return array_key_exists('reimbursement_return_operation',$query)||array_key_exists(self::COMPLETE,$post)||(is_string($post['action']??null)&&in_array($post['action'],self::ACTIONS,true));
    }
    public static function source(array $query):array
    {
        if(($query['view']??null)!=='finance'||($query['tab']??null)!=='receipts'||!is_string($query['reimbursement_return_operation']??null)||!isset(self::ACTIONS[$query['reimbursement_return_operation']]))throw new DomainException('Den Rückzahlungsschritt an der ursprünglichen privaten Ausgabe öffnen.',422);
        $out=['entry'=>Originals::id($query['entry']??null),'operation'=>$query['reimbursement_return_operation'],'key'=>Originals::key($query['reimbursement_return_key']??null),'counter'=>Originals::id($query['reimbursement_return_counter']??null)];
        $out[$out['operation']==='return'?'allocation':'return_id']=Originals::id($query[$out['operation']==='return'?'reimbursement_return_allocation':'reimbursement_return_id']??null);return$out;
    }
    public static function fields(array $post,array $source):array
    {
        if(($post[self::COMPLETE]??null)!=='1')throw new DomainException('Rückzahlungsformular ist unvollständig. Bekannte ursprüngliche Angaben bleiben erhalten.',422);
        $keys=['action','id','request_key','entry_version','claim_id','claim_version','counter_id','counter_version','reason'];$keys=array_merge($keys,$source['operation']==='return'?['allocation_id','amount']:['return_id']);$out=[];
        foreach($keys as$key){if(!is_string($post[$key]??null))throw new DomainException('Rückzahlungsformular ist unvollständig. Bekannte ursprüngliche Angaben bleiben erhalten.',422);$out[$key]=$post[$key];}
        if($out['action']!==self::ACTIONS[$source['operation']]||$out['id']!==$source['entry']||$out['request_key']!==$source['key']||$out['counter_id']!==$source['counter']||($source['operation']==='return'?$out['allocation_id']!==$source['allocation']:$out['return_id']!==$source['return_id']))throw new DomainException('Rückzahlungsformular gehört zu einer anderen ursprünglichen Erstattung oder Geldbewegung.',409);
        foreach(['entry_version','claim_version','counter_version']as$key)if(!preg_match('/\A[1-9][0-9]{0,9}\z/D',$out[$key]))throw new DomainException('Ursprüngliche Versionsangaben vollständig prüfen.',422);
        Originals::id($out['claim_id']);return$out;
    }
    public static function original(array $post):array
    {
        return array_intersect_key($post,array_flip(['action','id','request_key','entry_version','claim_id','claim_version','counter_id','counter_version','allocation_id','amount','return_id','reason',self::COMPLETE,'reimbursement_return_confirmed']));
    }
    public static function modelSource(array $fields):array
    {
        return['entry'=>$fields['id'],'claim_id'=>$fields['claim_id'],'entry_version'=>(int)$fields['entry_version'],'claim_version'=>(int)$fields['claim_version']];
    }
    private static function signature(string $actor,array $source,array $fields,string $hash,string $csrf):string{return hash_hmac('sha256',Originals::json([$actor,$source,$fields,$hash]),$csrf);}
    private static function sameFirst(array $first,array $source,array $fields,string $actor):bool
    {
        $request=$source['operation']==='return'?$first['request']:($first['void']['request']??[]);
        if(($request['actor']??null)!==$actor||($request['reimbursement']??null)!==self::modelSource($fields)||($request['reason']??null)!==$fields['reason'])return false;
        if($source['operation']==='return')return($request['counter']??null)===$source['counter']&&($request['counter_version']??null)===(int)$fields['counter_version']&&($request['amounts']??null)===[['allocation_id'=>$fields['allocation_id'],'amount'=>$fields['amount']]];
        return($request['transaction']??null)===$source['counter']&&($request['id']??null)===$source['return_id']&&($first['void']['review']['counter']['version']??null)===(int)$fields['counter_version'];
    }
    public static function handle(PDO $db,string $actor,array $query,array $post,string $csrf):array
    {
        if(!is_string($post['csrf']??null)||!hash_equals($csrf,$post['csrf']))throw new DomainException('Sitzung abgelaufen. Bitte neu anmelden; Ihre Eingaben bleiben erhalten.',403);
        $source=self::source($query);$fields=self::fields($post,$source);try{$who=Permissions::finance($db,$actor,true);}catch(DomainException$failure){throw new DomainException($failure->getMessage(),403,$failure);}$finance=new Finance($db,$actor);
        $first=$finance->reimbursementReturnSaved($source['entry'],$source['key'],$source['operation']==='void');if($first){if(!self::sameFirst($first,$source,$fields,$who['id']))throw new DomainException('Diese Rückzahlungskennung gehört zu anderen ursprünglichen Angaben. Gespeicherten Ausgang lesen.',409);return['saved'=>$first,'operation'=>$source['operation']];}
        $refresh=array_key_exists('reimbursement_return_recheck',$post);
        if($refresh){if($post['reimbursement_return_recheck']!=='1'||array_key_exists('reimbursement_return_confirmed',$post))throw new DomainException('Die Rückzahlungsprüfung vollständig und gesondert bestätigen.',422);
            $current=Transactions::run($db,static function()use($db,$actor,$source):array{$f=new Finance($db,$actor);return['entry'=>$f->currentEntry($source['entry']),'claim'=>(new ExpenseReimbursements($db,$actor))->forEntry($source['entry']),'counter'=>$f->transactionDetail($source['counter'],true)];});if(!$current['claim']||$current['claim']['id']!==$fields['claim_id'])throw new DomainException('Ursprünglichen privaten Erstattungsfall auswählen.',409);
            $fields['entry_version']=(string)$current['entry']['version'];$fields['claim_version']=(string)$current['claim']['version'];$fields['counter_version']=(string)$current['counter']['version'];
        }
        $model=self::modelSource($fields);$review=$source['operation']==='return'?$finance->reimbursementReturnReview($model,$source['counter'],[['allocation_id'=>$fields['allocation_id'],'amount'=>$fields['amount']]],$fields['reason']):$finance->reimbursementReturnVoidReview($model,$source['counter'],$source['return_id'],$fields['reason']);
        if((string)$review['review']['counter']['version']!==$fields['counter_version'])throw new DomainException('Erstattung, Zahlung oder ursprünglicher Nachweis inzwischen geändert. Angaben erneut prüfen.',409);$hash=$review['hash'];
        if(array_key_exists('reimbursement_return_confirmed',$post)){
            foreach(['reimbursement_return_review_hash','reimbursement_return_review_signature','reimbursement_return_confirmed']as$key)if(!is_string($post[$key]??null))throw new DomainException('Die Rückzahlungsprüfung vollständig und gesondert bestätigen.',422);
            if($post['reimbursement_return_confirmed']!=='1'||!preg_match('/\A[a-f0-9]{64}\z/D',$post['reimbursement_return_review_hash'])||!hash_equals(self::signature($actor,$source,$fields,$post['reimbursement_return_review_hash'],$csrf),$post['reimbursement_return_review_signature']))throw new DomainException('Geprüfte Rückzahlungsangaben wurden verändert. Angaben erneut prüfen.',409);
            if(!hash_equals($hash,$post['reimbursement_return_review_hash']))throw new DomainException('Erstattung, Zahlung oder ursprünglicher Nachweis inzwischen geändert. Angaben erneut prüfen.',409);
            $saved=$source['operation']==='return'?$finance->returnReimbursement($model,$source['counter'],(int)$fields['counter_version'],[['allocation_id'=>$fields['allocation_id'],'amount'=>$fields['amount']]],$fields['reason'],$source['key'],$hash,true):$finance->revokeReimbursementReturn($model,$source['counter'],$source['return_id'],$fields['reason'],$source['key'],$hash,true);
            return['saved'=>$saved,'operation'=>$source['operation']];
        }
        return['source'=>$source,'fields'=>$fields,'review'=>$review['review'],'hash'=>$hash,'signature'=>self::signature($actor,$source,$fields,$hash,$csrf)];
    }
}
