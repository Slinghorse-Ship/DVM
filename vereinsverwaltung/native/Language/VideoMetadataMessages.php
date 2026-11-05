<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
/** Source controls only; public descriptions and newly typed private proof stay literal. */
final class VideoMetadataMessages
{
    public const ENGLISH=[
        'Videoangaben'=>'Video details','Beschreibung'=>'Description','Credit'=>'Credit','Quelle'=>'Source','Lizenz'=>'Licence',
        'Quellen- und Lizenzlinks'=>'Source and licence links','Quellenlink'=>'Source link','Lizenzlink'=>'Licence link',
        'Rechte & Bereiche'=>'Rights and uses','Nutzungsrechte'=>'Usage rights','Einwilligung'=>'Consent','Nutzung bis'=>'Use until',
        'Erlaubte Bereiche'=>'Permitted uses','Website'=>'Website','Newsletter'=>'Newsletter','Social Media'=>'Social media','Presse'=>'Press',
        'Ungeprüft'=>'Unreviewed','Nutzung geklärt'=>'Usage cleared','Nutzung gesperrt'=>'Usage blocked',
        'Nicht erforderlich'=>'Not required','Liegt vor'=>'Recorded','Widerrufen'=>'Withdrawn',
        'Privater Nachweis'=>'Private proof','Privater Nachweis vorhanden'=>'Private proof recorded',
        'Nachweis ersetzen'=>'Replace proof','Neuer privater Nachweis'=>'New private proof',
        'Gespeicherte private Nachweise werden hier nicht angezeigt.'=>'Saved private proof is not displayed here.',
        'Angaben speichern'=>'Save details','Gespeicherte Angaben prüfen'=>'Check saved details',
        'Ursprüngliche Angaben erneut speichern'=>'Retry original details save',
        'Weitere Änderung vorbereiten'=>'Prepare another change',
        'Weitere Änderung vorbereitet. Deine Eingaben bleiben erhalten.'=>'Another change is prepared. Your inputs are retained.',
        'Videoangaben gespeichert. Der Beitrag bleibt geöffnet.'=>'Video details saved. Your post stays open.',
        'Videoangaben gespeichert. Der nicht ausgewählte Nachweistext bleibt als Entwurf erhalten.'=>'Video details saved. The unselected proof text remains a draft.',
        'Gespeicherte Videoangaben'=>'Saved video details',
        'Videoangaben werden gespeichert …'=>'Saving video details …',
        'Speicherung der Videoangaben nicht bestätigt. Deine Eingaben bleiben erhalten. Gespeicherte Angaben prüfen.'=>'Video details save unconfirmed. Your inputs are retained. Check saved details.',
        'Keine gespeicherten Angaben für diesen Vorgang gefunden. Unverändert erneut speichern oder Eingaben bewusst korrigieren.'=>'No saved details found for this request. Retry unchanged or deliberately correct your inputs.',
        'Ohne JavaScript öffnen Speicherung und Prüfung ein eigenes Fenster. Der ungespeicherte Beitrag bleibt im ursprünglichen Fenster.'=>'Without JavaScript, saving and checking open a separate window. The unsaved post stays in the original window.',
        'Deine ursprünglichen Eingaben bleiben erhalten.'=>'Your original inputs are retained.',
        'Beitrag mit aktuellen Videoangaben öffnen'=>'Open post with current video details',
        'Gespeicherter Nachweis gefunden. Deine weiteren Eingaben bleiben erhalten. Für eine neue Speicherung weitere Änderung vorbereiten.'=>'Saved receipt found. Your further inputs are retained. Prepare another change before saving again.',
        'Du kannst die ursprüngliche Speicherung prüfen. Für Änderungen fehlt die Bearbeitungsberechtigung.'=>'You can check the original save. You do not have permission to edit.',
        'Ungültige Auswahl: {value}'=>'Invalid choice: {value}',
        'Ursprüngliches Videoangabenformular öffnen.'=>'Open the original video details form.',
        'Videoangaben brauchen einen eigenen Speichervorgang.'=>'Video details require their own save operation.',
        'Videoangaben werden noch gespeichert. Gespeicherte Angaben prüfen.'=>'Video details are still being saved. Check saved details.',
        'Gespeicherten Videoangabennachweis prüfen.'=>'Review the saved video details receipt.',
        'Diese Kennung gehört zu den ursprünglichen Videoangaben. Gespeicherte Angaben prüfen.'=>'This identifier belongs to the original video details. Check saved details.',
        'Gespeicherte Videodatenversion angeben.'=>'Provide the saved video details version.',
        'Videoangaben inzwischen geändert. Formular neu laden.'=>'Video details have changed. Reopen the form.',
        'Videoangaben erneut öffnen.'=>'Reopen the video details.',
        'Eigene Videoquelle erneut öffnen.'=>'Reopen your own video source.',
        'Eigene ursprüngliche Videoquelle fehlt.'=>'Your original video source is missing.',
        'Erlaubte Bereiche als Auswahl angeben.'=>'Select the permitted uses.',
        'Privaten Nachweis als Text angeben.'=>'Provide private proof as text.',
        'Privaten Nachweiszugang prüfen.'=>'Review access to private proof.',
        'Videoangaben über den Installer aktivieren.'=>'Enable video details through the installer.',
        'Videoangaben als gültigen begrenzten Text angeben.'=>'Provide video details as valid text within the limit.',
        'Beschreibung für das Video angeben.'=>'Provide a description for the video.',
        'Quellen- und Lizenzlinks als HTTPS-Adresse ohne Zugangsdaten angeben.'=>'Provide source and licence links as HTTPS addresses without credentials.',
        'Videorechte und Einwilligung prüfen.'=>'Review video rights and consent.',
        'Gültiges Ablaufdatum angeben.'=>'Provide a valid expiry date.',
        'Videokanäle als Liste angeben.'=>'Provide video channels as a list.',
        'Videokanal prüfen.'=>'Review the video channel.',
        'Vereinszugehörigkeit geändert.'=>'The club assignment changed.',
    ];
    public static function key(string $source):string{return 'video_metadata_'.substr(hash('sha256',$source),0,24);}
    public static function messages(string $locale):array{$english=explode('-',$locale,2)[0]==='en';$out=[];foreach(self::ENGLISH as$source=>$translation)$out[self::key($source)]=$english?$translation:$source;return$out;}
    public static function text(string $source,array $messages,array $values=[]):string{$text=$messages[self::key($source)]??VideoStudioMessages::text($source,$messages);$tokens=[];foreach($values as$key=>$value)$tokens['{'.$key.'}']=(string)$value;return strtr($text,$tokens);}
    public static function browser(array $messages):array{$out=VideoStudioMessages::browser($messages);foreach(self::ENGLISH as$source=>$translation)$out[$source]=self::text($source,$messages);return$out;}
}
