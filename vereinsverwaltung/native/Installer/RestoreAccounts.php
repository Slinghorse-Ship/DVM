<?php declare(strict_types=1);
namespace Kulturhus\Native\Installer;

use DomainException;
use PDO;
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Native\Shared\{Db,OperationGate,SecretBox};

/** Private operator handoff after one authenticated completed restore, never a web account reset. */
final class RestoreAccounts
{
    public function __construct(private string $source){}

    public function inspect(string $root,PDO $db,string $dsn,#[\SensitiveParameter]string $backupKey,string $accountId):array
    {
        self::id($accountId);$root=$this->root($root);$gate=OperationGate::exclusive($root.'/private');
        try{
            $permit=$this->permit($root,$db,$dsn,$backupKey);$row=$this->account($db,$permit,$accountId);
            $result=self::metadata($row)+['eligible'=>$row['totp_secret']!==null&&!(bool)$row['email_factor'],'restore_intent'=>$permit['intent']];
            $path=$this->paths($root,$permit['intent'],$accountId,null,false)['receipt'];
            if(file_exists($path)||is_link($path)){
                $receipt=$this->receipt($path,$permit,$accountId,$backupKey);
                $result['handoff']=['request_key'=>$receipt['request_key'],'state'=>$receipt['state'],'source_current'=>hash_equals($receipt['source'],self::stamp($row,$backupKey))];
            }
            return $result;
        }finally{$gate->close();}
    }

    /** Returns metadata and a private file path only. Plain recovery codes never leave this service in JSON. */
    public function issue(string $root,PDO $db,string $dsn,#[\SensitiveParameter]string $backupKey,array $input):array
    {
        $input=self::input($input);$root=$this->root($root);$gate=OperationGate::exclusive($root.'/private');
        try{
            $permit=$this->permit($root,$db,$dsn,$backupKey);
            $paths=$this->paths($root,$permit['intent'],$input['account_id'],$input['request_key'],true);
            $context=self::context($permit,$input['account_id']);$box=self::box($backupKey);$replayed=false;
            $receipt=Db::atomic($db,function()use($db,$permit,$input,$paths,$backupKey,$box,$context,&$replayed):array{
                $row=$this->account($db,$permit,$input['account_id']);
                if((int)$row['version']!==$input['account_version']||!hash_equals(self::alias($row),$input['original_alias']))throw new DomainException('Ursprüngliches Konto, Anmeldename oder Kontoversion inzwischen geändert.');
                if($row['totp_secret']===null||(bool)$row['email_factor'])throw new DomainException('Dieses Konto besitzt keinen aktiven Authenticator-Faktor für Wiederherstellungscodes.');
                $stamp=self::stamp($row,$backupKey);
                if(file_exists($paths['receipt'])||is_link($paths['receipt'])){
                    $receipt=$this->receipt($paths['receipt'],$permit,$input['account_id'],$backupKey);
                    if($receipt['request_key']!==$input['request_key']||$receipt['version']!==$input['account_version']||!hash_equals($receipt['alias'],$input['original_alias'])||!hash_equals($receipt['source'],$stamp))throw new DomainException('Für dieses Konto besteht bereits eine andere ursprüngliche Codeübergabe. Gespeicherten Auftrag prüfen.');
                    $replayed=true;
                }else{
                    $codes=[];for($i=0;$i<10;$i++)$codes[]=implode('-',str_split(bin2hex(random_bytes(10)),5));
                    $receipt=['format'=>1,'intent'=>$permit['intent'],'account_id'=>$input['account_id'],'request_key'=>$input['request_key'],'version'=>$input['account_version'],'alias'=>$input['original_alias'],'source'=>$stamp,'issued_at'=>time(),'state'=>'prepared','codes'=>$codes];
                    UpgradeFiles::atomic($paths['receipt'],json_encode(['cipher'=>$box->seal($context,$receipt)],JSON_THROW_ON_ERROR)."\n");
                }
                $hashes=self::hashes($receipt['codes']);
                $rows=Db::run($db,'SELECT code_hash,used_at,revoked_at FROM native_auth_recovery WHERE account_id=? AND code_hash IN ('.implode(',',array_fill(0,10,'?')).') FOR UPDATE',[$input['account_id'],...$hashes])->fetchAll(PDO::FETCH_ASSOC);
                if($rows!==[]){
                    if(count($rows)!==10||array_filter($rows,static fn(array $r):bool=>$r['revoked_at']!==null))throw new DomainException('Ursprüngliche Codeübergabe ist unvollständig oder bereits widerrufen.');
                }else{
                    if($receipt['state']!=='prepared'||Db::run($db,'SELECT 1 FROM native_auth_recovery WHERE account_id=? AND revoked_at IS NULL LIMIT 1 FOR UPDATE',[$input['account_id']])->fetchColumn())throw new DomainException('Das Konto besitzt bereits neuere Wiederherstellungscodes.');
                    foreach($hashes as$hash)Db::run($db,'INSERT INTO native_auth_recovery(account_id,code_hash) VALUES(?,?)',[$input['account_id'],$hash]);
                }
                // Validate an existing output before accepting a new database commit.
                self::existingOutput($paths['output'],self::text($receipt));
                return $receipt;
            });
            // A lost COMMIT acknowledgement leaves the prepared encrypted receipt;
            // the next same request reconciles the ten actual hashes, never minting again.
            $receipt['state']='issued';
            UpgradeFiles::atomic($paths['receipt'],json_encode(['cipher'=>$box->seal($context,$receipt)],JSON_THROW_ON_ERROR)."\n");
            $text=self::text($receipt);
            if(!self::existingOutput($paths['output'],$text))UpgradeFiles::atomic($paths['output'],$text);
            return ['status'=>$replayed?'replayed':'issued','account_id'=>$input['account_id'],'version'=>$input['account_version'],'code_count'=>10,'output_file'=>$paths['output'],'receipt_id'=>$input['account_id'],'restore_intent'=>$permit['intent']];
        }finally{$gate->close();}
    }

    private function root(string $root):string
    {
        if(PHP_SAPI!=='cli')throw new DomainException('Private CLI-Kontoübergabe erforderlich.');
        require_once $this->source.'/distribution/src/PackageStore.php';
        if(!str_starts_with($root,'/')||preg_match('~(?:^|/)\.\.?(/|$)|[\x00-\x1f]~',$root)||is_link($root)||!is_dir($root)||realpath($root)!==rtrim($root,'/'))throw new DomainException('Ursprüngliches privates Wiederherstellungsziel auswählen.');
        \PackageStore::directory($root);return rtrim($root,'/');
    }

    private function permit(string $root,PDO $db,string $dsn,#[\SensitiveParameter]string $backupKey):array
    {
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$db->inTransaction())throw new DomainException('Eigene MySQL-Verbindung ohne offenen Speichervorgang erforderlich.');
        foreach(['/restore-progress.json','/private/restore-hold','/private/upgrade-hold']as$suffix)if(file_exists($root.$suffix)||is_link($root.$suffix))throw new DomainException('Begonnene Wiederherstellung oder Paketaktualisierung zuerst abschließen.');
        $state=(new RestoreProgress($root,$backupKey))->read(true);
        $credentials=$state['credentials']??null;$time=$state['security_review_at']??null;
        if(($state['phase']??null)!=='done'||!preg_match('/\A[a-f0-9]{48}\z/D',$state['intent']??'')||!is_int($time)||$time<1||!is_array($credentials)||($credentials['format']??null)!==1||($credentials['intent']??null)!==$state['intent']||($credentials['security_review_at']??null)!==$time||!is_int($credentials['recovery_codes_revoked']??null)||$credentials['recovery_codes_revoked']<0)throw new DomainException('Abgeschlossener signierter Kontowiderruf dieser Wiederherstellung fehlt.');
        $environmentFile=$root.'/private/environment.json';UpgradeFiles::privateFile($environmentFile);$environment=\PackageStore::json($environmentFile);$installation=\PackageStore::json($root.'/installation.json');
        self::webRoot($root,(string)($environment['VEREIN_WEB_ROOT']??$root.'/web'));
        $database=(string)$db->query('SELECT DATABASE()')->fetchColumn();$server=(string)$db->query('SELECT @@server_uuid')->fetchColumn();
        if(!preg_match('/(?:mysql:|;)dbname=([a-zA-Z0-9_]+)(?:;|$)/',$dsn,$match)||($match[1]??'')!==$database||($environment['VEREIN_DATABASE']??null)!==$dsn||($state['database']??null)!==$database||($state['server']??null)!==$server||($installation['target']??null)!==$root||($installation['source']??null)!==($state['source']??null)||!is_string($state['source']??null)||!hash_equals($state['source'],(string)hash_file('sha256',$this->source.'/distribution/source-files.json'))||!is_string($environment['VEREIN_CLUB']??null)||$environment['VEREIN_CLUB']==='')throw new DomainException('Kontowiederherstellung gehört nicht zu dieser ursprünglichen Installation und Datenbank.');
        return ['root'=>$root,'intent'=>$state['intent'],'club'=>$environment['VEREIN_CLUB'],'database'=>$database,'server'=>$server,'source'=>$state['source']];
    }

    private function account(PDO $db,array $permit,string $id):array
    {
        self::id($id);$lock=$db->inTransaction()?' FOR UPDATE':'';
        $row=Db::run($db,'SELECT a.*,u.club_id,u.name,u.active AS native_active FROM native_auth_accounts a JOIN native_users u ON u.id=a.actor_id WHERE a.id=?'.$lock,[$id])->fetch(PDO::FETCH_ASSOC);
        if(!$row||!$row['active']||!$row['verified']||$row['suspended']||!$row['native_active']||$row['password_hash']===null||$row['club_id']!==ManagerBridge::clubKey($permit['club']))throw new DomainException('Aktives bestätigtes persönliches Konto dieser Wiederherstellung auswählen.');
        $binding=Db::run($db,'SELECT manager_actor_id,manager_club_id FROM native_manager_identities WHERE native_id=?'.($db->inTransaction()?' FOR SHARE':''),[$row['actor_id']])->fetch(PDO::FETCH_ASSOC);
        if($binding){
            $actor=Db::run($db,'SELECT club_id,active FROM actors WHERE id=?'.($db->inTransaction()?' FOR SHARE':''),[$binding['manager_actor_id']])->fetch(PDO::FETCH_ASSOC);
            if($binding['manager_club_id']!==$permit['club']||$row['actor_id']!==ManagerBridge::actorKey($binding['manager_actor_id'],$permit['club'])||!$actor||!$actor['active']||$actor['club_id']!==$permit['club'])throw new DomainException('Persönliche Vereinszuordnung des Kontos ist nicht mehr gültig.');
        }
        $row['_binding']=$binding?:null;return $row;
    }

    private static function input(array $input):array
    {
        if(array_diff(array_keys($input),['account_id','original_alias','identity_reviewed','account_version','request_key'])||($input['identity_reviewed']??null)!==true||!is_int($input['account_version']??null)||$input['account_version']<1||!is_string($input['original_alias']??null)||$input['original_alias']===''||strlen($input['original_alias'])>254||!preg_match('//u',$input['original_alias'])||preg_match('/[\x00-\x1f\x7f]/',$input['original_alias']))throw new DomainException('Persönliche Identität, ursprünglichen Anmeldenamen und Kontoversion ausdrücklich bestätigen.');
        self::id($input['account_id']??null);self::id($input['request_key']??null);return $input;
    }
    private static function id(mixed $id):void{if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new DomainException('Genaue ursprüngliche Konto- und Auftragskennung auswählen.');}
    private static function webRoot(string $root,string $webRoot):void
    {
        if(!str_starts_with($webRoot,'/')||preg_match('~(?:^|/)\.\.?(/|$)|[\x00-\x1f]~',$webRoot)||!is_dir($webRoot)||is_link($webRoot))throw new DomainException('Tatsächliches unverlinktes Webverzeichnis prüfen.');
        // Validate every existing component before comparing actual filesystem paths.
        \PackageStore::directory($webRoot);$web=realpath($webRoot);$private=realpath($root.'/private');
        if(!$web||!$private||$private===$web||str_starts_with($private,$web.'/'))throw new DomainException('Private Codeübergabe muss außerhalb des Webverzeichnisses liegen.');
    }
    private static function alias(array $row):string{return $row['login_name']??$row['email'];}
    private static function metadata(array $row):array{return ['account_id'=>$row['id'],'version'=>(int)$row['version'],'original_alias'=>self::alias($row),'name'=>$row['name']];}
    private static function box(#[\SensitiveParameter]string $key):SecretBox{return new SecretBox(hash_hkdf('sha256',hex2bin($key),32,'kulturhus-restore-account-codes-v1'));}
    private static function context(array $permit,string $id):string{return 'restore-account-codes:'.$permit['intent'].':'.$permit['root'].':'.$permit['database'].':'.$permit['server'].':'.$id;}
    private static function stamp(array $row,#[\SensitiveParameter]string $key):string
    {
        return hash_hmac('sha256',json_encode(array_intersect_key($row,array_flip(['id','actor_id','club_id','version','email','login_name','password_hash','totp_secret','email_factor','factor_email','active','verified','suspended','native_active','_binding'])),JSON_THROW_ON_ERROR),hash_hkdf('sha256',hex2bin($key),32,'kulturhus-restore-account-source-v1'));
    }
    private function paths(string $root,string $intent,string $id,?string $request,bool $create):array
    {
        $base=$root.'/private/restore-account-codes/'.$intent;
        foreach([$root.'/private/restore-account-codes',$base,$base.'/receipts',$base.'/output']as$directory){
            if(!$create&&!file_exists($directory)&&!is_link($directory))continue;
            if(!file_exists($directory)&&!is_link($directory)&&!mkdir($directory,0700))throw new DomainException('Private Codeübergabe konnte nicht vorbereitet werden.');
            if(is_link($directory)||!is_dir($directory)||(fileperms($directory)&0077))throw new DomainException('Codeübergabe muss ausschließlich privat gespeichert sein.');
        }
        return ['receipt'=>$base.'/receipts/'.$id.'.json','output'=>$request===null?null:$base.'/output/'.$request.'.txt'];
    }
    private function receipt(string $file,array $permit,string $id,#[\SensitiveParameter]string $key):array
    {
        UpgradeFiles::privateFile($file);if(filesize($file)>32768)throw new DomainException('Ursprünglicher Codeübergabenachweis ist ungültig.');
        $envelope=\PackageStore::json($file);if(array_keys($envelope)!==['cipher']||!is_string($envelope['cipher']))throw new DomainException('Ursprünglicher Codeübergabenachweis ist ungültig.');
        $receipt=self::box($key)->open(self::context($permit,$id),$envelope['cipher']);
        if(($receipt['format']??null)!==1||($receipt['intent']??null)!==$permit['intent']||($receipt['account_id']??null)!==$id||!in_array($receipt['state']??null,['prepared','issued'],true)||!is_int($receipt['version']??null)||$receipt['version']<1||!is_int($receipt['issued_at']??null)||$receipt['issued_at']<1||!is_string($receipt['alias']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$receipt['source']??'')||!is_array($receipt['codes']??null)||!array_is_list($receipt['codes'])||count($receipt['codes'])!==10)throw new DomainException('Ursprünglicher Codeübergabenachweis ist ungültig.');
        self::id($receipt['request_key']??null);self::hashes($receipt['codes']);return $receipt;
    }
    private static function hashes(#[\SensitiveParameter]array $codes):array
    {
        $hashes=[];foreach($codes as$code){if(!is_string($code)||!preg_match('/\A[a-f0-9]{5}(?:-[a-f0-9]{5}){3}\z/D',$code))throw new DomainException('Ursprünglicher Codeübergabenachweis ist ungültig.');$hashes[]=hash('sha256',str_replace('-','',$code));}
        if(count(array_unique($hashes))!==10)throw new DomainException('Ursprünglicher Codeübergabenachweis ist ungültig.');return $hashes;
    }
    private static function text(#[\SensitiveParameter]array $receipt):string
    {
        return "DVM · Persönliche Wiederherstellungscodes\n".$receipt['alias']."\n\n".implode("\n",$receipt['codes'])."\n\nJeder Code funktioniert einmal zusätzlich zum eigenen Passwort.\nFür die Anmeldung einen Code verwenden. Für einen anschließenden bewussten Authenticator-Wechsel einen weiteren Code aufbewahren.\n";
    }
    private static function existingOutput(string $file,#[\SensitiveParameter]string $expected):bool
    {
        if(!file_exists($file)&&!is_link($file))return false;
        UpgradeFiles::privateFile($file);
        if(filesize($file)!==strlen($expected)||!hash_equals(hash('sha256',$expected),(string)hash_file('sha256',$file)))throw new DomainException('Private Ausgabedatei wurde verändert. Ursprüngliche Codeübergabe prüfen.');
        return true;
    }
}
