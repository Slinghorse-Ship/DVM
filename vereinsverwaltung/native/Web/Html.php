<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;
final class Html
{
    public static function e(mixed $text): string {return htmlspecialchars((string)$text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    public static function text(string $text): string {return nl2br(self::e($text));}
    public static function content(array $item): string
    {
        $html=!empty($item['body_html'])?\Kulturhus\Native\Shared\RichText::sanitize($item['body_html']):str_replace(["\r\n","\r","\n"],'<br>',self::e((string)($item['body']??'')));
        $seen=[];
        return preg_replace_callback('~<h([2-6])>(.*?)</h\1>~su',static function(array$m)use(&$seen):string{
            $text=html_entity_decode(strip_tags($m[2]),ENT_QUOTES|ENT_HTML5,'UTF-8');
            if(!preg_match('/\A\s*§\s*([1-9][0-9]{0,2})(?:\s|[.:]|\z)/u',$text,$number)||isset($seen[$number[1]]))return$m[0];
            $seen[$number[1]]=true;return '<h'.$m[1].' id="paragraf-'.$number[1].'">'.$m[2].'</h'.$m[1].'>';
        },$html);
    }
    public static function input(string $name, string $label, mixed $value='', string $type='text',string $extra=''): string
    {$class=preg_match('/\bclass\s*=/i',$extra)?'':' class="form-control"';return '<label>'.self::e($label).'<input'.$class.' name="'.self::e($name).'" type="'.self::e($type).'" value="'.self::e($value).'" '.$extra.'></label>';}
    public static function hidden(string $name,mixed $value): string {return '<input type="hidden" name="'.self::e($name).'" value="'.self::e($value).'">';}
    public static function select(string $name,string $label,array $choices,string $value):string
    {
        $s='<label>'.self::e($label).'<select class="form-control" aria-label="'.self::e($label).'" name="'.self::e($name).'">';
        foreach($choices as $id=>$title)$s.='<option value="'.self::e($id).'"'.((string)$id===$value?' selected':'').'>'.self::e($title).'</option>';
        return $s.'</select></label>';
    }
}
