<?php declare(strict_types=1);
namespace Kulturhus\Native\Installer;

/** Exact on-disk inventories and atomic private journals, independent of the active app. */
final class UpgradeFiles
{
    public static function inventory(string $app, string $source): array
    {
        $files=[];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($app,\FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink() || !$file->isFile()) throw new \DomainException('Paket enthält einen Link oder eine Spezialdatei.');
            $name=\PackageStore::relative(substr($file->getPathname(),strlen($app)+1));
            $files[$name]=['path'=>$name,'bytes'=>$file->getSize(),'sha256'=>hash_file('sha256',$file->getPathname()),'normalized_lf'=>false,'mode'=>$file->getPerms()&0777];
        }
        ksort($files);
        return ['format'=>1,'source'=>$source,'files'=>array_values($files)];
    }

    public static function proof(string $file): array
    {
        self::privateFile($file);
        $proof=\PackageStore::json($file);
        if (($proof['format']??0)!==1 || !preg_match('/\A[a-f0-9]{64}\z/D',$proof['source']??'') || !is_array($proof['files']??null) || !$proof['files'] || count($proof['files'])>100000) throw new \DomainException('Paketinventar unvollständig.');
        $seen=[];
        foreach ($proof['files'] as $entry) {
            $path=\PackageStore::relative($entry['path']??'');
            if (isset($seen[$path]) || !is_int($entry['bytes']??null) || $entry['bytes']<0 || !preg_match('/\A[a-f0-9]{64}\z/D',$entry['sha256']??'')) throw new \DomainException('Paketinventar enthält ungültige Originale.');
            $seen[$path]=true;
        }
        return $proof;
    }

    public static function privateFile(string $file): void
    {
        \PackageStore::relative(basename($file));
        clearstatcache(true,$file);
        if (is_link($file) || !is_file($file) || (fileperms($file)&0077)) throw new \DomainException('Private Originaldatei fehlt oder ist öffentlich lesbar.');
        // The existing parent is read-only here; PackageStore respects configured open_basedir roots.
        \PackageStore::directory(dirname($file));
    }

    public static function atomic(string $file, #[\SensitiveParameter] string $bytes, int $mode=0600): void
    {
        \PackageStore::directory(dirname($file));
        if (is_link($file) || (file_exists($file) && !is_file($file))) throw new \DomainException('Upgradeziel ist kein eigenes Original.');
        $temporary=dirname($file).'/.upgrade-write-'.bin2hex(random_bytes(12));
        $old=umask(0077);
        try { $handle=fopen($temporary,'xb'); } finally { umask($old); }
        if (!$handle) throw new \DomainException('Upgrade konnte nicht gespeichert werden.');
        try {
            $remaining=$bytes;
            while ($remaining!=='') {
                $written=fwrite($handle,$remaining);
                if (!$written) throw new \DomainException('Upgrade unvollständig gespeichert.');
                $remaining=substr($remaining,$written);
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle)) || !chmod($temporary,$mode)) throw new \DomainException('Upgrade unvollständig gespeichert.');
            fclose($handle); $handle=null;
            if (!rename($temporary,$file)) throw new \DomainException('Upgrade konnte nicht übernommen werden.');
        } finally { if (is_resource($handle)) fclose($handle); if (is_file($temporary)) unlink($temporary); }
    }

    public static function copy(string $from,string $to,array $entry,?string $temporary=null): void
    {
        \PackageStore::check($from,$entry);
        \PackageStore::directory(dirname($to));
        if ($temporary!==null && file_exists($temporary)) { if (is_link($temporary)||!is_file($temporary)) throw new \DomainException('Private temporäre Paketdatei prüfen.'); unlink($temporary); }
        if (file_exists($to)) { \PackageStore::check($to,$entry); return; }
        $tmp=$temporary??dirname($to).'/.upgrade-copy-'.bin2hex(random_bytes(12));
        $input=fopen($from,'rb');
        try {
            Deployment::copy($input,$tmp,$entry,(($entry['mode']??0644)&0111)?0755:0644);
            if (!rename($tmp,$to)) throw new \DomainException('Paketdatei nicht übernommen.');
        } finally { if (is_resource($input)) fclose($input); if (is_file($tmp)) unlink($tmp); }
    }
}
