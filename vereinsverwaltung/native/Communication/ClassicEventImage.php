<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

/** Former event-card composition, with the entire source photo and frozen reminder text. */
final class ClassicEventImage
{
    private static function box(\GdImage $im,int $x,int $y,int $right,int $bottom,int $radius,int $color):void
    {
        imagefilledrectangle($im,$x+$radius,$y,$right-$radius,$bottom,$color);
        imagefilledrectangle($im,$x,$y+$radius,$right,$bottom-$radius,$color);
        foreach([[$x+$radius,$y+$radius],[$right-$radius,$y+$radius],[$x+$radius,$bottom-$radius],[$right-$radius,$bottom-$radius]]as[$cx,$cy])imagefilledellipse($im,$cx,$cy,$radius*2,$radius*2,$color);
    }
    private static function fit(\GdImage $im,string $text,string $font,int $size,int $minimum,int $x,int $top,int $width,int $height,int $color):void
    {
        do{
            $lines=[];$line='';$valid=true;
            foreach(preg_split('/\s+/u',trim($text),-1,PREG_SPLIT_NO_EMPTY)as$word){
                $b=imagettfbbox($size,0,$font,$word);if($b[2]-$b[0]>$width){$valid=false;break;}
                $candidate=trim($line.' '.$word);$b=imagettfbbox($size,0,$font,$candidate);
                if($line!==''&&$b[2]-$b[0]>$width){$lines[]=$line;$line=$word;}else $line=$candidate;
            }
            if($line!=='')$lines[]=$line;$leading=(int)ceil($size*1.32);
            if($valid&&count($lines)*$leading<=$height)break;$size-=2;
        }while($size>=$minimum);
        if($size<$minimum)throw new \DomainException('Text passt nicht lesbar ins Bild. Kürzen und neu erzeugen.');
        foreach($lines as$line){$top+=$leading;imagettftext($im,$size,0,$x,$top,$color,$font,$line);}
    }
    private static function contain(\GdImage $im,array $picture,int $x,int $y,int $width,int $height,int $background):void
    {
        $source=imagecreatefromstring(file_get_contents($picture['path']));if(!$source)throw new \DomainException('Foto nicht lesbar.');
        $scale=min($width/imagesx($source),$height/imagesy($source));$w=(int)round(imagesx($source)*$scale);$h=(int)round(imagesy($source)*$scale);
        imagefilledrectangle($im,$x,$y,$x+$width,$y+$height,$background);
        imagecopyresampled($im,$source,$x+intdiv($width-$w,2),$y+intdiv($height-$h,2),0,0,$w,$h,imagesx($source),imagesy($source));unset($source);
    }
    public static function details(string $text):array
    {
        $label='';
        if(preg_match('/\A(HEUTE|MORGEN|NOCH [0-9]{1,3} TAGE|TERMIN MERKEN|TODAY|TOMORROW|[0-9]{1,3} DAYS TO GO|SAVE THE DATE)(?:\s*·\s*|\R|\z)/u',$text,$m)){$label=$m[1];$text=substr($text,strlen($m[0]));}
        $date='';if(preg_match('/\A([0-9]{2}\.[0-9]{2}\.[0-9]{4}|[0-9]{4}-[0-9]{2}-[0-9]{2})\s+([0-9]{2}:[0-9]{2})(?: Uhr)?(?: (?:bis|to) (?:(?:[0-9]{2}\.[0-9]{2}\.[0-9]{4}|[0-9]{4}-[0-9]{2}-[0-9]{2}) )?[0-9]{2}:[0-9]{2}(?: Uhr)?)?/u',$text,$m)){$date=$m[1].'  |  '.$m[2].(str_contains($m[1],'.')?' UHR':'');$text=trim(substr($text,strlen($m[0])));}
        return ['label'=>$label,'date'=>$date,'place'=>$text];
    }
    public static function canvas(array $slide,int $height,?array $picture,?array $logo,array $identity,string $destination,array $options,array $attribution):\GdImage
    {
        if(!$picture)throw new \DomainException('Für die Vereinskarte bitte das Veranstaltungsfoto auswählen.');
        $fonts=dirname(__DIR__,2).'/resources/fonts/liberation-serif/';$regular=$fonts.'LiberationSerif-Regular.ttf';$bold=$fonts.'LiberationSerif-Bold.ttf';
        $im=imagecreatetruecolor(1080,$height);$dark=imagecolorallocate($im,19,38,29);$cream=imagecolorallocate($im,247,240,223);$yellow=imagecolorallocate($im,244,184,63);$teal=imagecolorallocate($im,143,194,188);$red=imagecolorallocate($im,217,88,62);$white=imagecolorallocate($im,255,255,255);
        $story=$height===1920;$detail=self::details($slide['text']);
        if($story){ImageDesign::crop($im,$picture,0,0,1080,$height,$options);for($i=0;$i<3;$i++)imagefilter($im,IMG_FILTER_GAUSSIAN_BLUR);imagefilledrectangle($im,0,0,1080,$height,imagecolorallocatealpha($im,16,34,25,38));}
        else{imagefill($im,0,0,$cream);imagefilledellipse($im,1018,76,250,250,$teal);imagefilledellipse($im,1018,76,128,128,$yellow);}
        $x=$story?64:72;$top=$story?104:54;$nameX=$x+98;
        if($logo){self::contain($im,$logo,$x,$top,126,86,$story?$cream:$dark);$nameX=$x+148;}
        else{self::box($im,$x,$top,$x+76,$top+76,17,$dark);self::box($im,$x+4,$top+4,$x+72,$top+70,14,$yellow);imagettftext($im,37,0,$x+22,$top+54,$dark,$bold,mb_strtoupper(mb_substr($identity['name'],0,1)));}
        self::fit($im,$identity['name'],$bold,28,20,$nameX,$top,820-($nameX-$x),74,$story?$cream:$dark);
        if($story){
            self::box($im,76,258,1028,898,28,$dark);self::contain($im,$picture,84,266,936,624,$dark);
            if($detail['label']!==''){$b=imagettfbbox(30,0,$regular,$detail['label']);$w=min(700,$b[2]-$b[0]+72);self::box($im,988-$w,294,988,378,24,$red);self::fit($im,$detail['label'],$regular,30,22,1024-$w,306,$w-72,55,$white);}
            self::box($im,76,948,1028,1810,36,imagecolorallocatealpha($im,10,23,17,50));self::box($im,64,932,1016,1794,36,$cream);imagefilledellipse($im,934,1004,150,150,$teal);
            self::box($im,108,982,858,1062,26,$yellow);self::fit($im,$detail['date']?:'TERMIN MERKEN',$regular,28,22,140,990,680,58,$dark);
            self::fit($im,$slide['title'],$regular,58,34,108,1090,824,365,$dark);
            self::fit($im,$detail['place']!==''?'ORT: '.$detail['place']:'',$regular,24,20,108,1470,824,105,$dark);
            self::box($im,108,1594,810,1690,30,$dark);imagettftext($im,27,0,144,1655,$white,$regular,'INFOS');imagefilledrectangle($im,108,1725,908,1730,$red);
            self::fit($im,mb_strtoupper((string)parse_url($identity['origin'],PHP_URL_HOST)),$regular,25,18,108,1733,800,52,$dark);
        }else{
            self::box($im,84,208,1020,778,24,$dark);self::contain($im,$picture,72,194,936,560,$dark);
            self::box($im,72,802,952,880,25,$yellow);self::fit($im,$detail['date']?:'TERMIN MERKEN',$regular,27,20,104,810,816,58,$dark);
            self::fit($im,$slide['title'],$regular,54,32,72,900,936,205,$dark);
            self::fit($im,$detail['place']!==''?'ORT: '.$detail['place']:'',$regular,23,19,72,1110,900,86,$dark);
            self::box($im,72,1212,1008,1292,25,$dark);self::fit($im,'INFOS AUF '.mb_strtoupper((string)parse_url($identity['origin'],PHP_URL_HOST)),$regular,23,18,104,1224,872,55,$white);imagefilledrectangle($im,72,1316,1008,1322,$red);
        }
        if(($attribution['height']??0)>0){$scale=$attribution['y']/$height;$w=(int)floor(1080*$scale);$h=(int)floor($height*$scale);$full=imagecreatetruecolor(1080,$height);imagefill($full,0,0,imagecolorallocate($full,247,240,223));imagecopyresampled($full,$im,intdiv(1080-$w,2),0,0,0,$w,$h,1080,$height);unset($im);$im=$full;ImageAttribution::draw($im,$attribution);}
        return $im;
    }
}
