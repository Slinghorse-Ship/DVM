<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication\Mail;

use PDO;
use DomainException;
use Kulturhus\Native\Shared\{Access,Db,SecretBox};
use Kulturhus\Native\Integration\ManagerAccess;
use Kulturhus\Native\Communication\Content;
use Kulturhus\Verein\InvoiceMailbox;
require_once dirname(__DIR__,3).'/src/autoload.php';

/** Optional conversation forwarding. Credentials and addresses stay encrypted. */
final class Settings
{
    public function __construct(private PDO $db,private SecretBox $box) {}
    public static function defaults():array
    {
        return ['enabled'=>false,'receive_enabled'=>false,'auto_send'=>true,'target'=>'','reply_to'=>'','imap_host'=>'','imap_user'=>'','folder'=>'INBOX','since'=>gmdate('Y-m-d'),'imap_password'=>'','binding'=>'','actor'=>'','authority'=>'native'];
    }
    public static function lock(string $club):string {return 'conversation-mail-'.substr(hash('sha256',$club),0,40);}
    public function configuration(Access $access):array
    {
        $access->require('social','manage');
        $row=Db::run($this->db,"SELECT * FROM native_private_settings WHERE club_id=? AND purpose='social-mail'".($this->db->inTransaction()?' FOR SHARE':''),[$access->club()])->fetch();
        $data=$row?$this->box->open('social-mail:'.$access->club(),$row['ciphertext']):self::defaults();
        // Existing enabled installations do not retroactively authorize mail
        // publication; the current owner selects it in the module settings.
        if(!array_key_exists('auto_send',$data))$data['auto_send']=false;
        return ['version'=>(int)($row['version']??0),'data'=>$data];
    }
    public function state(Access $access):array
    {
        $state=$this->configuration($access);$state['password_set']=$state['data']['imap_password']!=='';$state['binding']=$state['data']['binding'];
        unset($state['data']['imap_password'],$state['data']['binding'],$state['data']['actor'],$state['data']['authority']);
        $state['imap_available']=function_exists('imap_open');
        return $state;
    }
    public function save(Access $access,array $input,int $version):void
    {
        $access->require('website','manage');$access->require('social','manage');$club=$access->club();$lock=self::lock($club);
        if((int)Db::run($this->db,'SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)throw new DomainException('Postfach wird gerade abgerufen. Erneut versuchen.');
        try{Db::atomic($this->db,function()use($access,$input,$version,$club):void{
            $actor=$access->require('website','manage');$access->require('social','manage');
            $row=Db::run($this->db,"SELECT * FROM native_private_settings WHERE club_id=? AND purpose='social-mail' FOR UPDATE",[$club])->fetch();
            if((int)($row['version']??0)!==$version)throw new DomainException('Mailmodul inzwischen geändert. Neu öffnen.');
            $old=$row?$this->box->open('social-mail:'.$club,$row['ciphertext']):self::defaults();$data=self::defaults();
            foreach(['enabled','receive_enabled','auto_send']as$key){if(isset($input[$key])&&!in_array($input[$key],['1','0',1,0,true,false],true))throw new DomainException('Mailmodul-Auswahl prüfen.');$data[$key]=in_array($input[$key]??false,['1',1,true],true);}
            if($data['enabled']&&$data['auto_send']){$access->require('social','edit');$access->require('social','publish');}
            foreach(['target','reply_to','imap_host','imap_user','folder','since']as$key)$data[$key]=Content::text($input[$key]??$data[$key],254);
            foreach(['target','reply_to']as$key)if($data[$key]!=='')$data[$key]=Content::email($data[$key]);
            if($data['enabled']&&($data['target']===''||$data['reply_to']===''))throw new DomainException('Zieladresse und Antwortpostfach eintragen.');
            if($data['target']!==''&&$data['target']===$data['reply_to'])throw new DomainException('Zieladresse und Antwortpostfach müssen verschieden sein.');
            InvoiceMailbox::normalize(['enabled'=>$data['enabled']&&$data['receive_enabled'],'email'=>$data['reply_to'],'imap_host'=>$data['imap_host'],'imap_user'=>$data['imap_user'],'folder'=>$data['folder'],'since'=>$data['since']]);
            $password=$input['imap_password']??'';
            if(!is_string($password)||strlen($password)>1000||preg_match('/[\x00\r\n]/',$password))throw new DomainException('Postfachpasswort prüfen.');
            $changed=$old['imap_host']!==$data['imap_host']||$old['imap_user']!==$data['imap_user'];
            $clear=($input['clear_password']??null)==='1';
            if($changed&&$old['imap_password']!==''&&$password===''&&!$clear)throw new DomainException('Bei neuem Server oder Benutzer das Passwort erneut eingeben.');
            $data['imap_password']=$clear?'':($password!==''?$password:$old['imap_password']);
            if($data['enabled']&&$data['receive_enabled']&&$data['imap_password']==='')throw new DomainException('Passwort für den Antwortabruf eintragen.');
            $same=$row!==false;
            foreach(['target','reply_to','imap_host','imap_user','folder','since']as$key)$same=$same&&$old[$key]===$data[$key];
            $data['binding']=$same?$old['binding']:bin2hex(random_bytes(16));$data['actor']=$actor['id'];$data['authority']=$access instanceof ManagerAccess?'manager':'native';
            Db::run($this->db,"INSERT INTO native_private_settings(club_id,purpose,version,ciphertext,updated_by) VALUES(?,'social-mail',?,?,?) ON DUPLICATE KEY UPDATE version=VALUES(version),ciphertext=VALUES(ciphertext),updated_by=VALUES(updated_by)",[$club,$version+1,$this->box->seal('social-mail:'.$club,$data),$actor['id']]);
        });}finally{Db::run($this->db,'SELECT RELEASE_LOCK(?)',[$lock]);}
    }
}
