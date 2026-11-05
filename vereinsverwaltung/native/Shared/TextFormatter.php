<?php declare(strict_types=1);
namespace Kulturhus\Native\Shared;

use InvalidArgumentException;

/** Format derived output only. The original human source is never rewritten. */
final class TextFormatter
{
    public static function render(string $source, string $format = 'plain'): string
    {
        if (strlen($source)>400000 || !mb_check_encoding($source,'UTF-8') || mb_strlen($source)>100000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$source)) {
            throw new InvalidArgumentException('Gültigen Text mit höchstens 100.000 Zeichen angeben.');
        }
        $source=trim(str_replace(["\r\n","\r"],"\n",$source));
        if (!in_array($format,['plain','markdown','richtext'],true)) throw new InvalidArgumentException('Textformat auswählen.');
        if ($source==='') return '';
        if ($format==='plain') return '<p>'.nl2br(htmlspecialchars($source,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),false).'</p>';
        if ($format==='richtext') return RichText::sanitize($source);
        if (substr_count($source,"\n")>2000) throw new InvalidArgumentException('Markdown hat zu viele Textzeilen.');
        $parser=(new \Kulturhus\Native\Comments\Markdown\Parsedown())->setSafeMode(true)->setMarkupEscaped(true)->setUrlsLinked(false)->setBreaksEnabled(true);
        return RichText::sanitize($parser->text($source));
    }

    public static function text(string $source, string $format = 'plain'): string
    {
        return RichText::plain(self::render($source,$format));
    }
}
