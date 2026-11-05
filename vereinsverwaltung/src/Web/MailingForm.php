<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

use DomainException;
use Kulturhus\Verein\ClubMailings;

/** Exact page and command metadata; rejecting input never supplies a new intention. */
final class MailingForm
{
    public const ACTIONS=['mailing-save','mailing-queue','mailing-cancel','mailing-print'];
    private const QUERY=['view','tab','mailing','new_mailing','edit_mailing','mailing_q','mailing_state','mailing_page','mailing_request'];
    private const POST=['action','mailing_complete','mailing_recheck','mailing','request_key','preview_hash','print_key','subject','body','merge_fields','people','mail_attachments','confirmed'];
    public const STATES=ClubMailings::STATES;

    public static function marked(array $post):bool
    {
        return array_key_exists('mailing',$post)||(is_string($post['action']??null)&&str_starts_with(trim($post['action']),'mailing-'));
    }
    public static function text(mixed $value,int $limit,string $error,bool $multiline=false):string
    {
        if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||strlen($value)>$limit||preg_match($multiline?'/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/':'/[\x00-\x1f\x7f]/',$value))throw new DomainException($error,422);
        return $value;
    }
    public static function key(mixed $value,int $length):string
    {
        if(!is_string($value)||!preg_match('/\A[a-f0-9]{'.$length.'}\z/D',$value))throw new DomainException('Ursprüngliche Sendung und Vorgangskennung prüfen.',422);
        return $value;
    }
    public static function people(mixed $value,int $limit=250):array
    {
        if(!is_array($value)||!array_is_list($value)||count($value)>$limit)throw new DomainException('Ursprüngliche Personen- und Anlagenauswahl prüfen.',422);
        $out=[];
        foreach($value as$id){$id=self::text($id,128,'Ursprüngliche Personen- und Anlagenauswahl prüfen.');if($id===''||in_array($id,$out,true))throw new DomainException('Ursprüngliche Personen- und Anlagenauswahl prüfen.',422);$out[]=$id;}
        return $out;
    }
    public static function query(array $query):array
    {
        if(($query['view']??null)!=='messages'||(array_key_exists('tab',$query)&&$query['tab']!=='')||array_diff(array_keys($query),self::QUERY))throw new DomainException('Sendung im ursprünglich geöffneten Bereich prüfen.',422);
        $id=array_key_exists('mailing',$query)?self::text($query['mailing'],128,'Ursprüngliche Sendung auswählen.'):'';
        if(array_key_exists('mailing',$query)&&$id==='')throw new DomainException('Ursprüngliche Sendung auswählen.',422);
        foreach(['new_mailing','edit_mailing']as$flag)if(array_key_exists($flag,$query)&&$query[$flag]!=='1')throw new DomainException('Ursprüngliche Sendungsansicht prüfen.',422);
        $new=array_key_exists('new_mailing',$query);$edit=array_key_exists('edit_mailing',$query);
        if(($new&&($id!==''||$edit))||($edit&&$id===''))throw new DomainException('Ursprüngliche Sendungsansicht prüfen.',422);
        $q=array_key_exists('mailing_q',$query)?self::text($query['mailing_q'],800,'Ursprüngliche Sendungssuche vollständig prüfen.'):'';
        if(mb_strlen($q)>200)throw new DomainException('Ursprüngliche Sendungssuche vollständig prüfen.',422);
        $state=array_key_exists('mailing_state',$query)?$query['mailing_state']:'';if(!is_string($state)||($state!==''&&!in_array($state,self::STATES,true)))throw new DomainException('Ursprünglichen Sendungsstatus auswählen.',422);
        $page=array_key_exists('mailing_page',$query)?$query['mailing_page']:'1';if(!is_string($page)||!preg_match('/\A[1-9][0-9]{0,6}\z/D',$page)||(int)$page>1000000)throw new DomainException('Ursprüngliche Sendungsseite auswählen.',422);
        $request=array_key_exists('mailing_request',$query)?self::key($query['mailing_request'],32):'';
        if($request!==''&&($id!==''||$new||$edit))throw new DomainException('Ursprüngliche Sendungsansicht prüfen.',422);
        return ['id'=>$id,'new'=>$new,'edit'=>$edit,'query'=>$q,'state'=>$state,'page'=>(int)$page,'request'=>$request];
    }
    public static function metadata(array $query,array $post):array
    {
        $context=self::query($query);
        if(array_diff(array_keys($post),[...self::POST,'csrf'])||!in_array($post['action']??null,self::ACTIONS,true)||($post['mailing_complete']??null)!=='1')throw new DomainException('Sendungsformular nicht vollständig übertragen. Angaben zuerst prüfen.',422);
        $id=self::text($post['mailing']??null,128,'Ursprüngliche Sendung auswählen.');
        if($context['request']!==''||$id!==$context['id'])throw new DomainException('Sendung im ursprünglich geöffneten Bereich prüfen.',422);
        $shared=['action','mailing_complete','mailing_recheck','mailing','csrf'];
        $specific=match($post['action']){'mailing-save'=>['request_key','subject','body','merge_fields','people','mail_attachments'],'mailing-queue'=>['preview_hash','confirmed'],'mailing-cancel'=>['preview_hash'],'mailing-print'=>['preview_hash','print_key']};
        if(array_diff(array_keys($post),[...$shared,...$specific]))throw new DomainException('Sendungsformular nicht vollständig übertragen. Angaben zuerst prüfen.',422);
        $out=['action'=>$post['action'],'id'=>$id,'context'=>$context];
        if(array_key_exists('mailing_recheck',$post)&&$post['mailing_recheck']!=='1')throw new DomainException('Sendungsformular nicht vollständig übertragen. Angaben zuerst prüfen.',422);
        if($post['action']==='mailing-save'){
            $out['key']=self::key($post['request_key']??null,$id===''?32:64);
        }else{
            if($id==='')throw new DomainException('Ursprüngliche Sendung auswählen.',422);
            $out['hash']=self::key($post['preview_hash']??null,64);
            if($post['action']==='mailing-print')$out['key']=self::key($post['print_key']??null,32);
        }
        return $out;
    }
    public static function command(array $query,array $post):array
    {
        $out=self::metadata($query,$post);
        if(array_key_exists('mailing_recheck',$post))throw new DomainException('Die Sendungsprüfung führt keine Änderung oder Zustellung aus.',422);
        if($out['action']==='mailing-save'){
            $out['subject']=self::text($post['subject']??null,200,'Betreff vollständig prüfen.');
            $out['body']=self::text($post['body']??null,10000,'Nachricht vollständig prüfen.',true);
            $out['fields']=self::text($post['merge_fields']??null,15000,'Serienfelder vollständig prüfen.',true);
            $out['people']=self::people(array_key_exists('people',$post)?$post['people']:[]);
            $out['attachments']=self::people(array_key_exists('mail_attachments',$post)?$post['mail_attachments']:[],10);
        }elseif($out['action']==='mailing-queue'&&($post['confirmed']??null)!=='1')throw new DomainException('Versand mit Empfängervorschau bestätigen.',422);
        return $out;
    }
    public static function originals(array $input,bool $query=false):array
    {
        $safe=array_intersect_key($input,array_flip($query?self::QUERY:self::POST));
        return self::encode($safe);
    }
    private static function encode(mixed $value):array
    {
        if(is_string($value))return mb_check_encoding($value,'UTF-8')?['type'=>'text','value'=>$value]:['type'=>'bytes','base64'=>base64_encode($value)];
        if(is_array($value)){
            $entries=[];
            foreach($value as$key=>$item){
                if(is_string($key)&&preg_match('/(?:csrf|password|(?:access|refresh)_token|(?:factor|recovery)_code)\z/i',$key))continue;
                $entries[]=['key'=>self::encode($key),'value'=>self::encode($item)];
            }
            return ['type'=>'array','entries'=>$entries];
        }
        if(is_int($value)||is_float($value)||is_bool($value)||$value===null)return ['type'=>get_debug_type($value),'value'=>$value];
        return ['type'=>get_debug_type($value)];
    }
    public static function url(array $query=[]):string{return'?'.http_build_query(['view'=>'messages']+$query,'','&',PHP_QUERY_RFC3986);}
}
