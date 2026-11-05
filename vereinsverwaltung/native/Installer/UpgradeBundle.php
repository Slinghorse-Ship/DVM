<?php declare(strict_types=1);
namespace Kulturhus\Native\Installer;

/** Separate upload: original app/private files are never overwritten by uploading a package. */
final class UpgradeBundle
{
    public static function build(string $source,string $target,bool $withOcr=false): array
    {
        if (PHP_SAPI!=='cli' || !str_starts_with($target,'/') || file_exists($target) || is_link($target)) throw new \DomainException('Neues absolutes Upgrade-Paketverzeichnis erforderlich.');
        require_once $source.'/distribution/src/PackageStore.php';
        \PackageStore::directory($target); chmod($target,0700);
        \PackageStore::directory($target.'/private');
        $payload=$target.'/private/upgrade-package';
        WebBundle::build($source,$payload,$withOcr);
        foreach (['web','SETUP-KEY.txt'] as $name) self::remove($payload.'/'.$name);
        foreach (['setup-token','setup-state.key'] as $name) unlink($payload.'/private/'.$name);
        $token=bin2hex(random_bytes(32));
        Deployment::file($payload.'/private/upgrade-token',hash('sha256',$token)."\n");
        Deployment::file($target.'/UPGRADE-KEY.txt',$token."\n");
        \PackageStore::directory($target.'/web/upgrade',0755);
        Deployment::file($target.'/web/upgrade/index.php',"<?php declare(strict_types=1);\nini_set('display_errors','0');\nini_set('zend.exception_ignore_args','1');\n\$root=dirname(__DIR__,2);\n\$package=\$root.'/private/upgrade-package';\nrequire \$package.'/app/vereinsverwaltung/native/bootstrap.php';\nrequire \$package.'/app/distribution/src/PackageStore.php';\n\\Kulturhus\\Native\\Installer\\WebUpgrade::serve(\$root,\$package);\n",0644);
        foreach (['upgrade.css','upgrade.js'] as $asset) Deployment::file($target.'/web/upgrade/'.$asset,(string)file_get_contents($source.'/distribution/web/'.$asset),0644);
        foreach (\Kulturhus\Native\Web\BootstrapAssets::sources() as $name=>$path) if (in_array($name,['bootstrap-6.0.0-alpha.1.min.css','bootstrap-dvm.css'],true)) Deployment::file($target.'/web/upgrade/'.$name,(string)file_get_contents($path),0644);
        return ['status'=>'upgrade-bundle','files'=>count(UpgradeFiles::proof($payload.'/private/app-files.json')['files']),'external_actions'=>0];
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname());
            }
            rmdir($path);
        } elseif (is_file($path)) unlink($path);
    }
}
