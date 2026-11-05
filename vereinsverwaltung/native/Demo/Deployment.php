<?php declare(strict_types=1);
namespace Kulturhus\Native\Demo;

/** CLI provisioning: private configuration, then a fixed public /demo front controller. */
final class Deployment
{
    /** An optional mounted entry advertises the demo; no private config is read. */
    public static function available(string $webroot):bool
    {
        $file=rtrim($webroot,'/').'/demo/index.php';
        return $webroot!==''&&is_dir($webroot)&&!is_link(dirname($file))&&!is_link($file)&&is_file($file)
            &&str_contains((string)file_get_contents($file,false,null,0,128),'/* DVM_PUBLIC_DEMO */');
    }
    public static function configure(string $path,#[\SensitiveParameter] array $input):array
    {
        if(PHP_SAPI!=='cli')throw new \DomainException('Private CLI-Konfiguration erforderlich.');
        Configuration::absolute($path);if(basename($path)!=='demo.json')throw new \DomainException('Private Konfiguration als demo.json speichern.');
        $root=dirname($path);
        $existing=is_file($path);
        if($existing){
            $configuration=Configuration::read($path);
            if(($input['origin']??null)!==$configuration['origin']||($input['database']??null)!==$configuration['database'])throw new \DomainException('Bestehende Demo-Konfiguration bleibt unverändert.');
            Configuration::connect($configuration);
        }else{
            if(is_dir($root)&&array_diff(scandir($root),['.','..']))throw new \DomainException('Eigenen neuen leeren Demoordner wählen.');
            if(!is_dir($root)){if(!is_dir(dirname($root))||!mkdir($root,0700))throw new \DomainException('Privater Demoordner konnte nicht angelegt werden.');}
            $configuration=Configuration::validate(['format'=>1,'instance'=>bin2hex(random_bytes(12)),'origin'=>$input['origin']??null,'private_root'=>$root,'database'=>$input['database']??null]);
            $password=$input['operator_password']??null;if(!is_string($password)||strlen($password)<16||strlen($password)>72)throw new \DomainException('Privates Betreiberpasswort mit 16 bis 72 Bytes erforderlich.');
            $db=Configuration::connect($configuration);
            if((int)$db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==0)throw new \DomainException('Neue Demo benötigt eine eigene leere Datenbank.');
            // Persist identity before any other files, so interrupted configuration resumes.
            Configuration::create($path,json_encode($configuration,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
        }
        foreach(['sessions','operator-sessions','media','banking','member-bank-keys'] as $directory){
            $target=$root.'/'.$directory;
            if(!is_dir($target)&&!mkdir($target,0700))throw new \RuntimeException('Privater Demospeicher nicht vollständig.');
            if(is_link($target)||(fileperms($target)&0077)!==0)throw new \DomainException('Privater Demospeicher nicht sicher.');
        }
        if(!is_file($root.'/operator.json')){
            $password=$input['operator_password']??null;if(!is_string($password))throw new \DomainException('Betreiberpasswort zur begonnenen Konfiguration erneut privat bereitstellen.');
            (new Operator($configuration))->initialize($password);
        }else (new Operator($configuration))->status();
        return ['configured'=>true,'existing'=>$existing];
    }

    public static function mount(string $path,string $webroot):array
    {
        if(PHP_SAPI!=='cli')throw new \DomainException('Private CLI-Einbindung erforderlich.');
        $configuration=Configuration::read($path);Configuration::absolute($webroot);
        if(!is_dir($webroot)||realpath($webroot)!==$webroot||str_starts_with($configuration['private_root'].'/',$webroot.'/')||str_starts_with($webroot.'/',$configuration['private_root'].'/'))throw new \DomainException('Getrennten bestehenden öffentlichen Webroot wählen.');
        $directory=$webroot.'/demo';$receipt=$configuration['private_root'].'/mount.json';
        if(is_file($receipt)){
            Configuration::privateFile($receipt);$record=json_decode((string)file_get_contents($receipt),true,16,JSON_THROW_ON_ERROR);
            if($record!==['instance'=>$configuration['instance'],'webroot'=>$webroot])throw new \DomainException('Vorhandene Demo-Einbindung gehört zu einem anderen Webroot.');
        }else{
            if(file_exists($directory)||is_link($directory))throw new \DomainException('Vorhandener öffentlicher Demoordner bleibt unverändert.');
            Configuration::create($receipt,json_encode(['instance'=>$configuration['instance'],'webroot'=>$webroot],JSON_THROW_ON_ERROR));
        }
        if(!is_dir($directory)&&!mkdir($directory,0755))throw new \RuntimeException('Öffentlicher Demoordner nicht verfügbar.');
        if(is_link($directory))throw new \DomainException('Kein symbolischer öffentlicher Demoordner.');
        chmod($directory,0755);
        if(array_diff(scandir($directory),['.','..','index.php','.htaccess']))throw new \DomainException('Unbekannte Dateien im Demoordner bleiben erhalten.');
        $source=dirname(__DIR__,3);
        $entry="<?php declare(strict_types=1);\n/* DVM_PUBLIC_DEMO */\nini_set('display_errors','0');\nini_set('zend.exception_ignore_args','1');\nrequire ".var_export($source.'/vendor/autoload.php',true).";\nrequire ".var_export($source.'/vereinsverwaltung/native/bootstrap.php',true).";\nrequire ".var_export($source.'/vereinsverwaltung/src/bootstrap.php',true).";\ntry { \\Kulturhus\\Native\\Demo\\Runtime::serve(".var_export($path,true)."); } catch (\\Throwable) { if(!headers_sent()) { if(http_response_code()<400)http_response_code(503); header('Cache-Control: no-store'); } echo 'Demo derzeit nicht verfügbar. / Demo currently unavailable.'; }\n";
        self::publicFile($directory.'/index.php',$entry);
        self::publicFile($directory.'/.htaccess',"Options -Indexes\nDirectoryIndex index.php\nRewriteEngine On\nRewriteRule ^ index.php [END]\n");
        $rewrite=$webroot.'/.htaccess';$original=is_file($rewrite)?(string)file_get_contents($rewrite):"RewriteEngine On\n";
        if(is_link($rewrite))throw new \DomainException('Keine symbolische Webroot-Konfiguration.');
        $next=\Kulturhus\Native\Installer\Deployment::demoRewrite($original);
        if($next!==$original){
            $temporary=$webroot.'/.dvm-demo-'.bin2hex(random_bytes(12));Configuration::create($temporary,$next);
            try{chmod($temporary,0644);if(!rename($temporary,$rewrite))throw new \RuntimeException('Demo-Rewrite nicht bestätigt.');}finally{if(is_file($temporary))unlink($temporary);}
        }
        return ['mounted'=>true,'path'=>'/demo'];
    }

    private static function publicFile(string $path,string $bytes):void
    {
        if(file_exists($path)||is_link($path)){
            if(is_link($path)||!is_file($path)||!hash_equals(hash('sha256',$bytes),hash_file('sha256',$path)))throw new \DomainException('Bestehende öffentliche Demodatei bleibt unverändert.');
        }else Configuration::create($path,$bytes);
        chmod($path,0644);
    }
}
