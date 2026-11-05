<?php declare(strict_types=1);
namespace Kulturhus\Native\Installer;
use PDO;
/** Read-only preflight. Neither writes targets nor connects to remote services. */
final class Plan
{
    public static function inspect(string $source, string $destination, ?PDO $database=null): array
    {
        $source=realpath($source);
        $target=realpath($destination);
        if (!$source || !$target || !is_dir($source)||!is_dir($target)||is_link($destination)) throw new \DomainException('Existierendes lokales Quell- und Zielverzeichnis erforderlich.');
        $files=[];$conflicts=[];
        $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source,\FilesystemIterator::SKIP_DOTS));
        foreach($iterator as $file) {
            if($file->isLink()) throw new \DomainException('Symbolische Links sind im Paket nicht erlaubt.');
            if(!$file->isFile()) continue;
            $name=str_replace('\\','/',substr($file->getPathname(),strlen($source)+1));
            if(!preg_match('/\.(php|css|js)$/D',$name)) continue;
            $relative='native/'.$name;
            $files[]=['path'=>$relative,'sha256'=>hash_file('sha256',$file->getPathname()),'bytes'=>$file->getSize()];
            $parts=explode('/',$relative);$cursor=$target;
            foreach($parts as $index=>$part) {
                $cursor.='/'.$part;
                if(is_link($cursor)||is_file($cursor)||($index===count($parts)-1&&file_exists($cursor))) { $conflicts[]=$relative; break; }
            }
        }
        $tables=[];
        if($database) {
            if($database->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new \DomainException('MySQL erforderlich.');
            foreach($database->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) if(preg_match('/^(native_|khcms_|khn_)/',$table)) $tables[]=$table;
        }
        usort($files,static fn($a,$b)=>strcmp($a['path'],$b['path']));sort($tables);
        return ['mode'=>'read-only-preflight','can_stage'=>$conflicts===[]&&$tables===[],'files'=>$files,'file_conflicts'=>array_values(array_unique($conflicts)),'table_conflicts'=>$tables,
            'integrations'=>array_fill_keys(['FinTS-Herstellerkennung','Meta','ELSTER','SMTP','OCR'],'not-configured-disabled'),
            'limitations'=>['Kein Deployment oder Installer-Commit.','Keine Migration bestehender Daten.','Dateiprüfung erneut unmittelbar vor Installation erforderlich.','Produktive Identität, Datenbankrechte, Webserver und Geheimnisverwaltung müssen separat eingerichtet werden.']];
    }
}
