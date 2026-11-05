<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;

use Kulturhus\Native\Auth\Service;

/** Server-rendered account flows. Secrets are never put in local storage or analytics. */
final class Account
{
    private ?\Kulturhus\Native\Language\Catalog $language=null;
    private string $locale='de';
    private array $languageMessages=[];
    private ?\Kulturhus\Native\Appearance\Preferences $appearance=null;
    public function __construct(private Service $auth,private string $csrf,private string $clientKey,private string $managerPath='/',private ?\Closure $destination=null,private ?\Closure $resetDelivery=null,private ?\Closure $languageResolver=null,private ?\Closure $appearanceResolver=null){}

    public static function forNative(\PDO $db,Service $auth,string $csrf,string $clientKey):self
    {
        return new self($auth,$csrf,$clientKey,'/',null,null,static fn(array $account,string $token)=>new \Kulturhus\Native\Language\Catalog($db,new \Kulturhus\Native\Auth\SessionAccess($db,$auth,$token)),static fn(array $account,string $token)=>new \Kulturhus\Native\Appearance\Preferences($db,new \Kulturhus\Native\Auth\SessionAccess($db,$auth,$token)));
    }

    public static function forManager(\PDO $db,Service $auth,string $csrf,string $clientKey,string $managerPath='/vereinsverwaltung/'):self
    {
        return new self($auth,$csrf,$clientKey,$managerPath,static function(array $account)use($db,$managerPath):string{
            $actor=\Kulturhus\Native\Shared\Db::run($db,'SELECT a.role,a.active FROM native_manager_identities i JOIN actors a ON a.id=i.manager_actor_id AND a.club_id=i.manager_club_id WHERE i.native_id=?',[$account['actor_id']])->fetch();
            if(!$actor||!$actor['active'])throw new \DomainException('Persönliche Vereinszuordnung nicht verfügbar.');
            return $managerPath.'?view='.($actor['role']==='member'?'portal':'dashboard');
        },static function(string $identifier,string $client)use($db,$auth):void{
            \Kulturhus\Native\Auth\Mail::requestReset($db,$auth,\Kulturhus\Native\Shared\SecretBox::fromFile((string)getenv('KH_NATIVE_PRIVATE_KEY_FILE')),$identifier,$client);
        },static function(array $account)use($db):\Kulturhus\Native\Language\Catalog{
            $actor=\Kulturhus\Native\Shared\Db::run($db,'SELECT manager_actor_id FROM native_manager_identities WHERE native_id=?',[$account['actor_id']])->fetchColumn();
            if(!$actor)throw new \DomainException('Persönliche Vereinszuordnung nicht verfügbar.');
            $access=(new \Kulturhus\Native\Integration\ManagerBridge($db,$actor,'native',static fn()=>null))->access();
            if($access->user()['id']!==$account['actor_id'])throw new \DomainException('Persönliche Vereinszuordnung nicht verfügbar.');
            return new \Kulturhus\Native\Language\Catalog($db,$access);
        },static fn(array $account,string $token)=>new \Kulturhus\Native\Appearance\Preferences($db,new \Kulturhus\Native\Auth\SessionAccess($db,$auth,$token)));
    }

    private ?\Closure $systemNavigation=null;
    public function withSystemNavigation(\Closure $resolver):self{$this->systemNavigation=$resolver;return $this;}
    private bool $inManager=false;
    public function withinManager():self{$this->inManager=true;return $this;}
    private function accountUrl(string $section=''):string{return $this->inManager?$this->managerPath.'?view=settings&tab=account&account_native=1'.($section!==''?'&section='.rawurlencode($section):''):'/account'.($section!==''?'?section='.rawurlencode($section):'');}
    private bool $demoAvailable=false;
    public function withDemoLink(bool $available):self{$this->demoAvailable=$available;return $this;}

