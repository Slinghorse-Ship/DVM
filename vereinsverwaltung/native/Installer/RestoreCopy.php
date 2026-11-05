<?php declare(strict_types=1);
namespace Kulturhus\Native\Installer;

/** Resume only the exact already-written prefix of the signed current archive member. */
final class RestoreCopy
{
    public static function copy($input,string $file,array $proof,int $mode,bool $ownedPrefix,?\Closure $checkpoint=null): void
    {
        \PackageStore::directory(dirname($file));
        if (is_link($file) || (file_exists($file)&&!is_file($file))) throw new \DomainException('Wiederherstellungsoriginal ist keine eigene Datei.');
        if (is_file($file)) {
            clearstatcache(true,$file); $size=filesize($file);
            if ($size===$proof['bytes'] && hash_equals($proof['sha256'],hash_file('sha256',$file))) return;
            if (!$ownedPrefix || $size>$proof['bytes'] || (fileperms($file)&0077)) throw new \DomainException('Geänderte Wiederherstellungsdatei bleibt unverändert.');
        } else $size=0;
        $hash=hash_init('sha256'); $old=umask(0077);
        try { $output=fopen($file,is_file($file)?'r+b':'x+b'); } finally { umask($old); }
        if (!$output) throw new \DomainException('Eigenes Wiederherstellungsziel nicht verfügbar.');
        try {
            for ($read=0;$read<$size;) {
                $source=fread($input,min(65536,$size-$read));
                if ($source===false || $source==='') throw new \DomainException('Ursprünglicher Archivanfang nicht vollständig lesbar.');
                $prefix=fread($output,strlen($source));
                if ($source!==$prefix) throw new \DomainException('Geschriebener Originalanfang passt nicht zum Archiv; bestehende Bytes bleiben unverändert.');
                hash_update($hash,$source);$read+=strlen($source);
            }
            $bytes=$size;
            while (!feof($input)) {
                $chunk=fread($input,65536);
                if ($chunk===false || ($bytes+strlen($chunk))>$proof['bytes']) throw new \DomainException('Archivdatei während der Fortsetzung verändert.');
                if ($chunk!=='' && fwrite($output,$chunk)!==strlen($chunk)) throw new \DomainException('Eigenes Original noch nicht vollständig geschrieben.');
                hash_update($hash,$chunk);$bytes+=strlen($chunk);
                if ($checkpoint && $chunk!=='') { fflush($output);$checkpoint('copy-chunk'); }
            }
            if ($bytes!==$proof['bytes'] || !hash_equals($proof['sha256'],hash_final($hash)) || !fflush($output) || (function_exists('fsync')&&!fsync($output)) || !chmod($file,$mode)) throw new \DomainException('Wiederherstellungsoriginal noch nicht vollständig verifiziert.');
        } finally { fclose($output); }
    }
}
