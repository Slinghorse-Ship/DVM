<?php declare(strict_types=1);
namespace Kulturhus\Native\System;

/** Only reviewed local, repeatable operations. No provider/business job is registered here. */
final class Catalog
{
    public const TASKS=['cache-cleanup'=>'Abgelaufenen Websitecache bereinigen','operations-observe'=>'Vorhandene Betriebsnachweise prüfen','auth-cleanup'=>'Abgelaufene eigene Anmeldedaten bereinigen'];
    public const INTERVALS=[5=>'Alle 5 Minuten',15=>'Alle 15 Minuten',30=>'Alle 30 Minuten',60=>'Stündlich',360=>'Alle 6 Stunden',1440=>'Täglich'];
    public const EVENTS=[
        'task_saved'=>['info','Zeitplan gespeichert'],
        'task_requested'=>['info','Lokale Aufgabe angefordert'],
        'task_completed'=>['info','Lokale Aufgabe abgeschlossen'],
        'task_failed'=>['warning','Lokale Aufgabe nicht bestätigt'],
        'task_abandoned'=>['warning','Unterbrochener lokaler Lauf erkannt'],
        'task_paused'=>['error','Zeitplan benötigt eine erneute Freigabe'],
        'worker_review'=>['warning','Vorhandenen Hintergrundlauf prüfen'],
        'schema_review'=>['warning','Aktuellen Modulstand prüfen'],
        'maintenance_review'=>['warning','Ursprüngliche Wartung prüfen'],
        'installation_observed'=>['info','Ursprünglichen Installationsstand geprüft'],
    ];
    public static function task(string $task):string{return self::TASKS[$task]??throw new \DomainException('Lokale Systemaufgabe auswählen.');}
    public static function context(string $code,array $context):array
    {
        if(!isset(self::EVENTS[$code])||array_diff(array_keys($context),['task','count','attempt','version','phase','module']))throw new \InvalidArgumentException('Nur freigegebene Systemereignisse und Betriebszählungen protokollieren.');
        foreach($context as$key=>$value){
            if($key==='task'){if(!is_string($value))throw new \InvalidArgumentException('Systemaufgabe prüfen.');self::task($value);}
            elseif(in_array($key,['count','attempt','version'],true)){if(!is_int($value)||$value<0||$value>2147483646)throw new \InvalidArgumentException('Begrenzte Betriebszählung erforderlich.');}
            elseif($key==='module'){if(!is_string($value)||!array_key_exists($value,\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS))throw new \InvalidArgumentException('Bekanntes Systemmodul erforderlich.');}
            elseif(!is_string($value)||!in_array($value,['active','maintenance','done','rolled-back','incomplete','source'],true))throw new \InvalidArgumentException('Bekannten Betriebszustand verwenden.');
        }
        return $context;
    }
}
