<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

use DomainException;
use PDO;
use Kulturhus\Verein\{BoardAccess,ClubMailings};

/** One state for GET, rejected commands and explicit read-only outcome checks. */
final class MailingPage
{
    public static function applies(array $query):bool{return($query['view']??null)==='messages'&&(!array_key_exists('tab',$query)||$query['tab']==='');}
    public static function prepare(PDO $db,string $actor,array $query,array $post=[],string $error='',int $status=200):array
    {
        $state=['status'=>$status,'error'=>$error,'notice'=>'','mode'=>'error','mailing'=>null,'preview'=>null,'deliveries'=>[],'contacts'=>[],'attachments'=>[],'form'=>null,'catalogue'=>null,'context'=>null,'metadata'=>null,'originals'=>null,'retry'=>false,'printed'=>null];
        if($post!==[])$state['originals']=['query'=>MailingForm::originals($query,true),'form'=>MailingForm::originals($post)];
        try{
            try{BoardAccess::require($db,$actor);}catch(DomainException $failure){throw new DomainException($failure->getMessage(),403,$failure);}
            $context=MailingForm::query($query);$state['context']=$context;$service=new ClubMailings($db,$actor);
            if($post!==[]){
                $metadata=MailingForm::metadata($query,$post);$state['metadata']=$metadata;
                if($metadata['action']==='mailing-save'){
                    // Longer valid text remains editable; byte/shape limits are checked only on submission.
                    $form=['id'=>$metadata['id'],'key'=>$metadata['key']];
                    foreach(['subject','body','merge_fields']as$field)$form[$field]=MailingForm::text($post[$field]??null,PHP_INT_MAX,'Diese Eingabeform kann nicht übernommen werden. Die übermittelten Werte sind unten unverändert dokumentiert.',$field!=='subject');
                    $form['people']=MailingForm::people(array_key_exists('people',$post)?$post['people']:[]);
                    $form['attachments']=MailingForm::people(array_key_exists('mail_attachments',$post)?$post['mail_attachments']:[],10);
                    $state['form']=$form;
                }
                $state['mode']='retained';
                if($state['form']!==null)self::choices($state,$service);
                if(($post['mailing_recheck']??null)==='1'){
                    $current=$metadata['id']===''?$service->byRequest($metadata['key']):$service->get($metadata['id']);
                    $state['mailing']=$current;
                    if($metadata['action']==='mailing-print')$state['printed']=$service->printed($metadata['id'],$metadata['hash'],$metadata['key']);
                    if($current===null){$state['notice']='Unter dieser Vorgangskennung ist noch keine Sendung bestätigt.';$state['retry']=$metadata['action']==='mailing-save';}
                    else{
                        $state['notice']='Gespeicherten Stand geöffnet. Vor weiteren Änderungen die aktuellen Angaben prüfen.';
                        $original=$metadata['action']==='mailing-save'?$metadata['key']:$metadata['hash'];
                        $state['retry']=$metadata['id']!==''&&$current['state']==='draft'&&hash_equals($current['preview_hash'],$original)&&$metadata['action']==='mailing-save';
                    }
                }elseif($status===422&&$state['form']!==null){$state['retry']=true;}
                if($state['retry']&&$state['form']!==null){$state['mode']='edit';}
                return$state;
            }
            if($context['request']!==''){
                $mailing=$service->byRequest($context['request']);
                if($mailing===null){$state['notice']='Unter dieser Vorgangskennung ist noch keine Sendung bestätigt.';return$state;}
                $state['notice']='Gespeicherten Stand geöffnet. Vor weiteren Änderungen die aktuellen Angaben prüfen.';
            }else{$mailing=$context['id']!==''?$service->get($context['id']):null;}
            $state['mailing']=$mailing;
            if($context['new']||$context['edit']){
                if($mailing!==null&&$mailing['state']!=='draft')throw new DomainException('Sendung geändert oder bereits eingeplant. Bitte neu laden.',409);
                $state['form']=['id'=>$mailing['id']??'','key'=>$mailing['preview_hash']??bin2hex(random_bytes(16)),'subject'=>$mailing['subject']??'','body'=>$mailing['body']??'','merge_fields'=>$mailing===null?'':implode("\n",array_map(static fn($key,$value)=>$key.'='.$value,array_keys($mailing['merge_fields']),array_values($mailing['merge_fields']))),'people'=>array_column($mailing['people']??[],'id'),'attachments'=>array_column($mailing['attachment_refs']??[],'id')];
                $state['mode']='edit';$state['retry']=true;self::choices($state,$service);
            }elseif($mailing!==null){
                $state['mode']='detail';$state['deliveries']=$service->deliveries($mailing['id']);
                // Keep the saved original and editing link visible when current source checks fail.
                if($mailing['state']==='draft')$state['preview']=$service->preview($mailing['id']);
            }else{$state['catalogue']=$service->catalogue($context['query'],$context['state'],$context['page']);$state['mode']='list';}
        }catch(DomainException $failure){
            if($state['error']===''){$state['error']=$failure->getMessage();$state['status']=in_array($failure->getCode(),[403,404,409,422],true)?$failure->getCode():422;}
            $state['retry']=false;if($post!==[]&&$state['form']!==null)$state['mode']='retained';
        }catch(\Throwable){$state['status']=503;$state['error']=$state['error']?:'Sendungsdaten konnten nicht geladen werden. Gespeicherten Stand prüfen.';$state['retry']=false;}
        return$state;
    }
    private static function choices(array &$state,ClubMailings $service):void
    {
        $state['contacts']=$service->contacts();$state['attachments']=$service->attachmentChoices();
        foreach(array_diff($state['form']['people'],array_column($state['contacts'],'id'))as$id)$state['contacts'][]=['id'=>$id,'name'=>null,'email'=>'','recipient_group'=>'unavailable'];
        foreach(array_diff($state['form']['attachments'],array_column($state['attachments'],'id'))as$id)$state['attachments'][]=['id'=>$id,'title'=>null];
    }
}