    public function action(array $post,array &$session): array
    {
        $this->languageContext($session,$_GET);
        $this->appearanceContext($session);
        if(!is_string($post['csrf']??null)||!hash_equals($this->csrf,$post['csrf']))throw new \DomainException($this->t('Formular abgelaufen. Bitte neu laden.'));
        $text=function(string $key)use($post):string {if(!is_string($post[$key]??''))throw new \DomainException($this->t('Eingaben prüfen.'));return $post[$key]??'';};
        $operation=$text('operation');$token=$session['auth_token']??'';
        $result=[];
        if($operation==='select-appearance'){
            if(!$this->appearance)throw new \DomainException('Anzeigeeinstellungen sind für diesen Zugang nicht verfügbar.');
            $version=$text('appearance_version');if(!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$version))throw new \DomainException('Gespeicherte Anzeigeversion prüfen.');
            $this->appearance->save(['admin_view'=>$text('admin_view'),'admin_color'=>$text('admin_color'),'admin_density'=>$text('admin_density')],(int)$version,$text('appearance_scope'));
            return ['redirect'=>$this->accountUrl('appearance').'#account-appearance'];
        }elseif($operation==='select-language'){
            if(!$this->language)throw new \DomainException($this->t('Sprachwahl ist für diesen Zugang nicht verfügbar.'));$this->language->select($text('interface_locale'));return ['redirect'=>$this->accountUrl('language').'#account-language'];
        }elseif($operation==='login'){
            $result=$this->auth->passwordLogin($text('email'),$text('password'),$this->clientKey);
            if($result['status']==='second_factor'){$session['auth_challenge']=$result['challenge'];$session['auth_factor_method']=$result['method']??'totp';unset($session['auth_token']);return ['notice'=>($result['method']??'')==='email'?$this->t('Ein Sicherheitscode wurde für deine bestätigte E-Mail-Adresse vorbereitet. Er gilt fünf Minuten.'):$this->t('Bitte bestätige die Anmeldung mit deinem zweiten Faktor.')];}
        }elseif($operation==='second-factor'){
            $result=$this->auth->verifySecondFactor($session['auth_challenge']??'',$text('code'),$this->clientKey);
        }elseif($operation==='logout'){
            if($token!=='')$this->auth->logout($token);
            unset($session['auth_token'],$session['auth_challenge'],$session['auth_factor_method'],$session['email_factor_pending']);session_regenerate_id(true);return ['redirect'=>'/login'];
        }elseif($operation==='accept-invitation'){
            $this->samePasswords($text('password'),$text('repeat'));
            $this->auth->acceptInvitation($text('token'),$text('password'));
            return ['notice'=>$this->t('Dein Konto ist eingerichtet. Du kannst dich jetzt anmelden.'),'login'=>true];
        }elseif($operation==='request-reset'){
            if($this->resetDelivery)($this->resetDelivery)($text('email'),$this->clientKey);
            else $this->auth->requestReset($text('email'),$this->clientKey);
            return ['notice'=>$this->t('Falls ein passendes Konto besteht, wurde die Anfrage zum Zurücksetzen verarbeitet.')];
        }elseif($operation==='reset-password'){
            $this->samePasswords($text('password'),$text('repeat'));
            $this->auth->resetPassword($text('token'),$text('password'));
            unset($session['auth_token'],$session['auth_challenge']);
            return ['notice'=>$this->t('Das Passwort wurde geändert. Bitte melde dich erneut an; dein zweiter Faktor bleibt bestehen.'),'login'=>true];
        }elseif($operation==='session-duration'){
            $result=$this->auth->selectSessionDuration($token,$text('mode'));
            $session['device_duration_saved']=true;
        }elseif($operation==='change-password'){
            $this->samePasswords($text('password'),$text('repeat'));
            $result=$this->auth->changePassword($token,$text('current_password'),$text('password'));
        }elseif($operation==='begin-email-change'){
            $version=filter_var($text('version'),FILTER_VALIDATE_INT);if($version===false||$version===null)throw new \DomainException($this->t('Gespeicherten Kontostand prüfen.'));
            $this->auth->beginEmailChange($token,$text('current_password'),$text('new_email'),$version);return ['redirect'=>$this->emailDestination()];
        }elseif($operation==='confirm-email-change'){
            $result=$this->auth->confirmEmailChange($token,$text('email_change_id'),$text('code'));
        }elseif($operation==='cancel-email-change'){
            $this->auth->cancelEmailChange($token,$text('email_change_id'));return ['redirect'=>$this->emailDestination()];
        }elseif($operation==='change-login-name'){
            $version=filter_var($text('version'),FILTER_VALIDATE_INT);
            if($version===false||$version===null)throw new \DomainException($this->t('Gespeicherten Kontostand prüfen.'));
            $this->auth->changeLoginName($token,$text('current_password'),$text('login_name'),$version);
            return ['notice'=>$this->t('Persönlicher Benutzername gespeichert. Du kannst ihn bei der nächsten Anmeldung verwenden.')];
        }elseif(in_array($operation,['enable-email-factor','disable-email-factor'],true)){
            unset($session['email_factor_pending']);
            $enable=$operation==='enable-email-factor';
            $session['email_factor_pending']=$this->auth->beginEmailFactor($token,$text('current_password'),$enable)+['enable'=>$enable];
            return ['notice'=>$this->t('Ein Sicherheitscode wurde für deine bestätigte E-Mail-Adresse vorbereitet. Es wird erst nach deiner Bestätigung etwas geändert. Der Code gilt fünf Minuten.')];
        }elseif($operation==='confirm-email-factor'){
            $pending=$session['email_factor_pending']??null;
            if(!is_array($pending)||(int)$pending['expires_at']<=time())throw new \DomainException($this->t('Bestätigung abgelaufen. Bitte erneut beginnen.'));
            $this->auth->confirmEmailFactor($token,$pending['challenge'],$text('code'),$pending['enable']);
            unset($session['email_factor_pending']);
            return ['notice'=>$pending['enable']?$this->t('E-Mail-Zwei-Faktor-Anmeldung aktiviert.'):$this->t('E-Mail-Zwei-Faktor-Anmeldung deaktiviert.')];
        }elseif($operation==='begin-totp'){
            return ['setup'=>$this->auth->beginTotp($token,$text('current_password')),'notice'=>$this->t('Trage den Einrichtungsschlüssel in deiner Authenticator-App ein und bestätige den angezeigten Code.')];
        }elseif($operation==='confirm-totp'){
            return ['recovery'=>$this->auth->confirmTotp($token,$text('code')),'notice'=>$this->t('Zwei-Faktor-Authentifizierung aktiviert. Bewahre deine Wiederherstellungscodes sicher auf.')];
        }elseif($operation==='disable-totp'){
            $this->auth->disableTotp($token,$text('current_password'),$text('code'));
            return ['notice'=>$this->t('Zwei-Faktor-Authentifizierung deaktiviert.')];
        }elseif($operation==='revoke-session'){
            $changed=$this->auth->revokeSession($token,$text('session_id'));
            return ['notice'=>$changed?$this->t('Die ausgewählte Anmeldung ist beendet.'):$this->t('Diese Anmeldung ist bereits beendet.')];
        }elseif($operation==='revoke-sessions'){
            $this->auth->revokeSessions($token);unset($session['auth_token'],$session['auth_challenge']);session_regenerate_id(true);return ['redirect'=>'/login'];
        }else throw new \DomainException($this->t('Unbekannter Kontovorgang.'));
        if(($result['status']??'')==='authenticated'){
            session_regenerate_id(true);$session['auth_token']=$result['session_token'];unset($session['auth_challenge'],$session['auth_factor_method'],$session['email_factor_pending']);
            if($operation==='confirm-email-change')return ['redirect'=>$this->emailDestination()];
            if($operation==='session-duration')return ['redirect'=>$this->managerPath.'?view=settings&tab=account#device-session'];
            $signature=$session['workflow_login_signature']??null;unset($session['workflow_login_signature']);
            if(is_string($signature)&&preg_match('/\A[a-f0-9]{32}\z/D',$signature))return ['redirect'=>$this->managerPath.'?view=portal&tab=signatures&wf_sign='.$signature];
            return ['redirect'=>$this->destination?($this->destination)($result['account']):$this->managerPath.'?view=website'];
        }
        return [];
    }

    public function render(string $path,array $query,array $session,array $state=[],string $error=''):string
    {
        $this->languageContext($session,$query);$this->appearanceContext($session);if(isset($state['notice'])&&is_string($state['notice']))$state['notice']=$this->t($state['notice']);$error=$this->t($error);
        $e=Html::e(...);$account=null;
        if(!empty($session['auth_token']))$account=$this->auth->resolve($session['auth_token']);
        $heading=$this->t('Anmelden');$content='';
        $identifier=$error!==''&&is_string($_POST['email']??null)&&strlen($_POST['email'])<=254&&preg_match('//u',$_POST['email'])===1&&!preg_match('/[\x00-\x1F\x7F]/',$_POST['email'])?$_POST['email']:'';
        if($account){
            $heading=$this->t('Dein Konto');
            $operation=($_SERVER['REQUEST_METHOD']??'')==='POST'&&is_string($_POST['operation']??null)?$_POST['operation']:'';
            $section=match($operation){
                'begin-email-change','confirm-email-change','cancel-email-change'=>'email',
                'change-login-name'=>'username','change-password'=>'password',
                'begin-totp','confirm-totp','disable-totp','enable-email-factor','disable-email-factor','confirm-email-factor'=>'security',
                'select-appearance'=>'appearance','select-language'=>'language','session-duration'=>'device','logout','revoke-sessions','revoke-session'=>'sessions',default=>is_string($query['section']??null)?$query['section']:'',
            };
            if(isset($state['setup'])||isset($state['recovery'])||isset($session['email_factor_pending']))$section='security';
            $content='<p class="account-identity">'.$e($account['email']).(!empty($account['login_name'])?'<br>'.Html::e($this->t('Benutzername:')).' '.$e($account['login_name']):'').'</p><p><a class="btn btn-solid theme-primary button" href="'.$e($this->destination?($this->destination)($account):$this->managerPath.'?view=website').'">'.Html::e($this->t('Vereinsmanager öffnen →')).'</a></p>';
            if($this->language){$choices=[];foreach($this->language->available() as $language)$choices[$language['code']]=$language['label'];$body='<p>'.Html::e($this->t('Die Auswahl gilt für deinen Zugang. Website-Inhalte behalten ihre eigene Sprache.')).'</p>'.$this->form('select-language').Html::select('interface_locale',$this->t('Sprache auswählen'),$choices,$this->language->locale()).'<button class="btn btn-solid theme-primary">'.Html::e($this->t('Sprache speichern')).'</button></form>';$content.=$this->panel('language',$this->t('Oberflächensprache'),$body,$section==='language');}
            if($this->appearance){$body=\Kulturhus\Native\Appearance\View::render($this->appearance->state(),$this->csrf,$operation==='select-appearance'&&$error!==''?$_POST:null,$operation==='select-appearance'?$error:'',$this->locale);$content.=$this->panel('appearance',explode('-',$this->locale,2)[0]==='en'?'Administration layout':'Verwaltungsansicht',$body,$section==='appearance');}
            $pending=$this->auth->emailChangeState($session['auth_token']);$emailSubmitted=$operation==='begin-email-change'&&$error!=='';
            $newEmail=$emailSubmitted&&is_string($_POST['new_email']??null)?$_POST['new_email']:'';$emailVersion=$emailSubmitted&&is_string($_POST['version']??null)?$_POST['version']:$account['version'];$body='';
            if($pending){$body.='<p>'.Html::e($pending['email']).'</p>'.$this->form('confirm-email-change').Html::hidden('email_change_id',$pending['id']).$this->emailCode().'<button class="btn btn-solid theme-primary">'.Html::e($this->t('Neue Login-Adresse bestätigen')).'</button></form>'.$this->form('cancel-email-change').Html::hidden('email_change_id',$pending['id']).'<button class="btn btn-outline theme-secondary">'.Html::e($this->t('Anfrage beenden')).'</button></form>';}
            if(!$pending||$emailSubmitted)$body.=$this->form('begin-email-change').Html::hidden('version',$emailVersion).Html::input('new_email',$this->t('Neue Login-E-Mail-Adresse'),$newEmail,'email','required maxlength="254" autocomplete="email" autocapitalize="none" spellcheck="false"').$this->password('current_password',$this->t('Aktuelles Passwort'),'current-password').'<button class="btn btn-solid theme-primary">'.Html::e($this->t('Bestätigungscode anfordern')).'</button></form>';
            $content.=$this->panel('email',$this->t('Login-E-Mail-Adresse ändern'),$body,$section==='email','account-panel account-email-address');
            $submitted=$operation==='change-login-name'&&$error!=='';
            $name=$submitted&&is_string($_POST['login_name']??null)?$_POST['login_name']:($account['login_name']??'');
            $version=$submitted&&is_string($_POST['version']??null)?$_POST['version']:$account['version'];
            $body='<p>'.Html::e($this->t('Bis zu 90 Kleinbuchstaben, Zahlen, Punkte oder Bindestriche. Jede Person hat einen eigenen Benutzernamen, auch bei einem gemeinsamen Familienpostfach. Die E-Mail-Adresse bleibt erhalten.')).'</p>'.$this->form('change-login-name').Html::hidden('version',$version).Html::input('login_name',$this->t('Persönlicher Benutzername'),$name,'text','required maxlength="90" pattern="[a-z0-9][a-z0-9.\-]{0,89}" autocomplete="username" autocapitalize="none" spellcheck="false"').$this->password('current_password',$this->t('Aktuelles Passwort'),'current-password').'<button class="btn btn-solid theme-primary">'.Html::e($this->t('Benutzernamen speichern')).'</button></form>';
            $content.=$this->panel('username',$this->t('Persönlichen Benutzernamen bearbeiten'),$body,$section==='username','account-login-name');
            $body=$this->form('change-password').$this->password('current_password',$this->t('Aktuelles Passwort'),'current-password').$this->newPassword().'<button class="btn btn-solid theme-primary">'.Html::e($this->t('Passwort ändern')).'</button></form>';
            $content.=$this->panel('password',$this->t('Passwort ändern'),$body,$section==='password');
            $body='<p>'.($account['totp_enabled']?$this->t('Authenticator aktiviert'):($account['email_factor']?$this->t('E-Mail-Faktor aktiviert'):$this->t('Noch nicht aktiviert'))).'</p>';
            if(isset($state['recovery'])){
                $body.='<div class="account-codes" role="status"><h3>'.Html::e($this->t('Wiederherstellungscodes')).'</h3><p>'.Html::e($this->t('Jeder Code funktioniert einmal. Diese Liste wird nur jetzt angezeigt.')).'</p><ul>';
                foreach($state['recovery']['recovery_codes'] as $code)$body.='<li><code>'.$e($code).'</code></li>';
                $body.='</ul></div>';
            }
            if(!$account['totp_enabled']&&!$account['email_factor']){
                if(isset($state['setup']))$body.='<div class="account-codes"><p>'.Html::e($this->t('Einrichtungsschlüssel')).'</p><code>'.$e($state['setup']['secret']).'</code><p><a href="'.$e($state['setup']['otpauth_uri']).'">'.Html::e($this->t('Mit Authenticator-App öffnen')).'</a></p></div>';
                $body.=$this->form('begin-totp').$this->password('current_password',$this->t('Aktuelles Passwort'),'current-password').'<button class="btn btn-solid theme-primary">'.Html::e($this->t('Authenticator einrichten')).'</button></form>';
                $body.=$this->form('confirm-totp').$this->code().'<button class="btn btn-solid theme-primary">'.Html::e($this->t('Einrichtung bestätigen')).'</button></form>';
            }elseif($account['totp_enabled']) $body.='<details'.($operation==='disable-totp'?' open':'').'><summary>'.Html::e($this->t('Zweiten Faktor deaktivieren')).'</summary>'.$this->form('disable-totp').$this->password('current_password',$this->t('Aktuelles Passwort'),'current-password').$this->code().'<button class="btn btn-solid theme-primary">'.Html::e($this->t('2FA deaktivieren')).'</button></form></details>';
            if(!$account['totp_enabled']){
                $body.='<h3>'.Html::e($this->t('Zwei-Faktor-Anmeldung per E-Mail')).'</h3><p>'.($account['email_factor']?$this->t('Aktiviert'):$this->t('Noch nicht aktiviert')).'. Der Sicherheitscode gilt jeweils fünf Minuten und gehört zu deinem persönlichen Konto.</p>';
                if(isset($session['email_factor_pending'])&&(int)$session['email_factor_pending']['expires_at']>time())$body.=$this->form('confirm-email-factor').$this->emailCode().'<button class="btn btn-solid theme-primary">'.Html::e($this->t('E-Mail-Code bestätigen')).'</button></form>';
                $body.=$this->form($account['email_factor']?'disable-email-factor':'enable-email-factor').$this->password('current_password',$this->t('Aktuelles Passwort'),'current-password').'<button class="btn btn-solid theme-primary">'.($account['email_factor']?$this->t('Deaktivierungscode anfordern'):$this->t('Aktivierungscode anfordern')).'</button></form>';
            }
            $content.=$this->panel('security',$this->t('Zwei-Faktor-Authentifizierung'),$body,$section==='security');
            $duration=$this->auth->sessionDuration($session['auth_token']);
            $body='<p>'.Html::e($this->t('Diese Auswahl gilt für die aktuelle Anmeldung auf diesem Gerät. Bei 24 Stunden und 30 Tagen beginnt die Frist mit deiner Nutzung jeweils neu. Browserdaten zu löschen erfordert eine neue Anmeldung.')).'</p>'.$this->form('session-duration').Html::select('mode',$this->t('Wie lange möchtest du angemeldet bleiben?'),$this->durationLabels(),$duration['mode']).'<button class="btn btn-solid theme-primary">'.Html::e($this->t('Anmeldedauer speichern')).'</button></form>';
            $content.=$this->panel('device',$this->t('Auf diesem Gerät angemeldet bleiben'),$body,$section==='device');
            $body=$this->sessionList($session['auth_token'],$query['session_page']??1);
            $body.=$this->form('logout').'<button class="btn btn-solid theme-primary">'.Html::e($this->t('Auf diesem Gerät abmelden')).'</button></form>'.$this->form('revoke-sessions').'<button class="btn btn-solid theme-primary">'.Html::e($this->t('Alle Sitzungen beenden')).'</button></form>';
            $content.=$this->panel('sessions',$this->t('Sitzungen und Abmelden'),$body,$section==='sessions');
            $content.='<details class="account-panel"><summary>'.Html::e($this->t('Technische Einrichtung')).'</summary>';
            if($this->systemNavigation&&($this->systemNavigation)((string)($session['auth_token']??'')))$content.='<p><a class="btn btn-outline theme-secondary" href="/vereinsverwaltung/?view=settings&amp;tab=system">'.Html::e($this->t('Systemverwaltung')).'</a></p>';
            $content.='<p><a class="btn btn-outline theme-secondary" href="/vereinsverwaltung/?view=settings&amp;tab=api">'.Html::e($this->t('API-Zugänge')).'</a></p></details>';
        }elseif(!empty($session['auth_challenge'])){
            $email=($session['auth_factor_method']??'')==='email';
            $heading=$this->t('Anmeldung bestätigen');$content='<p>'.($email?$this->t('Gib den achtstelligen Code aus deiner E-Mail ein. Er gilt fünf Minuten und funktioniert einmal.'):$this->t('Gib den aktuellen Code deiner Authenticator-App oder einen Wiederherstellungscode ein.')).'</p>'.$this->form('second-factor').($email?$this->emailCode():$this->code()).'<button class="btn btn-solid theme-primary">'.Html::e($this->t('Anmeldung bestätigen')).'</button></form>'.$this->form('logout').'<button class="btn btn-solid theme-secondary secondary">'.Html::e($this->t('Abbrechen')).'</button></form>';
        }elseif(in_array($path,['/invite','/reset'],true)&&empty($state['login'])){
            $heading=$path==='/invite'?$this->t('Konto einrichten'):$this->t('Neues Passwort setzen');
            $content=$this->form($path==='/invite'?'accept-invitation':'reset-password').Html::hidden('token',is_string($query['token']??null)?$query['token']:'').$this->newPassword().'<button class="btn btn-solid theme-primary">'.$heading.'</button></form>';
        }elseif($path==='/forgot'&&empty($state['login'])){
            $heading=$this->t('Wieder Zugang erhalten');$content=$this->form('request-reset').$this->email($identifier).'<button class="btn btn-solid theme-primary">'.Html::e($this->t('Zurücksetzen anfordern')).'</button></form><p><a href="/login">'.Html::e($this->t('Zur Anmeldung')).'</a></p>';
        }else{
            $content=$this->form('login').$this->email($identifier).$this->password('password',$this->t('Passwort'),'current-password').'<button class="btn btn-solid theme-primary">'.Html::e($this->t('Anmelden und Vereinsmanager öffnen')).'</button></form><p><a href="/forgot">'.Html::e($this->t('Passwort vergessen?')).'</a></p><p>'.Html::e($this->t('Ein neues Konto richtest du über die Einladung deines Vereins ein.')).'</p>';
        }
        if(!$account&&$path==='/login'&&$this->demoAvailable)$content.='<p><a class="btn btn-outline theme-secondary" href="/demo/">'.$e($this->t('Demo ausprobieren')).'</a></p>';
        $notice=isset($state['notice'])?'<p'.($error===''?' id="account-result" tabindex="-1"':'').' role="status">'.$e($state['notice']).'</p>':'';
        if($error!=='')$notice.='<p id="account-result" tabindex="-1" role="alert" class="portal-login__error">'.$e($error).'</p>';
        $languageLinks='';if(!$account){$languageLinks='<nav class="account-language-choice" aria-label="'.$e($this->t('Oberflächensprache')).'">';foreach(['de'=>'Deutsch','en'=>'English'] as $code=>$label){$values=['lang'=>$code];if(is_string($query['token']??null))$values['token']=$query['token'];$target=(in_array($path,['/login','/forgot','/invite','/reset'],true)?$path:'/login').'?'.http_build_query($values);$languageLinks.='<a class="btn btn-outline theme-secondary" href="'.$e($target).'" lang="'.$code.'"'.($this->locale===$code?' aria-current="page"':'').'>'.$e($label).'</a>';}$languageLinks.='</nav>';}
        $appearanceAssets='<link rel="stylesheet" href="/assets/form-validation.css"><script defer src="/assets/form-validation.js"></script>'.($this->appearance?\Kulturhus\Native\Appearance\Chrome::assets():'');
        $appearanceAttributes=$account&&$this->appearance?\Kulturhus\Native\Appearance\Values::attributes($this->appearance->state()['data']):'';
        if($this->inManager&&$account){$content=str_replace(['href="/account?','href="/account"'],['href="'.Html::e($this->accountUrl()).'&amp;','href="'.Html::e($this->accountUrl()).'"'],$content);return '<link rel="stylesheet" href="account-native.css"><section class="content native-account-security" data-native-account><header class="page-heading"><h1>'.$e($heading).'</h1><a class="btn btn-outline theme-secondary" href="?view=settings&amp;tab=account">'.$e($this->t('Mein Account')).'</a></header>'.$notice.$content.'</section>';}
        return '<!doctype html><html lang="'.$e($this->locale).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>'.$e($heading).' · DVM – DeinVereinsManager</title>' . BootstrapAssets::tags() . '<link rel="stylesheet" href="/assets/kulturhus-site.css"><link rel="stylesheet" href="/assets/kulturhus-enhancements.css"><link rel="stylesheet" href="/assets/account.css">'.$appearanceAssets.'</head><body class="theme-kultur account-page'.($account?' account-signed-in':'').'"'.$appearanceAttributes.'><main id="main" class="portal-login"><section class="portal-login__intro"><p class="eyebrow">DVM · DeinVereinsManager</p><h1>'.Html::e($this->t('Willkommen bei')).' '.'<br>DeinVereinsManager.</h1><p>'.Html::e($this->t('Ein gemeinsamer Ort für unsere Vereinsarbeit.')).'</p><ul><li>'.Html::e($this->t('Mitglieder und Anträge verwalten')).'</li><li>'.Html::e($this->t('Aufnahmen im Vorstand entscheiden')).'</li><li>'.Html::e($this->t('Website und Newsletter gestalten')).'</li></ul><a href="/site/start">'.Html::e($this->t('← Zurück zur Website')).'</a></section><section class="portal-login__card"'.($notice===''?' id="account-result" tabindex="-1"':'').'>'.$languageLinks.'<h2>'.$e($heading).'</h2>'.$notice.$content.'</section></main></body></html>';
    }
    private function emailDestination():string{return $this->accountUrl('email').'#account-email';}
    private function appearanceContext(array $session):void
    {
        $this->appearance=null;if(!$this->appearanceResolver||!is_string($session['auth_token']??null)||$session['auth_token']==='')return;
        $account=$this->auth->resolve($session['auth_token']);$preferences=($this->appearanceResolver)($account,$session['auth_token']);
        if(!$preferences instanceof \Kulturhus\Native\Appearance\Preferences)throw new \LogicException('Invalid appearance service.');$this->appearance=$preferences;
    }
    private function languageContext(array $session,array $query):void
    {
        $this->language=null;$this->locale=is_string($query['lang']??null)&&in_array($query['lang'],['de','en'],true)?$query['lang']:'de';$this->languageMessages=\Kulturhus\Native\Language\AccountMessages::messages($this->locale);
        if(!$this->languageResolver||!is_string($session['auth_token']??null)||$session['auth_token']==='')return;
        try{$account=$this->auth->resolve($session['auth_token']);if(!$account)return;$catalog=($this->languageResolver)($account,$session['auth_token']);if(!$catalog instanceof \Kulturhus\Native\Language\Catalog)throw new \DomainException($this->t('Sprachwahl ist für diesen Zugang nicht verfügbar.'));$this->language=$catalog;$this->locale=$catalog->locale();$this->languageMessages=$catalog->messages();}catch(\DomainException){}
    }
    private function t(string $source):string{return $this->languageMessages[\Kulturhus\Native\Language\AccountMessages::key($source)]??$source;}
    private function durationLabels():array{$labels=\Kulturhus\Native\Auth\Sessions::LABELS;foreach($labels as &$label)$label=$this->t($label);unset($label);return $labels;}
    private function panel(string $id,string $label,string $body,bool $open,string $class='account-panel'):string
    {
        return '<details class="'.$class.'"'.($open?' open':'').' id="account-'.$id.'" name="account-settings"><summary>'.Html::e($label).'</summary>'.$body.'</details>';
    }
    private function sessionList(#[\SensitiveParameter]string $token,mixed $page):string
    {
        try{$list=$this->auth->sessions($token,$page);}catch(\DomainException){return '<p role="alert">'.Html::e($this->t('Anmeldungen konnten nicht angezeigt werden.')).' '.'<a href="/account?section=sessions#account-sessions">'.Html::e($this->t('Aktuellen Stand öffnen')).'</a></p>';}
        $e=Html::e(...);$pager='';
        if($list['pages']>1)$pager='<form class="account-form account-session-pages" method="get" action="/account#account-sessions">'.Html::hidden('section','sessions').Html::input('session_page',$this->t('Anmeldungsseite'),(string)$list['page'],'number','required min="1" max="'.$list['pages'].'" step="1"').'<span>'.Html::e($this->t('von')).' '.$list['pages'].'</span><button class="btn btn-solid theme-primary">'.Html::e($this->t('Seite anzeigen')).'</button></form>';
        $html='<p>'.$list['total'].' aktive '.($list['total']===1?$this->t('Anmeldung'):$this->t('Anmeldungen')).'</p>'.$pager.'<ul class="account-session-list">';
        $time=static function(int $stamp)use($e):string{$date=(new \DateTimeImmutable('@'.$stamp))->setTimezone(new \DateTimeZone('Europe/Berlin'));return '<time datetime="'.$e($date->format(DATE_ATOM)).'">'.$e($date->format('d.m.Y · H:i')).'</time>';};
        foreach($list['rows']as$row){
            $html.='<li'.($row['current']?' class="account-current-session"':'').'><strong>'.($row['current']?$this->t('Aktuelle Anmeldung'):$this->t('Weitere Anmeldung')).'</strong><p>'.Html::e($this->t('Begonnen:')).' '.$time($row['created_at']).'<br>'.Html::e($this->t('Zuletzt genutzt:')).' '.$time($row['last_seen']??$row['created_at']).'<br>'.Html::e($this->t('Gültig bis:')).' '.$time($row['expires_at']).'<br>'.$e($this->durationLabels()[$row['duration_mode']]).'</p>';
            if(!$row['current'])$html.=$this->form('revoke-session').Html::hidden('session_id',$row['id']).'<button class="btn btn-outline theme-secondary secondary">'.Html::e($this->t('Diese Anmeldung beenden')).'</button></form>';
            $html.='</li>';
        }
        return $html.'</ul>'.$pager;
    }
    private function form(string $operation):string{return '<form method="post" action="'.($this->inManager?Html::e($this->accountUrl()):'').'#account-result" class="account-form">'.Html::hidden('csrf',$this->csrf).($this->inManager?Html::hidden('action','account-native'):'').Html::hidden('operation',$operation);}
    private function email(string $value=''):string{return '<label>'.Html::e($this->t('E-Mail-Adresse oder persönlicher Benutzername')).'<input class="form-control" name="email" type="text" value="'.Html::e($value).'" autocomplete="username" required maxlength="254" autocapitalize="none" spellcheck="false"></label>';}
    private function password(string $name,string $label,string $autocomplete):string{return '<label>'.Html::e($label).'<input class="form-control" name="'.$name.'" type="password" autocomplete="'.$autocomplete.'" required maxlength="200"></label>';}
    private function newPassword():string{return $this->password('password',$this->t('Neues Passwort'),'new-password').$this->password('repeat',$this->t('Passwort wiederholen'),'new-password').'<p>'.Html::e($this->t('Mindestens 12 Zeichen.')).'</p>';}
    private function code():string{return '<label>'.Html::e($this->t('Authenticator- oder Wiederherstellungscode')).'<input class="form-control" name="code" autocomplete="one-time-code" required maxlength="80" spellcheck="false"></label>';}
    private function emailCode():string{return '<label>'.Html::e($this->t('Code aus deiner E-Mail')).'<input class="form-control" name="code" inputmode="numeric" pattern="[0-9]{8}" maxlength="8" autocomplete="one-time-code" required spellcheck="false"></label>';}
    private function samePasswords(string $first,string $second):void{if(!hash_equals($first,$second))throw new \DomainException($this->t('Die Passwörter stimmen nicht überein.'));}
}
