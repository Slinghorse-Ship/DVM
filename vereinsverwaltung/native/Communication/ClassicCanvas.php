<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
/** The original event card as real editable layers; stored human canvases always take precedence. */
final class ClassicCanvas
{
 public static function document(array $slide,string $kind,array $identity):?array
 {
  $photo=$slide['media_ref']??'';if($photo==='')return null;Content::id($photo);
  $story=$kind==='story';$h=$story?1920:1350;$detail=ClassicEventImage::details($slide['text']??'');$layers=[];
  $layer=static function(string $id,string $type,int $x,int $y,int $w,int $height,array $extra=[])use(&$layers):void{$layers[]=['id'=>$id,'type'=>$type,'x'=>$x,'y'=>$y,'width'=>$w,'height'=>$height,'rotation'=>0,'opacity'=>1]+$extra;};
  $rect=static function(string $id,int $x,int $y,int $w,int $height,string $color)use($layer):void{$layer($id,'rect',$x,$y,$w,$height,['color'=>$color]);};
  $text=static function(string $id,string $value,int $x,int $y,int $w,int $height,int $size,string $color,string $font='Georgia')use($layer):void{$layer($id,'text',$x,$y,$w,$height,['text'=>mb_strcut($value,0,2000,'UTF-8'),'font'=>$font,'font_size'=>$size,'color'=>$color]);};
  if($story){$layer('event-backdrop','image',0,0,1080,$h,['media_ref'=>$photo,'fit'=>'cover','brightness'=>45,'saturation'=>75]);}
  $text('club-name',$identity['name'],140,$story?108:55,850,85,36,$story?'#f7f0df':'#13261d');
  $rect('club-mark',64,$story?104:54,62,72,'#f4b83f');$text('club-initial',mb_substr($identity['name'],0,1),78,$story?112:62,45,60,44,'#13261d');
  $photoY=$story?266:194;$photoH=$story?624:560;
  $rect('photo-frame',64,$photoY-12,952,$photoH+24,'#13261d');
  $layer('event-photo','image',76,$photoY,928,$photoH,['media_ref'=>$photo,'fit'=>'contain']);
  if($detail['label']!==''){$rect('reminder-background',590,$photoY+28,380,78,'#d9583e');$text('reminder-label',$detail['label'],610,$photoY+48,340,55,32,'#ffffff','Arial');}
  $cardY=$story?932:788;$rect('event-card',64,$cardY,952,$story?862:550,'#f7f0df');
  $rect('date-background',108,$cardY+50,820,80,'#f4b83f');$text('event-date',$detail['date']?:'TERMIN MERKEN',132,$cardY+72,770,62,34,'#13261d','Arial');
  $text('event-title',$slide['title'],108,$cardY+162,824,$story?350:220,$story?68:60,'#13261d');
  $text('event-location',$detail['place']!==''?'ORT: '.$detail['place']:'',108,$story?1460:1115,824,100,30,'#13261d','Arial');
  $rect('info-background',108,$story?1594:1220,820,86,'#13261d');$text('info-label','INFOS AUF '.mb_strtoupper((string)parse_url($identity['origin'],PHP_URL_HOST)),132,$story?1620:1246,770,58,30,'#ffffff','Arial');
  return CanvasDocument::validate(['schema'=>1,'width'=>1080,'height'=>$h,'background'=>'#f7f0df','layers'=>$layers],$kind);
 }
}
