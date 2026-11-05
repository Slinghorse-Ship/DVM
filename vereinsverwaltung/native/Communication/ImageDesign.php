<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

/** Deterministic local layouts. No remote generator or external font is used. */
final class ImageDesign
{
    public static function options(mixed $input): array
    {
        if (!is_array($input)) throw new \DomainException('Bildgestaltung prüfen.');
        $layout=$input['layout']??'editorial';$palette=$input['palette']??'forest';
        if (!in_array($layout,['editorial','poster','photo','classic'],true)||!in_array($palette,['forest','sunset','night'],true)) throw new \DomainException('Bildvorlage oder Farbwelt ungültig.');
        $result=['layout'=>$layout,'palette'=>$palette];
        foreach(['focal_x','focal_y'] as $key){$value=$input[$key]??50;if(filter_var($value,FILTER_VALIDATE_INT)===false||(int)$value<0||(int)$value>100)throw new \DomainException('Bildausschnitt zwischen 0 und 100 wählen.');$result[$key]=(int)$value;}
        return $result;
    }
    public static function crop(\GdImage $canvas,array $file,int $x,int $y,int $width,int $height,array $options):void
    {
        $source=imagecreatefromstring(file_get_contents($file['path']));if(!$source)throw new \DomainException('Foto nicht lesbar.');
        $sw=imagesx($source);$sh=imagesy($source);$scale=max($width/$sw,$height/$sh);$cw=min($sw,(int)round($width/$scale));$ch=min($sh,(int)round($height/$scale));
        $sx=(int)round(($sw-$cw)*$options['focal_x']/100);$sy=(int)round(($sh-$ch)*$options['focal_y']/100);
        imagecopyresampled($canvas,$source,$x,$y,$sx,$sy,$width,$height,$cw,$ch);unset($source);
    }
    private static function lines(string $text,int $size,string $font,int $width):array
    {
        $lines=[];$line='';
        foreach(preg_split('/\s+/u',trim($text),-1,PREG_SPLIT_NO_EMPTY) as $word){
            $box=imagettfbbox($size,0,$font,$word);if($box[2]-$box[0]>$width)throw new \DomainException('Ein Wort ist zu lang für das Bild. Titel oder Text kürzen.');
            $test=trim($line.' '.$word);$box=imagettfbbox($size,0,$font,$test);
            if($line!==''&&$box[2]-$box[0]>$width){$lines[]=$line;$line=$word;}else $line=$test;
        }
        if($line!=='')$lines[]=$line;return $lines;
    }
    private static function write(\GdImage $im,string $text,string $font,int $size,int $minSize,int $color,int $x,int &$y,int $width,int $maxHeight):void
    {
        do{$lines=self::lines($text,$size,$font,$width);$needed=count($lines)*(int)ceil($size*1.38);if($needed<=$maxHeight)break;$size-=2;}while($size>=$minSize);
        if($size<$minSize)throw new \DomainException('Text passt nicht lesbar ins Bild. Kürzen und neu erzeugen.');
        foreach($lines as $line){$y+=(int)ceil($size*1.38);imagettftext($im,$size,0,$x,$y,$color,$font,$line);}
    }
    public static function canvas(array $slide,int $height,?array $picture,?array $logo,array $identity,string $destination,array $options,array $attribution=[]):\GdImage
    {
        $options=self::options($options);if($options['layout']==='classic')return ClassicEventImage::canvas($slide,$height,$picture,$logo,$identity,$destination,$options,$attribution);$fonts=dirname(__DIR__,2).'/resources/fonts/liberation-serif/';$regular=$fonts.'LiberationSerif-Regular.ttf';$bold=$fonts.'LiberationSerif-Bold.ttf';
        if(!is_file($regular)||!is_file($bold))throw new \DomainException('Lokale Schrift fehlt.');
        $colors=['forest'=>[[247,246,240],[19,61,49],[212,232,105]],'sunset'=>[[255,246,239],[89,37,39],[255,179,118]],'night'=>[[238,241,255],[29,36,75],[188,191,255]]][$options['palette']];
        $im=imagecreatetruecolor(1080,$height);$bg=imagecolorallocate($im,...$colors[0]);$ink=imagecolorallocate($im,...$colors[1]);$accent=imagecolorallocate($im,...$colors[2]);imagefill($im,0,0,$bg);
        $qr=imagecreatefromstring(\Kulturhus\Native\Shared\Qr::png($destination));if(!$qr)throw new \DomainException('QR-Code nicht erzeugt.');$edge=imagesx($qr);if($edge>300)throw new \DomainException('QR-Adresse zu lang. Kürzere Zieladresse wählen.');
        $story=$height===1920;$top=$story?150:64;$bottom=($story?260:52)+($attribution['height']??0);$footer=$height-$bottom-max(230,$edge+20);$x=72;$width=936;$layout=$options['layout'];
        if($layout==='photo'){
            if(!$picture)throw new \DomainException('Für die Fotovorlage bitte ein eigenes Foto auswählen.');
            self::crop($im,$picture,0,0,1080,$height,$options);$textStart=$story?760:490;imagefilledrectangle($im,0,$textStart,1079,$height-1,$ink);$textColor=$bg;
        }elseif($layout==='editorial'&&$picture){$pictureHeight=min($story?650:460,max(120,$footer-$top-250));self::crop($im,$picture,48,$top,984,$pictureHeight,$options);$textStart=$top+$pictureHeight+40;$textColor=$ink;}
        else{
            // Geometric poster artwork is drawn locally, rather than a substitute photo.
            imagefilledellipse($im,900,$top+150,650,650,$accent);imagefilledrectangle($im,72,$top+40,252,$top+62,$ink);
            imagettftext($im,23,0,72,$top+115,$ink,$regular,$story?'STORY':'KULTUR · BEGEGNUNG');$textStart=$top+250;$textColor=$ink;
        }
        $y=$textStart;$available=$footer-$textStart-70;
        self::write($im,$slide['title'],$bold,64,38,$textColor,$x,$y,$width,(int)($available*.62));$y+=24;
        self::write($im,$slide['text'],$regular,32,24,$textColor,$x,$y,$width,max(0,$footer-$y-45));
        $qrSize=$edge;imagecopy($im,$qr,1008-$qrSize,$footer,0,0,$edge,$edge);unset($qr);
        $nameY=$footer+70;$textWidth=936-$qrSize-40;
        if($logo){$source=imagecreatefromstring(file_get_contents($logo['path']));if(!$source)throw new \DomainException('Logo nicht lesbar.');$factor=min(180/imagesx($source),85/imagesy($source));imagecopyresampled($im,$source,72,$footer,0,0,(int)(imagesx($source)*$factor),(int)(imagesy($source)*$factor),imagesx($source),imagesy($source));unset($source);$nameY+=90;}
        self::write($im,$identity['name'],$bold,24,20,$textColor,72,$nameY,$textWidth,65);
        $domain=(string)parse_url($identity['origin'],PHP_URL_HOST);self::write($im,$domain,$regular,20,18,$textColor,72,$nameY,$textWidth,55);
        if($attribution)ImageAttribution::draw($im,$attribution);
        return $im;
    }
}
