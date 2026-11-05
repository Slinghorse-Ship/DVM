<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;
use Kulturhus\Native\Auth\{AccountCatalogue,Service};
use Kulturhus\Native\Shared\Access;

/** Personal account invitations/status; original identity and role services remain authoritative. */
final class Accounts
{
    public function __construct(private \PDO $db,private Service $auth,private Access $access,private string $csrf){}
    private static function text(array $data,string $key,int $max=254):string{$value=$data[$key]??'';if(!is_string($value)||strlen($value)>$max||!mb_check_encoding($value,'UTF-8')||preg_match('/[\x00-\x1f\x7f]/',$value))throw new \DomainException('Kontoeingaben prüfen.');return $value;}
    private static function version(array $data):int{$version=self::text($data,'version',10);if(!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$version)||($value=filter_var($version,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>4294967293]]))===false)throw new \DomainException('Gespeicherten Kontostand prüfen.');return $value;}
    private static function url(array $query=[],string $anchor=''):string{return '/accounts'.($query?'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986):'').($anchor!==''?'#'.$anchor:'');}
    private function form(string $operation,array $query=[],string $anchor='account-result'):string{return '<form method="post" action="'.Html::e(self::url($query,$anchor)).'" class="account-form">'.Html::hidden('csrf',$this->csrf).Html::hidden('operation',$operation);}
    private static function state(string $state):string{return match($state){'active'=>'Aktiv','pending'=>'Einladung offen',default=>'Gesperrt'};}
    private static function pager(array $listing,string $prefix,array $query,string $anchor):string
    {
        if($listing['pages']<=1)return '';$h='<nav class="accounts-pages" aria-label="'.Html::e($prefix==='accounts'?'Konten blättern':'Personen blättern').'">';foreach([-1=>'Zurück',1=>'Weitere'] as $delta=>$label){$page=$listing['page']+$delta;if($page>=1&&$page<=$listing['pages'])$h.='<a class="btn btn-outline theme-secondary" href="'.Html::e(self::url(array_replace($query,[$prefix.'_page'=>$page]),$anchor)).'">'.$label.'</a>';}return $h.'<span>Seite '.$listing['page'].' von '.$listing['pages'].'</span></nav>';
    }
    public function action(array $post):array
    {
        try{return $this->perform($post);}catch(\DomainException $failure){throw $failure;}catch(\Throwable $failure){throw new \Kulturhus\Native\Auth\AccountOperationUnconfirmed($failure);}
    }
    private function perform(array $post):array
    {
        $this->access->require('accounts','manage');
        if(!is_string($post['csrf']??null)||!hash_equals($this->csrf,$post['csrf']))throw new \DomainException('Formular abgelaufen. Bitte neu laden.');
        $operation=self::text($post,'operation',30);$allowed=match($operation){'invite'=>['csrf','operation','actor_id','email','version'],'set-active'=>['csrf','operation','id','version','active'],default=>throw new \DomainException('Unbekannter Kontovorgang.')};if(array_diff(array_keys($post),$allowed))throw new \DomainException('Kontoeingaben prüfen.');
        if($operation==='invite'){
            $actor=self::text($post,'actor_id',128);$invitation=$this->auth->createInvitation($this->access,$actor,self::text($post,'email'),86400,null,self::version($post));
            return ['notice'=>'Einladung erstellt.','invitation'=>$invitation];
        }
        $active=self::text($post,'active',1);if(!in_array($active,['0','1'],true))throw new \DomainException('Gewünschten Kontostatus ausdrücklich auswählen.');$this->auth->setActive($this->access,self::text($post,'id',32),$active==='1',self::version($post));return ['notice'=>'Kontostatus gespeichert.'];
    }
    public function render(array $state=[],string $error='',array $query=[]):string
    {
        $owner=$this->access->require('accounts','manage');$catalogue=new AccountCatalogue($this->db,$this->access);$e=Html::e(...);$listing=$catalogue->listing($query);$post=($_SERVER['REQUEST_METHOD']??'GET')==='POST'?$_POST:[];
        $route=['accounts_q'=>$listing['q'],'accounts_state'=>$listing['state'],'accounts_page'=>$listing['page']];$accountId=self::text($query,'account',32);if(($post['operation']??null)==='set-active'&&is_string($post['id']??null))$accountId=$post['id'];$inviteActor=self::text($query,'invite_actor',128);if(($post['operation']??null)==='invite'&&is_string($post['actor_id']??null))$inviteActor=$post['actor_id'];
        $selected=null;if($accountId!=='')try{$selected=$catalogue->account($accountId);$route['account']=$accountId;}catch(\DomainException $failure){$error=$error?:$failure->getMessage();}
        $people=null;try{$people=$catalogue->people($query,$inviteActor!==''?$inviteActor:null);}catch(\DomainException $failure){$error=$error?:$failure->getMessage();$people=$catalogue->people($query);}
        $route+=['people_q'=>$people['q'],'people_page'=>$people['page']];if($people['selected'])$route['invite_actor']=$people['selected']['id'];
        $h='<header class="accounts-heading"><h1>Benutzerkonten</h1><a class="btn btn-outline theme-secondary" href="/account">Mein Konto</a></header>';
        if($error!=='')$h.='<p id="account-result" role="alert" tabindex="-1">'.$e($error).'</p>';elseif(isset($state['notice']))$h.='<p id="account-result" role="status" tabindex="-1">'.$e($state['notice']).'</p>';
        if(isset($state['invitation']))$h.='<section class="account-codes" aria-labelledby="invitation-title"><h2 id="invitation-title">Einladung</h2><p><a href="/invite?token='.$e($state['invitation']['token']).'">Persönliches Konto einrichten</a></p><p>Gültig bis '.$e(date('d.m.Y H:i',$state['invitation']['expires_at'])).'.</p><p>Link jetzt weitergeben. Es wurde keine E-Mail versendet.</p><a class="btn btn-outline theme-secondary" href="/accounts">Fertig</a></section>';
        if($selected){$failed=$error!==''&&($post['operation']??null)==='set-active'&&($post['id']??null)===$selected['id'];$version=$failed&&is_string($post['version']??null)?$post['version']:(string)$selected['version'];$active=$failed&&is_string($post['active']??null)&&in_array($post['active'],['0','1'],true)?$post['active']:($selected['active']?'0':'1');
            $h.='<section class="account-selected" id="account-detail"><h2>'.$e($selected['name']).'</h2><p>'.$e($selected['email']).'</p><p>'.self::state($selected['state']).' · '.($selected['totp_enabled']?'Authenticator':($selected['email_factor']?'E-Mail-Sicherheitscode':'Zweiter Faktor nicht aktiviert')).'</p>';
            if($selected['verified']&&$selected['person_active']&&$selected['actor_id']!==$owner['id'])$h.=$this->form('set-active',$route).Html::hidden('id',$selected['id']).Html::hidden('version',$version).Html::hidden('active',$active).'<button class="btn btn-solid theme-primary">'.($active==='1'?'Konto freischalten':'Konto sperren').'</button></form>';
            elseif($selected['actor_id']===$owner['id'])$h.='<p>Dein Verwaltungszugang bleibt aktiv.</p>';
            elseif(!$selected['person_active'])$h.='<p>Die persönliche Vereinszuordnung ist gesperrt.</p>';
            $h.='<a class="btn btn-outline theme-secondary" href="'.$e(self::url(['account'=>$selected['id']],'account-detail')).'">Aktuellen Stand neu öffnen</a></section>';
        }
        $h.='<section id="account-list"><h2>Konten · '.$listing['total'].'</h2><form method="get" action="/accounts#account-list" class="account-form accounts-search">'.Html::input('accounts_q','Name, E-Mail oder Benutzername',$listing['q'],'search','maxlength="150"').Html::select('accounts_state','Kontostatus',[''=>'Alle','active'=>'Aktiv','pending'=>'Einladung offen','blocked'=>'Gesperrt'],$listing['state']).'<button class="btn btn-outline theme-secondary">Suchen</button></form><div class="accounts-table"><table class="table"><caption class="visually-hidden">Persönliche Vereinskonten verwalten</caption><thead><tr><th scope="col">Persönliches Konto</th><th scope="col">Aktion</th></tr></thead><tbody>';
        foreach($listing['rows'] as $account)$h.='<tr data-account-summary><td><strong>'.$e($account['name']).'</strong><br>'.$e($account['login_name']?:$account['email']).'<br>'.self::state($account['state']).'</td><td><a class="btn btn-outline theme-secondary" href="'.$e(self::url(array_replace($route,['account'=>$account['id']]),'account-detail')).'">Öffnen<span class="visually-hidden">: '.$e($account['name']).'</span></a></td></tr>';
        if(!$listing['rows'])$h.='<tr><td colspan="2">Keine passenden Konten.</td></tr>';$h.='</tbody></table></div>'.self::pager($listing,'accounts',$route,'account-list').'</section>';
        $open=$inviteActor!==''||$people['q']!==''||$people['page']>1||($post['operation']??null)==='invite';$h.='<details class="account-invitation" id="account-invite"'.($open?' open':'').'><summary>Konto einladen</summary><form method="get" action="/accounts#account-invite" class="account-form accounts-search">'.Html::input('people_q','Person für Einladung suchen',$people['q'],'search','maxlength="150"').Html::hidden('invite_actor',$people['selected']['id']??'').'<button class="btn btn-outline theme-secondary">Personen suchen</button></form><ul class="accounts-people">';
        foreach($people['rows'] as $person)$h.='<li><a class="btn btn-outline theme-secondary" data-invite-person href="'.$e(self::url(array_replace($route,['invite_actor'=>$person['id']]),'account-invite')).'">'.$e($person['name']).'</a></li>';
        if(!$people['rows'])$h.='<li>Keine passende einladbare Person.</li>';$h.='</ul>'.self::pager($people,'people',$route,'account-invite');
        if($person=$people['selected']){$failed=$error!==''&&($post['operation']??null)==='invite'&&($post['actor_id']??null)===$person['id'];$email=$failed&&is_string($post['email']??null)&&strlen($post['email'])<=254&&mb_check_encoding($post['email'],'UTF-8')?$post['email']:$person['email'];$version=$failed&&is_string($post['version']??null)?$post['version']:(string)$person['account_version'];$h.='<h3>'.$e($person['name']).'</h3>'.$this->form('invite',$route).Html::hidden('actor_id',$person['id']).Html::hidden('version',$version).Html::input('email','E-Mail-Adresse',$email,'email','required autocomplete="off" maxlength="254"').'<button class="btn btn-solid theme-primary">'.($person['account_version']>0?'Einladung erneuern':'Einladung erstellen').'</button></form><a class="btn btn-outline theme-secondary" href="'.$e(self::url(['invite_actor'=>$person['id']],'account-invite')).'">Einladungsstand neu öffnen</a>';}
        $h.='</details>';
        return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Benutzerkonten · DVM – DeinVereinsManager</title>'.BootstrapAssets::tags().'<link rel="stylesheet" href="/assets/native.css"><link rel="stylesheet" href="/assets/account.css"></head><body><main class="accounts-admin">'.$h.'</main></body></html>';
    }
}
