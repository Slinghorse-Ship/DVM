<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

use DomainException;
use PDO;
use Kulturhus\Verein\{Finance,Permissions};

/** Private, bounded unsaved selections. Only an opaque nonce enters navigation URLs. */
final class FinanceMatchDraft
{
    private const FIELDS=[
        'allocate'=>['action','transaction_id','transaction_version','entry_id','entry_version','amount','request_key','return_to'],
        'internal-link'=>['action','transaction_id','transaction_version','other_id','other_version','amount','reason','request_key'],
        'internal-cash'=>['action','transaction_id','transaction_version','cash_account','cash_date','amount','reason','cash_confirm','request_key'],
    ];
    public static function matchesRoute(array $body,array $route):bool
    {
        if(!is_string($body['action']??null)||!isset(self::FIELDS[$body['action']])||!in_array($route['kind']??null,['transaction','entry'],true)||!is_string($route['id']??null))return false;
        return $route['kind']==='transaction'?($body['transaction_id']??null)===$route['id']:$body['action']==='allocate'&&($body['entry_id']??null)===$route['id'];
    }
    public static function route(array $query):array
    {
        foreach(['transaction','entry'] as $kind)if(is_string($query[$kind]??null)&&$query[$kind]!=='')return ['kind'=>$kind,'id'=>$query[$kind]];
        throw new DomainException('Ursprüngliche Zuordnung in ihrem eigenen Vorgang öffnen.',409);
    }
    public static function read(array $session,PDO $db,string $actor,array $route,mixed $nonce):array
    {
        $identity=self::identity($db,$actor);self::nonce($nonce);$draft=$session['finance_match_drafts'][$nonce]??null;
        if(!is_array($draft)||$draft['actor']!==$identity['id']||$draft['club']!==$identity['club_id']||$draft['route']!==$route)throw new DomainException('Diese ursprüngliche Auswahl ist für diesen Zugang und Vorgang nicht verfügbar.',404);
        self::source($db,$actor,$route);self::stable($db,$actor,$identity);
        return $draft;
    }
    public static function capture(array &$session,PDO $db,string $actor,array $route,array $post,?array $review=null):array
    {
        $identity=self::identity($db,$actor);if($identity['finance_access']!=='write')throw new DomainException('Aktuelle Finanzschreibberechtigung erforderlich.',403);
        if(!self::matchesRoute($post,$route))throw new DomainException('Ursprüngliche Zuordnung in ihrem eigenen Vorgang öffnen.',409);
        self::source($db,$actor,$route);$body=[];
        foreach(self::FIELDS[$post['action']] as $field){$value=$post[$field]??'';if(!is_string($value)||!mb_check_encoding($value,'UTF-8'))throw new DomainException('Ganze gültige Zuordnungsangaben prüfen.',422);$body[$field]=$value;}
        $reviewed=false;
        if($review!==null){
            $targetField=match($body['action']){'allocate'=>'entry_id','internal-link'=>'other_id','internal-cash'=>'cash_account'};
            if($review['action']!==$body['action']||$review['route']!==$route||$review['source']['id']!==$body['transaction_id']||$review['target']['id']!==$body[$targetField])throw new DomainException('Die aktuelle Prüfung gehört zu einer anderen ursprünglichen Auswahl.',409);
            $body['transaction_version']=(string)$review['source']['version'];
            if($body['action']==='allocate')$body['entry_version']=(string)$review['target']['version'];
            if($body['action']==='internal-link')$body['other_version']=(string)$review['target']['version'];
            if($body['action']==='internal-cash')$body['cash_confirm']='';
            $reviewed=true;
        }
        if(strlen(json_encode($body,JSON_THROW_ON_ERROR))>65536)throw new DomainException('Die ganze Auswahl ist zu groß für die Wiederaufnahme. Angaben bewusst prüfen; es wurde nichts gespeichert.',422);
        $nonce=$post['match_draft']??null;
        if($nonce!==null&&$nonce!=='')self::read($session,$db,$actor,$route,$nonce);
        else{
            $nonce=null;
            foreach($session['finance_match_drafts']??[] as $id=>$old)if($old['actor']===$identity['id']&&$old['club']===$identity['club_id']&&$old['route']===$route&&$old['body']['action']===$body['action']&&$old['body']['request_key']===$body['request_key']){$nonce=$id;break;}
            if($nonce===null){if(count($session['finance_match_drafts']??[])>=20)throw new DomainException('Zuerst eine eigene offene Zuordnungsauswahl bewusst schließen.',422);$nonce=bin2hex(random_bytes(16));}
        }
        $draft=['id'=>$nonce,'actor'=>$identity['id'],'club'=>$identity['club_id'],'route'=>$route,'body'=>$body,'reviewed'=>$reviewed];
        self::stable($db,$actor,$identity,true);$session['finance_match_drafts'][$nonce]=$draft;return $draft;
    }
    /** Browse using the complete current form, including browsers without scripts. */
    public static function navigate(array &$session,PDO $db,string $actor,array $route,array $post,array $query):array
    {
        $move=$post['match_move']??null;
        if(!is_string($move)||!preg_match('/\A(receipt|own|all):(search|jump|page:([1-9][0-9]{0,6}))\z/D',$move,$parts)||($parts[1]==='own'&&$route['kind']!=='transaction')||($parts[1]==='all'&&$parts[2]!=='search'))throw new DomainException('Gültige Zuordnungssuche oder Seite ausdrücklich wählen.',422);
        $draft=self::capture($session,$db,$actor,$route,$post);
        $next=$route['kind']==='transaction'?\bankTransactionContext($route['id'],$query):\receiptPaymentContext($route['id'],$query);
        $kinds=$parts[1]==='all'?($route['kind']==='transaction'?['receipt','own']:['receipt']):[$parts[1]];
        foreach($kinds as $kind){
            $prefix=$kind==='own'?'own':'match';
            foreach([$prefix.'_q',$kind==='own'?'own_account':($route['kind']==='entry'?'match_account':null)] as $field){
                if($field===null)continue;$value=$post[$field]??($query[$field]??'');if(!is_string($value))throw new DomainException('Ganze gültige Zuordnungssuche prüfen.',422);$next[$field]=$value;
            }
            $page=$parts[2]==='search'?'1':($parts[2]==='jump'?($post[$prefix.'_page']??''):($parts[3]??''));
            if(!is_string($page))throw new DomainException('Ursprüngliche Zuordnungsseite vollständig prüfen.',422);
            $next[$prefix.'_page']=$page;
        }
        $next['match_draft']=$draft['id'];return $next;
    }

