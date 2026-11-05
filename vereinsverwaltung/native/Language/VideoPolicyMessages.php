<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
/** Fixed policy controls; original content and source names stay literal. */
final class VideoPolicyMessages
{
    public const ENGLISH=[
        'Videospeicher'=>'Video storage',
        'Videoaufbewahrung'=>'Video retention',
        'Speichergrenze (MiB)'=>'Storage limit (MiB)',
        'Mindestaufbewahrung (Tage)'=>'Minimum retention (days)',
        '0 = unbegrenzt'=>'0 = unlimited',
        'Gilt für künftige Entfernungen. Gespeicherte Fristen bleiben erhalten.'=>'Applies to future removals. Captured deadlines are retained.',
        'Speichergrenze speichern'=>'Save storage limit',
        'Aufbewahrung speichern'=>'Save retention rule',
        '{count} Quellen · {used} belegt'=>'{count} sources · {used} used',
        'Unbegrenzt'=>'Unlimited',
        '{free} frei · {total} gesamt'=>'{free} free · {total} total',
        'Gespeicherte Regel'=>'Saved rule',
        'Gespeicherte Regel prüfen'=>'Check saved rule',
        'Ursprüngliche Regel erneut speichern'=>'Retry original rule save',
        'Weitere Änderung vorbereiten'=>'Prepare another change',
        'Weitere Änderung vorbereitet. Deine Eingaben bleiben erhalten.'=>'Another change is prepared. Your inputs are retained.',
        'Regel gespeichert. Der Beitrag bleibt geöffnet.'=>'Rule saved. Your post stays open.',
        'Regel wird gespeichert …'=>'Saving rule …',
        'Speicherung der Regel nicht bestätigt. Deine Eingaben bleiben erhalten. Gespeicherte Regel prüfen.'=>'Rule save unconfirmed. Your inputs are retained. Check saved rule.',
        'Keine gespeicherte Regel für diesen Vorgang gefunden. Unverändert erneut speichern oder Eingaben bewusst korrigieren.'=>'No saved rule found for this request. Retry unchanged or deliberately correct your inputs.',
        'Ohne JavaScript öffnen Speicherung und Prüfung ein eigenes Fenster. Der ungespeicherte Beitrag bleibt im ursprünglichen Fenster.'=>'Without JavaScript, saving and checking open a separate window. The unsaved post stays in the original window.',
        'Deine ursprünglichen Eingaben bleiben erhalten.'=>'Your original inputs are retained.',
        'Gespeicherte Regel gefunden. Deine weiteren Eingaben bleiben erhalten. Für eine neue Speicherung weitere Änderung vorbereiten.'=>'Saved rule found. Your further inputs are retained. Prepare another change before saving again.',
        'Du kannst deine ursprüngliche Speicherung prüfen. Für Änderungen fehlt die Verwaltungsberechtigung.'=>'You can check your original save. You do not have permission to manage rules.',
        'Beitrag mit aktuellen Regeln öffnen'=>'Open post with current rules',
        'Regelversion'=>'Rule version',
        'Videospeicherregel erneut öffnen.'=>'Reopen the video storage rule.',
        'Ursprüngliches Videospeicherformular öffnen.'=>'Open the original video storage form.',
        'Videospeicherregel braucht einen eigenen Speichervorgang.'=>'Video storage rules require their own save operation.',
        'Videospeicherregel wird noch gespeichert. Gespeicherte Regel prüfen.'=>'The video storage rule is still being saved. Check saved rule.',
        'Gespeicherten Videospeichernachweis prüfen.'=>'Review the saved video storage receipt.',
        'Diese Kennung gehört zur ursprünglichen Videospeicherregel. Gespeicherte Regel prüfen.'=>'This identifier belongs to the original video storage rule. Check saved rule.',
        'Videospeichergrenze als ganze MiB zwischen 0 und 102400 angeben.'=>'Provide the video storage limit in whole MiB between 0 and 102400.',
        'Videoaufbewahrung zwischen 0 und 3650 Tagen angeben.'=>'Provide video retention between 0 and 3650 days.',
        'Videospeichergrenze inzwischen geändert. Aktuellen Stand öffnen.'=>'The video storage limit has changed. Open the current rule.',
        'Videoaufbewahrung inzwischen geändert.'=>'The video retention rule has changed.',
        'Videospeichergrenze benötigt das Kommunikationsupdate im Installer.'=>'Enable video storage limits through the installer.',
        'Videoaufbewahrung über den Installer aktivieren.'=>'Enable video retention through the installer.',
        'Videoaufbewahrung über den Installer vervollständigen.'=>'Complete video retention through the installer.',
        'Gespeicherte Videospeichergrenze prüfen.'=>'Review the saved video storage limit.',
        'Vereinszuordnung geändert.'=>'The club assignment changed.',
    ];
    public static function key(string $source):string{return 'video_policy_'.substr(hash('sha256',$source),0,24);}
    public static function messages(string $locale):array{$english=explode('-',$locale,2)[0]==='en';$out=[];foreach(self::ENGLISH as$source=>$translation)$out[self::key($source)]=$english?$translation:$source;return$out;}
    public static function text(string $source,array $messages,array $values=[]):string{$text=$messages[self::key($source)]??VideoStudioMessages::text($source,$messages);$tokens=[];foreach($values as$key=>$value)$tokens['{'.$key.'}']=(string)$value;return strtr($text,$tokens);}
    public static function browser(array $messages):array{$out=VideoStudioMessages::browser($messages);foreach(self::ENGLISH as$source=>$translation)$out[$source]=self::text($source,$messages);return$out;}
}
