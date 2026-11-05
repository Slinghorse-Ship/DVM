<?php declare(strict_types=1);
namespace Kulturhus\Native\Installer;

/** Native runtime files shared by fresh installation and relocation after recovery. */
final class Deployment
{
    public const API_AUTH_REWRITE="RewriteCond %{HTTP:Authorization} .\nRewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n";
    public const DEMO_REWRITE="RewriteRule ^demo(?:/|$) - [L]\n";
    public const UPLOAD_INI="upload_max_filesize=256M\npost_max_size=520M\nmax_file_uploads=50\nmax_input_vars=8192\n";
    public static function write(string $target,#[\SensitiveParameter] array $environment): void
    {
        $webRoot=$environment['VEREIN_WEB_ROOT']??$target.'/web';
        foreach (['private','private/media','private/sessions'] as $dir) {
            \PackageStore::directory($target.'/'.$dir); chmod($target.'/'.$dir,$dir==='web'?0755:0700);
        }
        \PackageStore::directory($webRoot,0755); chmod($webRoot,0755);
        self::file($target.'/private/environment.json',json_encode($environment,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n",0600);
        self::file($target.'/private/runtime.php',"<?php\nforeach (".var_export($environment,true)." as \$name=>\$value) putenv(\$name.'='.\$value);\n",0600);
        self::file($target.'/private/worker.php',"<?php\nreturn ".var_export([
            'dsn'=>$environment['VEREIN_DATABASE'],'username'=>$environment['VEREIN_DATABASE_USER'],
            'password'=>$environment['VEREIN_DATABASE_PASSWORD'],'key_file'=>$target.'/private/private.key',
            'manager_key_file'=>$target.'/private/private.key',
            'auth_key_file'=>$target.'/private/auth.key',
            'environment_file'=>$target.'/private/environment.json',
            'club_id'=>$environment['VEREIN_CLUB'],
            'origin'=>$environment['VEREIN_PUBLIC_URL']??''
        ],true).";\n",0600);
        if(!file_exists($webRoot.'/.user.ini')&&!is_link($webRoot.'/.user.ini'))self::file($webRoot.'/.user.ini',self::UPLOAD_INI,0644);
        self::file($webRoot.'/index.php',self::entry($target),0644);
        self::file($webRoot.'/.htaccess',"Options -Indexes\nDirectoryIndex index.php\nRewriteEngine On\n".self::API_AUTH_REWRITE."RewriteRule ^(?:setup|upgrade)(?:/|$) - [END]\n".self::DEMO_REWRITE."RewriteRule ^ index.php [END]\n",0644);
    }

    /** The guard must execute before any active app bootstrap, including during a killed switch. */
    public static function entry(string $target): string
    {
        return "<?php declare(strict_types=1);\nini_set('display_errors','0');\nini_set('zend.exception_ignore_args','1');\n\$dvmPrivate=".var_export($target.'/private',true).";\n\$dvmUmask=umask(0077);\n\$dvmGate=@fopen(\$dvmPrivate.'/operations.lock','c+');\numask(\$dvmUmask);\nif (!\$dvmGate || !flock(\$dvmGate,LOCK_SH|LOCK_NB) || is_file(\$dvmPrivate.'/upgrade-hold') || is_file(\$dvmPrivate.'/restore-hold')) { http_response_code(503); header('Retry-After: 60'); header('Cache-Control: no-store'); echo 'Kurze Wartung. Bitte später erneut versuchen.'; exit; }\nregister_shutdown_function(static function() use (\$dvmGate): void { flock(\$dvmGate,LOCK_UN); fclose(\$dvmGate); });\nrequire ".var_export($target.'/private/runtime.php',true).";\nrequire ".var_export($target.'/app/vendor/autoload.php',true).";\nrequire ".var_export($target.'/app/vereinsverwaltung/native/bootstrap.php',true).";\nrequire ".var_export($target.'/app/vereinsverwaltung/src/Infrastructure/Database/Database.php',true).";\n\$db=\\Kulturhus\\Verein\\Database::connect(getenv('VEREIN_DATABASE'));\n\\Kulturhus\\Native\\Integration\\ProductionSite::serve(\$db);\n";
    }

    /** Keep the existing native/custom entry body; only add the independent maintenance guard. */
    public static function guardedEntry(string $target,string $original): string
    {
        if (!preg_match('/\A<\?php\s*(?:declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*)?/D',$original,$match)) throw new \DomainException('PHP-Einstieg vor der Aktualisierung prüfen.');
        $body=substr($original,strlen($match[0]));
        if (str_starts_with($body,"/* DVM_UPGRADE_GUARD */\n")) {
            $end=strpos($body,"/* DVM_UPGRADE_GUARD_END */\n");
            if ($end===false) throw new \DomainException('Vorhandene Wartungssperre unvollständig.');
            $body=substr($body,$end+strlen("/* DVM_UPGRADE_GUARD_END */\n"));
        }
        $template=self::entry($target);
        $end=strpos($template,'require '.var_export($target.'/private/runtime.php',true).';');
        $guard=substr($template,strlen("<?php declare(strict_types=1);\n"),$end-strlen("<?php declare(strict_types=1);\n"));
        return "<?php declare(strict_types=1);\n/* DVM_UPGRADE_GUARD */\n".$guard."/* DVM_UPGRADE_GUARD_END */\n".$body;
    }

    public static function upgradeRewrite(string $original): string
    {
        $original=self::demoRewrite($original);$rules=[];
        if (!str_contains($original,self::API_AUTH_REWRITE)) $rules[]=self::API_AUTH_REWRITE;
        $maintenance="RewriteRule ^(?:setup|upgrade)(?:/|$) - [END]\n";
        if (!str_contains($original,$maintenance)) $rules[]=$maintenance;
        if ($rules===[]) return $original;
        $insert=implode('',$rules);
        if (preg_match('/^RewriteEngine[ \t]+On[ \t]*$/mi',$original,$match,PREG_OFFSET_CAPTURE)) {
            $position=$match[0][1]+strlen($match[0][0]);
            return substr($original,0,$position)."\n".$insert.substr($original,$position);
        }
        return "RewriteEngine On\n".$insert.$original;
    }

    /** Let Apache reach the separate optional /demo child directory. */
    public static function demoRewrite(string $original):string
    {
        if(str_contains($original,self::DEMO_REWRITE))return $original;
        if(preg_match('/^RewriteEngine[ \t]+On[ \t]*$/mi',$original,$match,PREG_OFFSET_CAPTURE)){
            $position=$match[0][1]+strlen($match[0][0]);return substr($original,0,$position)."\n".self::DEMO_REWRITE.substr($original,$position);
        }
        return "RewriteEngine On\n".self::DEMO_REWRITE.$original;
    }

    public static function file(string $file,#[\SensitiveParameter] string $bytes,int $mode=0600): void
    {
        if (file_exists($file)) {
            if (is_link($file) || !is_file($file) || !hash_equals(hash('sha256',$bytes),hash_file('sha256',$file))) throw new \DomainException('Bestehende Datei bleibt unverändert: '.basename($file));
            return;
        }
        \PackageStore::writeNew($file,$bytes,$mode);
    }

    /** Large media and SQL files never need to fit in PHP's memory. */
    public static function copy($input,string $file,array $proof,int $mode): void
    {
        \PackageStore::directory(dirname($file));
        if (file_exists($file) || is_link($file)) throw new \DomainException('Wiederherstellungsdatei bereits vorhanden.');
        $old=umask(0077);
        try { $output=fopen($file,'xb'); } finally { umask($old); }
        if (!$output) throw new \DomainException('Wiederherstellungsdatei nicht verfügbar.');
        $complete=false; $bytes=0; $hash=hash_init('sha256');
        try {
            while (!feof($input)) {
                $chunk=fread($input,65536);
                if ($chunk===false) throw new \DomainException('Archivdatei nicht lesbar.');
                $bytes+=strlen($chunk);
                if ($bytes>$proof['bytes'] || fwrite($output,$chunk)!==strlen($chunk)) throw new \DomainException('Wiederherstellungsdatei unvollständig.');
                hash_update($hash,$chunk);
            }
            if ($bytes!==$proof['bytes'] || !hash_equals($proof['sha256'],hash_final($hash))) throw new \DomainException('Archiv wurde während der Wiederherstellung verändert.');
            if (!fflush($output) || !chmod($file,$mode)) throw new \DomainException('Wiederherstellungsdatei nicht abgeschlossen.');
            $complete=true;
        } finally { fclose($output); if (!$complete) unlink($file); }
    }
}
