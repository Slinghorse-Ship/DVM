<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use DomainException;
use Kulturhus\Verein\{MailChannelSettings,InvoiceMailbox};

/** Whole private intention. Credentials are returned only to the server-side caller. */
final class MailInput
{
    public const SCOPES=['mail_channels','invoice_mailbox'];
    public static function scope(string $scope):void
    {if(!in_array($scope,self::SCOPES,true))throw new DomainException('Eigenen Postfachbereich auswählen.',422);}
    public static function initial(string $scope,array $state):array
    {
        self::scope($scope);$data=$state['data'];
        if($scope==='mail_channels')foreach($data['channels']as &$row){$row['override']=$row['override']?'1':'0';$row+=['password'=>'','clear_password'=>'0'];}else{$data['enabled']=$data['enabled']?'1':'0';$data['smtp_enabled']=$data['smtp_enabled']?'1':'0';$data+=['imap_password'=>'','smtp_password'=>'','clear_imap_password'=>'0','clear_smtp_password'=>'0'];}unset($row);
        return ['version'=>(string)$state['version'],'data'=>$data];
    }
    public static function secrets(string $scope):array
    {self::scope($scope);return $scope==='mail_channels'?['channels.board.password','channels.press.password','channels.newsletter.password']:['imap_password','smtp_password'];}
    public static function value(array $data,string $path):mixed
    {foreach(explode('.',$path)as $key){if(!is_array($data)||!array_key_exists($key,$data))return null;$data=$data[$key];}return $data;}
    public static function put(array &$data,string $path,mixed $value):void
    {$parts=explode('.',$path);$last=array_pop($parts);foreach($parts as $key)$data=&$data[$key];$data[$last]=$value;}
    private static function scalar(mixed $value):string|bool|int
    {
        if(!is_string($value)&&!is_bool($value)&&!is_int($value))throw new DomainException('Alle Postfachangaben vollständig übermitteln.',422);
        if(is_string($value)&&(strlen($value)>40000||preg_match('//u',$value)!==1))throw new DomainException('Postfachangaben als vollständigen UTF-8-Text übermitteln.',422);
        return $value;
    }
    private static function text(mixed $value):string
    {$value=self::scalar($value);if(!is_string($value))throw new DomainException('Alle Postfachangaben vollständig übermitteln.',422);return $value;}
    public static function capture(string $scope,#[\SensitiveParameter] array $input):array
    {
        self::scope($scope);$keys=array_keys($input);sort($keys);if($keys!==['data','version'])throw new DomainException('Alle Postfachangaben vollständig übermitteln.',422);$version=$input['version']??null;
        if(!is_string($version)&&!is_int($version))throw new DomainException('Ursprüngliche Postfachfassung übermitteln.',422);
        $version=(string)$version;if(strlen($version)>40)throw new DomainException('Ursprüngliche Postfachfassung übermitteln.',422);
        $posted=$input['data']??null;if(!is_array($posted))throw new DomainException('Alle Postfachangaben vollständig übermitteln.',422);
        $data=[];
        if($scope==='mail_channels'){
            $keys=array_keys($posted);sort($keys);if($keys!==['channels','routes']||!is_array($posted['channels'])||!is_array($posted['routes'])||array_diff_key($posted['channels'],MailChannelSettings::defaults()['channels'])||array_diff_key($posted['routes'],MailChannelSettings::PURPOSES))throw new DomainException('Alle Postfachangaben vollständig übermitteln.',422);
            foreach(MailChannelSettings::defaults()['channels']as $channel=>$defaults){
                $row=$posted['channels'][$channel]??null;if(!is_array($row)||array_diff_key($row,$defaults+['password'=>'','clear_password'=>'0']))throw new DomainException('Alle Postfachangaben vollständig übermitteln.',422);
                foreach($defaults+['password'=>'','clear_password'=>'0']as $field=>$default){$optional=in_array($field,['override','password','clear_password'],true);if(!$optional&&!array_key_exists($field,$row))throw new DomainException('Alle Postfachangaben vollständig übermitteln.',422);$value=array_key_exists($field,$row)?$row[$field]:($field==='password'?'':'0');$data['channels'][$channel][$field]=in_array($field,['override','clear_password'],true)?self::scalar($value):self::text($value);}
            }
            foreach(MailChannelSettings::PURPOSES as $purpose=>$label){if(!array_key_exists($purpose,$posted['routes']??[]))throw new DomainException('Für jeden Versandzweck ein Postfach auswählen.',422);$data['routes'][$purpose]=self::text($posted['routes'][$purpose]);}
        }else{
            if(array_diff_key($posted,InvoiceMailbox::defaults()+['imap_password'=>'','smtp_password'=>'','clear_imap_password'=>'0','clear_smtp_password'=>'0']))throw new DomainException('Alle Postfachangaben vollständig übermitteln.',422);
            foreach(InvoiceMailbox::defaults()+['imap_password'=>'','smtp_password'=>'','clear_imap_password'=>'0','clear_smtp_password'=>'0']as $field=>$default){$optional=is_bool($default)||str_contains($field,'password');if(!$optional&&!array_key_exists($field,$posted))throw new DomainException('Alle Postfachangaben vollständig übermitteln.',422);$value=array_key_exists($field,$posted)?$posted[$field]:(str_starts_with($field,'clear_')||is_bool($default)?'0':'');$data[$field]=str_starts_with($field,'clear_')||is_bool($default)?self::scalar($value):self::text($value);}
        }
        foreach(self::secrets($scope)as $path)if(!is_string(self::value($data,$path)))throw new DomainException('Postfachpasswort als unveränderten Text übermitteln.',422);
        return ['version'=>$version,'data'=>$data];
    }
    public static function redact(string $scope,#[\SensitiveParameter] array $input,#[\SensitiveParameter] string $key,string $context):array
    {
        $input=self::capture($scope,$input);$intents=[];
        foreach(self::secrets($scope)as $path){$password=self::value($input['data'],$path);self::put($input['data'],$path,'');$clearPath=str_contains($path,'.')?substr($path,0,strrpos($path,'.')+1).'clear_password':'clear_'.$path;$clear=self::value($input['data'],$clearPath);$intents[$path]=['replacement'=>$password!=='','fingerprint'=>$password===''?'':hash_hmac('sha256',$context."\0".$scope."\0".$path."\0".$password,hash_hkdf('sha256',$key,32,'dvm-mail-intention-v1')),'clear'=>$clear];}
        return $input+['credential_intents'=>$intents];
    }
    public static function version(string $value):int
    {if(!preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/D',$value))throw new DomainException('Ursprüngliche Postfachfassung übermitteln.',422);return (int)$value;}
}
