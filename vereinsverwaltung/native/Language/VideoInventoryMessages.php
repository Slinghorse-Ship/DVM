<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
/** Read-only file observations; original identifiers and names remain literal. */
final class VideoInventoryMessages
{
    public const ENGLISH=[
        'Nicht zugeordnete Videodateien'=>'Unassigned video files',
        'Videospeicher erneut öffnen.'=>'Reopen video storage.',
        'Videodatei erneut auswählen.'=>'Select the video file again.',
        'Erneut prüfen'=>'Check again',
        'Speicher prüfen'=>'Check storage',
        'Einige Dateien konnten nicht vollständig geprüft werden.'=>'Some files could not be fully checked.',
        'Datei nicht als vollständiges Video bestätigt. Erneut prüfen.'=>'The file was not confirmed as a complete video. Check again.',
        'Keine nicht zugeordneten Videodateien gefunden.'=>'No unassigned video files found.',
        'Videodatei {id}'=>'Video file {id}',
        'Video bestätigt'=>'Video confirmed',
        'Datei prüfen'=>'Check file',
        'Unvollständige Datei oder mehr als 100 MiB.'=>'Incomplete file or larger than 100 MiB.',
        'Nicht zugeordnete Videoseiten'=>'Unassigned video file pages',
        'Erste Dateien'=>'First files',
        'Weitere Dateien'=>'More files',
        'Wiederherstellen'=>'Recover',
        'Die Dateiprüfung öffnet ein eigenes Fenster. Dein ungespeicherter Beitrag bleibt erhalten.'=>'File checks open a separate window. Your unsaved post is retained.',
        'Die Prüfung liest ältere Dateien ohne gespeicherte Videozuordnung. Dabei werden keine Dateien geändert.'=>'This check reads older files without a saved video assignment. No files are changed.',
        'Die ausgewählte Datei ist auf dieser Seite nicht vorhanden. Ursprüngliche Auswahl erneut prüfen.'=>'The selected file is not on this page. Check the original selection again.',
        'Ursprüngliche Dateiauswahl erneut prüfen'=>'Check original file selection again',
        'Videospeicherprüfung benötigt einen eigenen aktuellen Lesevorgang.'=>'Video storage checks require their own current read operation.',
        'Videospeicherprüfung benötigt einen privaten Speicher.'=>'Video storage checks require private storage.',
        'Unverändert zugeordneten Videospeicher prüfen.'=>'Check the originally assigned video storage.',
        'Videospeicher inzwischen geändert. Erneut prüfen.'=>'Video storage has changed. Check again.',
        'Nicht zugeordnete Videodatei nicht verfügbar.'=>'The unassigned video file is unavailable.',
        'Datei noch im Speichervorgang oder nicht sicher lesbar.'=>'The file is still being saved or cannot be read safely.',
        'Videodatei muss zwischen 32 Bytes und 100 MiB liegen.'=>'The video file must be between 32 bytes and 100 MiB.',
        'Datei oder Zuordnung inzwischen geändert. Erneut prüfen.'=>'The file or assignment has changed. Check again.',
        'Videodatei inzwischen geändert. Erneut prüfen.'=>'The video file has changed. Check again.',
        'Vereinszuordnung geändert. Videospeicher erneut öffnen.'=>'Club membership has changed. Reopen video storage.',
    ];
    public static function key(string $source):string{return 'video_inventory_'.substr(hash('sha256',$source),0,24);}
    public static function messages(string $locale):array{$english=explode('-',$locale,2)[0]==='en';$out=[];foreach(self::ENGLISH as$source=>$translation)$out[self::key($source)]=$english?$translation:$source;return$out;}
    public static function text(string $source,array $messages,array $values=[]):string{$text=$messages[self::key($source)]??VideoFileMessages::text($source,$messages);$tokens=[];foreach($values as$key=>$value)$tokens['{'.$key.'}']=(string)$value;return strtr($text,$tokens);}
}
