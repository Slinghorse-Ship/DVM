<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;
use InvalidArgumentException;
use Kulturhus\Native\Shared\RichText;

/** Bounded website content, versioned with its page rather than separate mutable items. */
final class ContentSections
{
    public const LIMIT=20;
    public const LAYOUTS=['text'=>'Textbereich','image-before'=>'Bild vor dem Text','image-after'=>'Bild nach dem Text'];
    public const ASPECTS=['original'=>'Ganzes Bild','landscape'=>'Querformat 16:9','square'=>'Quadrat 1:1','portrait'=>'Hochformat 3:4'];
    public const RATIOS=['landscape'=>'16 / 9','square'=>'1 / 1','portrait'=>'3 / 4'];
    public static function normalize(mixed $input):array
    {
        if(!is_array($input)||!array_is_list($input)||count($input)>self::LIMIT)throw new InvalidArgumentException('Höchstens 20 Inhaltsabschnitte als Liste angeben.');
        $result=[];$seen=[];
        foreach($input as $row){
            if(!is_array($row)||array_diff(array_keys($row),['id','heading','body_html','media_id','alt','caption','credit','layout','button_label','button_url','aspect','focal_x','focal_y']))throw new InvalidArgumentException('Ungültiger Inhaltsabschnitt.');
            $id=$row['id']??null;$media=$row['media_id']??null;
            if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id)||isset($seen[$id])||($media!==null&&(!is_string($media)||!preg_match('/\A[a-f0-9]{32}\z/D',$media))))throw new InvalidArgumentException('Eindeutige Abschnittskennung und gültiges Bild angeben.');
            $seen[$id]=true;$layout=$row['layout']??'text';
            if(!is_string($layout)||!isset(self::LAYOUTS[$layout])||($layout!=='text'&&$media===null)||($layout==='text'&&$media!==null))throw new InvalidArgumentException('Bild und Abschnittsanordnung müssen zusammenpassen.');
            $value=['id'=>$id,'heading'=>self::text($row['heading']??'',200),'body_html'=>RichText::sanitize(self::text($row['body_html']??'',20000)),'media_id'=>$media,'alt'=>self::text($row['alt']??'',500),'caption'=>self::text($row['caption']??'',500),'credit'=>self::text($row['credit']??'',300),'layout'=>$layout,'button_label'=>self::text($row['button_label']??'',100),'button_url'=>self::url($row['button_url']??'')];
            $value += self::crop($row,$media!==null);
            if(($media!==null&&$value['alt']==='')||(($value['button_label']==='')!==($value['button_url']===''))||($value['heading']===''&&RichText::plain($value['body_html'])===''&&$media===null&&$value['button_label']===''))throw new InvalidArgumentException('Abschnitt mit Inhalt, Bildbeschreibung und vollständigem Schaltflächenlink angeben.');
            $result[]=$value;
        }
        return $result;
    }
    /** Absent crop fields preserve the exact shape of already approved originals. */
    private static function crop(array $row,bool $image):array
    {
        $keys=['aspect','focal_x','focal_y'];$present=array_intersect(array_keys($row),$keys);if(!$present)return [];
        if(count($present)!==3||!is_string($row['aspect'])||!isset(self::ASPECTS[$row['aspect']]))throw new InvalidArgumentException('Vollständigen Bildzuschnitt angeben.');
        $crop=['aspect'=>$row['aspect']];foreach(['focal_x','focal_y']as $key){
            if(!is_int($row[$key])&&!is_string($row[$key]))throw new InvalidArgumentException('Bildfokus als ganze Prozentzahl angeben.');
            $number=filter_var($row[$key],FILTER_VALIDATE_INT);if($number===false||$number<0||$number>100)throw new InvalidArgumentException('Bildfokus zwischen 0 und 100 Prozent angeben.');$crop[$key]=$number;
        }
        if($crop===['aspect'=>'original','focal_x'=>50,'focal_y'=>50])return [];
        if(!$image)throw new InvalidArgumentException('Bildfokus und Zuschnitt benötigen ein Abschnittsbild.');
        return $crop;
    }
    public static function text(mixed $value,int $max):string
    {
        if(!is_string($value)||!preg_match('//u',$value)||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value)||mb_strlen($value,'UTF-8')>$max)throw new InvalidArgumentException('Abschnittstext und erlaubte Länge prüfen.');
        return trim(str_replace(["\r\n","\r"],"\n",$value));
    }
    private static function url(mixed $value):string
    {
        $value=self::text($value,2000);if($value==='')return '';
        if(preg_match('/[\s\\\\]/u',$value)||preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i',$value))throw new InvalidArgumentException('Gültigen Abschnittslink ohne Steuerzeichen angeben.');
        if(str_starts_with($value,'/')&&!str_starts_with($value,'//'))return $value;
        if(preg_match('/\A#[a-zA-Z][a-zA-Z0-9_-]{0,100}\z/D',$value))return $value;
        try{return \Kulturhus\Native\Communication\Content::url($value);}catch(\DomainException $error){throw new InvalidArgumentException($error->getMessage(),0,$error);}
    }
    public static function mediaIds(array $payload):array
    {
        return array_values(array_unique(array_filter(array_column($payload['content_sections']??[],'media_id'))));
    }
    public static function plain(array $sections):string
    {
        $parts=[];foreach($sections as $row)$parts[]=implode(' ',[$row['heading'],RichText::plain($row['body_html']),$row['alt'],$row['caption'],$row['credit'],$row['button_label']]);return implode("\n",$parts);
    }
}
