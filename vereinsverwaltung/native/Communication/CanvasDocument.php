<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
/** Bounded editable layout. No URLs, executable markup, embedded uploads or arbitrary font files. */
final class CanvasDocument
{
    private static function number(mixed $v,float $min,float $max):float
    {if(!is_int($v)&&!is_float($v))throw new \DomainException('Ungültiger Gestaltungswert.');if(!is_finite((float)$v)||$v<$min||$v>$max)throw new \DomainException('Gestaltungswert außerhalb des zulässigen Bereichs.');return (float)$v;}
    private static function color(mixed $v):string
    {if(!is_string($v)||!preg_match('/^#[a-fA-F0-9]{6}$/D',$v))throw new \DomainException('Farbe als sechsstelligen Farbwert angeben.');return strtolower($v);}
    public static function validate(array $input,string $kind):array
    {
        $height=$kind==='story'?1920:1350;
        if(($input['schema']??null)!==1||($input['width']??null)!==1080||($input['height']??null)!==$height)throw new \DomainException('Gestaltung passt nicht zum gespeicherten Post-/Storyformat.');
        $layers=$input['layers']??null;if(!is_array($layers)||!array_is_list($layers)||count($layers)>30)throw new \DomainException('Höchstens 30 Gestaltungsebenen erlaubt.');
        $out=['schema'=>1,'width'=>1080,'height'=>$height,'background'=>self::color($input['background']??null),'layers'=>[]];$ids=[];$cloneCount=0;
        foreach($layers as $l){
            if(!is_array($l)||!in_array($l['type']??'', ['text','rect','ellipse','image','qr','line','arrow','path','frame','redact'],true)||!is_string($l['id']??null)||!preg_match('/^[a-z0-9-]{1,40}$/D',$l['id'])||isset($ids[$l['id']]))throw new \DomainException('Ungültige oder doppelte Gestaltungsebene.');$ids[$l['id']]=true;
            $r=['id'=>$l['id'],'type'=>$l['type'],'x'=>self::number($l['x']??null,-2160,2160),'y'=>self::number($l['y']??null,-3840,3840),'width'=>self::number($l['width']??null,20,4320),'height'=>self::number($l['height']??null,20,7680),'rotation'=>self::number($l['rotation']??0,-180,180),'opacity'=>self::number($l['opacity']??1,0,1)];
            if($r['type']!=='image'&&array_key_exists('clone_stamps',$l))throw new \DomainException('Klonstempel benötigen eine eigene Fotoebene.');
            if(in_array($r['type'],['text','rect','ellipse','line','arrow','path','frame','redact'],true))$r['color']=self::color($l['color']??null);
            if(in_array($r['type'],['line','arrow','path','frame'],true))$r['stroke_width']=self::number($l['stroke_width']??12,1,120);
            if($r['type']==='path'){
                $points=$l['points']??null;if(!is_array($points)||!array_is_list($points)||count($points)<2||count($points)>1000)throw new \DomainException('Zeichnung benötigt 2–1000 Punkte.');$r['points']=[];
                foreach($points as $point){if(!is_array($point)||count($point)!==2||!array_is_list($point))throw new \DomainException('Ungültiger Zeichenpunkt.');$r['points'][]=[self::number($point[0],-4320,4320),self::number($point[1],-7680,7680)];}
            }
            if($r['type']==='redact'){
                $r['effect']=in_array($l['effect']??'', ['cover','pixelate','blur'],true)?$l['effect']:throw new \DomainException('Ungültige Unkenntlichmachung.');
                if($r['rotation']!==0.0||$r['opacity']!==1.0)throw new \DomainException('Unkenntlichmachung benötigt eine ungedrehte, deckende Fläche.');
            }
            if($r['type']==='text'){
                $r['text']=Content::text($l['text']??'',2000);$r['font_size']=self::number($l['font_size']??null,12,240);$r['font']=in_array($l['font']??'', ['Arial','Georgia'],true)?$l['font']:throw new \DomainException('Unbekannte Schrift.');
            }
            if($r['type']==='image'){
                $r['media_ref']=Content::id($l['media_ref']??'');foreach(['focal_x','focal_y'] as $f)$r[$f]=self::number($l[$f]??50,0,100);$r['zoom']=self::number($l['zoom']??1,1,4);$r['flip']=($l['flip']??false)===true;
                foreach(['brightness','contrast','saturation'] as $f)$r[$f]=self::number($l[$f]??100,0,200);
                $r['filter']=in_array($l['filter']??'original',['original','mono','warm','cool','sepia','vivid','soft'],true)?($l['filter']??'original'):throw new \DomainException('Unbekannter Bildfilter.');
                $r['fit']=in_array($l['fit']??'cover',['cover','contain'],true)?($l['fit']??'cover'):throw new \DomainException('Unbekannter Bildzuschnitt.');
                $r['exposure']=self::number($l['exposure']??0,-2,2);$r['gamma']=self::number($l['gamma']??1,.3,3);$r['temperature']=self::number($l['temperature']??0,-100,100);$r['tint']=self::number($l['tint']??0,-100,100);$r['sharpness']=self::number($l['sharpness']??0,0,100);
                if(array_key_exists('clone_stamps',$l)){$stamps=PhotoClones::validate($l['clone_stamps']);$cloneCount+=count($stamps);if($cloneCount>PhotoClones::DOCUMENT_LIMIT)throw new \DomainException('Höchstens 256 Klonstempel in einer Bildfassung erlaubt.');if($stamps)$r['clone_stamps']=$stamps;}
            }
            if($r['type']==='qr'&&($r['width']!==$r['height']||$r['width']<150||$r['rotation']!==0.0||$r['opacity']!==1.0))throw new \DomainException('QR-Code benötigt ein ungedrehtes, deckendes Quadrat von mindestens 150 Pixeln.');
            $out['layers'][]=$r;
        }
        return $out;
    }
}
