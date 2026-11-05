<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;

/** Private hosting information, without paths, keys, execution or false service success. */
final class PhotoRequirements
{
    public static function render(array $photo,?callable $text=null):string
    {
        $text??=static fn(string $source):string=>$source;
        $labels=['processes'=>'PHP-Prozessfunktionen','node'=>'Node.js auf dem Hosting','runners'=>'Serverprogramme für Fotos','codecs'=>'Lokale Fotocodecs','temporary'=>'Beschreibbarer Zwischenspeicher'];
        $html='<details class="status-preferences text-break" data-photo-prerequisites><summary class="py-3">'.Html::e($text('Serverfotos · Voraussetzungen')).'</summary><p>'.Html::e($text(!empty($photo['present'])?'Voraussetzungen vorhanden · Funktion nicht geprüft.':'Voraussetzungen fehlen · Funktion nicht geprüft.')).'</p><ul>';
        foreach($labels as$key=>$label)$html.='<li>'.(!empty($photo[$key])?'✓ ':'✗ ').Html::e($text($label)).'</li>';
        return $html.'</ul><p>'.Html::e($text('Node.js und Prozessfreigabe werden vom Hosting bereitgestellt. Kleine Fotos und die Browservorbereitung bleiben unabhängig davon nutzbar.')).'</p></details>';
    }
}
