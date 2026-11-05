<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
/** Fixed picker and original-use labels; human source names stay literal. */
final class VideoReuseMessages
{
    public const ENGLISH=[
        'Vorhandene Videofassung nutzen'=>'Use an existing video version',
        'Vorhandenes Video übernehmen'=>'Use an existing video',
        'Videos auswählen'=>'Choose videos',
        'Videos suchen'=>'Search videos',
        'Suchen'=>'Search',
        '{count} Quellen · Seite {page} von {pages}'=>'{count} sources · page {page} of {pages}',
        'Mit Ton'=>'With audio',
        'Ohne Ton'=>'Without audio',
        'Früherer Vereinsstand'=>'Earlier club details',
        'Bereits übernommen'=>'Already used',
        'Diese Fassung nutzen'=>'Use this version',
        'Keine passenden eigenen Videos.'=>'No matching videos of your own.',
        'Vorhandene Videoquellen'=>'Existing video sources',
        'Vorherige Quellen'=>'Previous sources',
        'Weitere Quellen'=>'More sources',
        'Erste Quellen'=>'First sources',
        'Die Videoauswahl öffnet ein eigenes Fenster. Dein ungespeicherter Beitrag bleibt erhalten.'=>'The video picker opens a separate window. Your unsaved post is retained.',
        'Verwendet dieselbe Videodatei. Andere Beiträge behalten ihre eigene Freigabe.'=>'Uses the same video file. Other posts keep their own approval.',
        'Ohne JavaScript öffnen Übernahme und Prüfung ein eigenes Fenster. Der ungespeicherte Beitrag bleibt im ursprünglichen Fenster.'=>'Without JavaScript, use and review open a separate window. The unsaved post stays in its original window.',
        'Deine ursprüngliche Videoauswahl und Beitragsfassung bleiben erhalten.'=>'Your original video selection and post revision are retained.',
        'Ursprüngliche Videoübernahme'=>'Original video use',
        'Videofassung übernommen.'=>'Video version used.',
        'Vorhandene Videofassung übernommen.'=>'Existing video version used.',
        'Ursprüngliche Videoübernahme prüfen'=>'Check original video use',
        'Ursprüngliche Videoübernahme erneut versuchen'=>'Retry original video use',
        'Übernahme zum aktuellen Beitragsstand vorbereiten'=>'Prepare use with the current post revision',
        'Übernahme zum aktuellen Beitragsstand vorbereitet. Noch keine Fassung übernommen.'=>'Use prepared with the current post revision. No version has been used yet.',
        'Übernahme derzeit nicht bestätigt. Ursprüngliche Auswahl behalten.'=>'Use is currently unconfirmed. Retain the original selection.',
        'Videoübernahme nicht bestätigt. Ursprüngliche Auswahl und Beitragsfassung behalten.'=>'Video use is unconfirmed. Retain the original selection and post revision.',
        'Videoübernahme wird gespeichert …'=>'Saving video use …',
        'Ursprüngliche Videoübernahme wird geprüft …'=>'Checking original video use …',
        'Aktuelle Übernahme wird vorbereitet …'=>'Preparing current video use …',
        'Ursprüngliche Anfrage zuerst prüfen.'=>'Check the original request first.',
        'Für eine neue Übernahme werden ein bearbeitbarer Reel-Entwurf und aktuelle Bearbeitungsrechte benötigt.'=>'A new use requires an editable reel draft and current editing rights.',
        'Ursprüngliches Videoauswahlformular öffnen.'=>'Open the original video selection form.',
        'Videoauswahl erneut öffnen.'=>'Open the video picker again.',
        'Videoauswahlseite prüfen.'=>'Check the video picker page.',
        'Ursprüngliche Videoübernahme prüfen.'=>'Check the original video use.',
        'Ursprüngliche Videoübernahme auswählen.'=>'Select the original video use.',
        'Eigene ursprüngliche Videofassung auswählen.'=>'Choose your own original video version.',
        'Video in einen eigenen Reel-Entwurf übernehmen.'=>'Use the video in your own reel draft.',
        'Video enthält einen früheren Vereinsstand. Neue Fassung speichern.'=>'The video contains earlier club details. Save a new version.',
        'Videoübernahme benötigt einen eigenen aktuellen Vorgang.'=>'Video use requires its own current operation.',
        'Ergebnisprüfung benötigt einen eigenen aktuellen Lesevorgang.'=>'Result review requires its own current read operation.',
        'Videoauswahl benötigt einen eigenen aktuellen Lesevorgang.'=>'Video selection requires its own current read operation.',
        'Person oder Vereinszuordnung geändert.'=>'The person or club assignment has changed.',
        'Inhalt oder Vereinsdaten inzwischen geändert. Aktuellen Stand öffnen.'=>'The content or club details have changed. Open the current version.',
        'Aktuellen Inhaltsstand öffnen.'=>'Open the current content revision.',
        'Formular abgelaufen.'=>'Form expired.',
        'Auswahl erneut prüfen'=>'Review selection again',
        'Ursprüngliche Videoübernahme öffnen'=>'Open original video use',
        'Originalauswahl und gespeicherte Fassung getrennt prüfen.'=>'Review the original selection and stored version separately.',
    ];
    public static function key(string $source):string{return 'video_reuse_'.substr(hash('sha256',$source),0,24);}
    public static function messages(string $locale):array
    {$english=explode('-',$locale,2)[0]==='en';$out=[];foreach(self::ENGLISH as$source=>$translation)$out[self::key($source)]=$english?$translation:$source;return$out;}
    public static function text(string $source,array $messages,array $values=[]):string
    {$text=isset(self::ENGLISH[$source])?($messages[self::key($source)]??$source):VideoRecoveryMessages::text($source,$messages);$args=[];foreach($values as$key=>$value)$args['{'.$key.'}']=(string)$value;return strtr($text,$args);}
    public static function browser(array $messages):array
    {$out=[];foreach(array_keys(self::ENGLISH)as$source)$out[$source]=self::text($source,$messages);foreach(['Gespeicherte Beitragsfassung {version}','Videodatei im Papierkorb.','Videodatei endgültig gelöscht.','Dateivorgang noch offen.','Videofassung im Archiv.','Videodatei verfügbar.','Archiv öffnen','Gespeicherte Videos']as$source)$out[$source]=self::text($source,$messages);return$out;}
}
