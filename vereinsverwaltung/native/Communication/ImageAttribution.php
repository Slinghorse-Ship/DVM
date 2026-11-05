<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;
use GdImage;
use PDO;
use Kulturhus\Native\Cms\MediaMetadata;
use Kulturhus\Native\Shared\{Access,Db};

/** Public source text is burned into new pixels; private consent proofs never enter this plan. */
final class ImageAttribution
{
    public const FONT_SIZE = 32;
    public const LINE_HEIGHT = 48;
    public const MAX_LINES = 8;
    public const WIDTH = 936;

    private static function font(): string
    {
        $font = dirname(__DIR__,2).'/resources/fonts/liberation-serif/LiberationSerif-Regular.ttf';
        if (!function_exists('imagettfbbox') || !is_file($font)) throw new DomainException('Lokale Bildschrift fehlt.');
        return $font;
    }

    public static function lines(array $metadata): array
    {
        $parts = \Kulturhus\Native\Shared\AttributionText::parts($metadata);
        if (!$parts) return [];
        $font = self::font();
        $fits = static function(string $text) use($font): bool {
            $box = imagettfbbox(self::FONT_SIZE,0,$font,$text);
            return $box !== false && max($box[0],$box[2],$box[4],$box[6])-min($box[0],$box[2],$box[4],$box[6]) <= self::WIDTH;
        };
        $lines = []; $line = '';
        foreach (preg_split('/\s+/u',implode(' · ',$parts),-1,PREG_SPLIT_NO_EMPTY) as $word) {
            $next = $line === '' ? $word : $line.' '.$word;
            if ($fits($next)) { $line = $next; continue; }
            if ($line !== '') { $lines[] = $line; $line = ''; }
            if ($fits($word)) $line = $word;
            else {
                preg_match_all('/\X/u',$word,$characters);
                foreach ($characters[0] as $character) {
                    if (!$fits($line.$character)) {
                        if ($line === '') throw new DomainException('Ein Quellenzeichen passt nicht lesbar ins Bild.');
                        $lines[] = $line; $line = '';
                    }
                    $line .= $character;
                }
            }
            if (count($lines) >= self::MAX_LINES) throw new DomainException('Bildquellen passen nicht lesbar ins Bild. Weniger Fotos verwenden.');
        }
        if ($line !== '') $lines[] = $line;
        if (count($lines)>self::MAX_LINES) throw new DomainException('Bildquellen passen nicht lesbar ins Bild. Weniger Fotos verwenden.');
        foreach($lines as $line){$box=imagettfbbox(self::FONT_SIZE,0,$font,$line);if(min($box[1],$box[3],$box[5],$box[7]) < -(self::FONT_SIZE+2) || max($box[1],$box[3],$box[5],$box[7]) >= self::LINE_HEIGHT-(self::FONT_SIZE+6))throw new DomainException('Bildquelle passt nicht vollständig in die Quellenzeile.');}
        return $lines;
    }

    public static function plan(array $usage,int $height,int $lineHeight=self::LINE_HEIGHT): array
    {
        if(!in_array($lineHeight,[44,48],true))throw new DomainException('Quellenformat prüfen.');
        if (!in_array($height,[1350,1920],true)) throw new DomainException('Social-Bildformat prüfen.');
        $lines = [];
        $media = $usage['media']; ksort($media,SORT_STRING);
        foreach ($media as $metadata) $lines = [...$lines,...self::lines($metadata)];
        if (count($lines)>self::MAX_LINES) throw new DomainException('Bildquellen passen nicht lesbar ins Bild. Weniger Fotos verwenden.');
        $band = $lines ? count($lines)*$lineHeight+32 : 0;
        return ['schema'=>1,'font_size'=>self::FONT_SIZE,'line_height'=>$lineHeight,'x'=>72,'width'=>self::WIDTH,'y'=>$height-($height===1920?260:52)-$band,'height'=>$band,'lines'=>$lines];
    }

    public static function matches(mixed $stored,array $plan): bool
    {
        if (!is_array($stored) || count($stored)!==count($plan)) return false;
        foreach ($plan as $key=>$value) if (!array_key_exists($key,$stored) || $stored[$key]!==$value) return false;
        return true;
    }

    public static function draw(GdImage $image,array $plan): void
    {
        if (!$plan['lines']) return;
        imagefilledrectangle($image,0,$plan['y'],imagesx($image)-1,$plan['y']+$plan['height']-1,imagecolorallocate($image,255,255,255));
        self::text($image,$plan['lines'],$plan['x'],$plan['y']+16,$plan['line_height']);
    }

    private static function text(GdImage $image,array $lines,int $x,int $y,int $lineHeight=self::LINE_HEIGHT): void
    {
        $ink = imagecolorallocate($image,24,39,34); $font = self::font();
        foreach ($lines as $i=>$line) if (imagettftext($image,self::FONT_SIZE,0,$x,$y+self::FONT_SIZE+4+$i*$lineHeight,$ink,$font,$line)===false) {
            throw new DomainException('Bildquellen konnten nicht erzeugt werden.');
        }
    }

    /** An authenticated version-bound raster gives the browser the exact server glyphs. */
    public static function preview(PDO $db,Access $access,string $id,int $version): string
    {
        Content::id($id);
        return Db::atomic($db,static function() use($db,$access,$id,$version): string {
            $access->require('social','read'); $actor = $access->require('website','read'); $club = (string)$actor['club_id'];
            $source = MediaMetadata::capture($db,$club,['media_ids'=>[$id]])[$id];
            if ($source['version']!==$version) throw new DomainException('Bildangaben geändert. Bildstudio neu öffnen.');
            $lines = self::lines($source);
            if (!$lines) throw new DomainException('Keine Bildquellenangabe.');
            $image = imagecreatetruecolor(self::WIDTH,count($lines)*self::LINE_HEIGHT);
            imagefill($image,0,0,imagecolorallocate($image,255,255,255)); self::text($image,$lines,0,0);
            ob_start(); try { if (!imagepng($image)) throw new DomainException('Bildquellen nicht verfügbar.'); $bytes = ob_get_contents(); } finally { ob_end_clean(); unset($image); }
            $access->require('social','read');
            if ((string)$access->require('website','read')['club_id']!==$club) throw new DomainException('Vereinszugehörigkeit geändert.');
            return $bytes;
        });
    }
}