    public static function discard(array &$session,PDO $db,string $actor,array $route,mixed $nonce):void
    {
        self::read($session,$db,$actor,$route,$nonce);unset($session['finance_match_drafts'][$nonce]);
    }
    public static function index(array $session,PDO $db,string $actor,array $route):array
    {
        $identity=self::identity($db,$actor);$out=[];
        foreach($session['finance_match_drafts']??[] as $draft)if($draft['actor']===$identity['id']&&$draft['club']===$identity['club_id']&&$draft['route']===$route)$out[]=['id'=>$draft['id'],'action'=>$draft['body']['action']];
        self::stable($db,$actor,$identity);return $out;
    }
    public static function view(array &$session,PDO $db,string $actor,array $route,array $post,array $query,?array $review=null):array
    {
        $state=['draft'=>null,'error'=>'','originals'=>[],'restored'=>false];
        try{
            if(self::matchesRoute($post,$route))$state['draft']=self::capture($session,$db,$actor,$route,$post,$review);
            elseif(array_key_exists('match_draft',$query)){$state['draft']=self::read($session,$db,$actor,$route,$query['match_draft']);$state['restored']=true;}
            $state['originals']=self::index($session,$db,$actor,$route);
        }catch(DomainException $failure){$state['error']=$failure->getMessage();}
        return $state;
    }
    /** A confirmed existing writer closes only its own exactly bound UI draft. */
    public static function finished(array &$session,PDO $db,string $actor,array $post):void
    {
        $nonce=$post['match_draft']??null;if(!is_string($nonce)||!isset($session['finance_match_drafts'][$nonce]))return;
        try{$identity=self::identity($db,$actor);}catch(DomainException){return;}
        $draft=$session['finance_match_drafts'][$nonce];
        if($draft['actor']!==$identity['id']||$draft['club']!==$identity['club_id']||!self::matchesRoute($post,$draft['route'])||($post['action']??null)!==$draft['body']['action'])return;
        foreach(['transaction_id','entry_id','other_id','cash_account','request_key'] as $field)if(isset($draft['body'][$field])&&($post[$field]??null)!==$draft['body'][$field])return;
        unset($session['finance_match_drafts'][$nonce]);
    }
    private static function stable(PDO $db,string $actor,array $expected,bool $write=false):void
    {
        $now=self::identity($db,$actor);if($now['id']!==$expected['id']||$now['club_id']!==$expected['club_id']||($write&&$now['finance_access']!=='write'))throw new DomainException('Vereinszugang oder Finanzberechtigung geändert.',409);
    }
    private static function identity(PDO $db,string $actor):array
    {
        $identity=Permissions::actor($db,$actor);if(!in_array($identity['finance_access'],['read','write'],true))throw new DomainException('Keine ausreichende Finanzberechtigung.',403);return $identity;
    }
    private static function source(PDO $db,string $actor,array $route):void
    {
        $finance=new Finance($db,$actor);
        if(($route['kind']??null)==='transaction')$finance->transactionDetail($route['id']);
        elseif(($route['kind']??null)==='entry')$finance->entry($route['id']);
        else throw new DomainException('Ursprüngliche Zuordnung in ihrem eigenen Vorgang öffnen.',409);
    }
    private static function nonce(mixed $nonce):void
    {
        if(!is_string($nonce)||!preg_match('/\A[a-f0-9]{32}\z/D',$nonce))throw new DomainException('Ursprüngliche Auswahlkennung vollständig prüfen.',422);
    }
}
