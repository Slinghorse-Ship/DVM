<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;
use Kulturhus\Native\Cms\ContentSections;
use Kulturhus\Native\Web\Html;

/** Fixed geometry only; original photo bytes and historical publication remain untouched. */
final class ContentSectionCrop
{
    public static function form(string $name,array $row):string
    {
        return '<details class="content-section-crop"><summary>Bildfokus und Zuschnitt</summary><p>Ein zugeschnittenes Bild behält seine Originaldatei. Kleine Fokuswerte zeigen links bzw. oben, große rechts bzw. unten. Änderungen erscheinen in der Livevorschau.</p><div class="fields">'
            .Html::select($name.'[aspect]','Bildzuschnitt',ContentSections::ASPECTS,$row['aspect']??'original')
            .Html::input($name.'[focal_x]','Fokus horizontal (%)',$row['focal_x']??50,'number','required min="0" max="100" step="1"')
            .Html::input($name.'[focal_y]','Fokus vertikal (%)',$row['focal_y']??50,'number','required min="0" max="100" step="1"')
            .'</div><p class="hint">Bei „Ganzes Bild“ bleibt das vollständige Foto sichtbar. Der gespeicherte Fokus wird für den nächsten Zuschnitt erhalten.</p></details>';
    }
    public static function style(array $row):string
    {
        $ratio=ContentSections::RATIOS[$row['aspect']??'original']??null;if($ratio===null)return '';
        $x=$row['focal_x']??50;$y=$row['focal_y']??50;
        if(!is_int($x)||!is_int($y)||$x<0||$x>100||$y<0||$y>100)throw new \DomainException('Gespeicherten Bildfokus prüfen.');
        return ' style="aspect-ratio:'.$ratio.';object-fit:cover;object-position:'.$x.'% '.$y.'%"';
    }
}
