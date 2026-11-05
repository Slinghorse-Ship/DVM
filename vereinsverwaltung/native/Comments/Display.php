<?php declare(strict_types=1);
namespace Kulturhus\Native\Comments;
use Kulturhus\Native\Shared\TextFormatter;

/** Derived display only: old valid plain sources remain readable if Markdown cannot render. */
final class Display
{
    public static function render(string $source,string $format='plain'):string
    {
        try{return TextFormatter::render($source,$format);}
        catch(\InvalidArgumentException $failure){
            if($format!=='markdown')throw$failure;
            // The same UTF-8, character and byte limits apply; this never rewrites stored text.
            return '<div data-comment-plain-fallback>'.TextFormatter::render($source,'plain').'</div>';
        }
    }
    public static function text(string $source,string $format='plain'):string
    {
        try{return TextFormatter::text($source,$format);}
        catch(\InvalidArgumentException $failure){
            if($format!=='markdown')throw$failure;
            TextFormatter::render($source,'plain');
            return trim(str_replace(["\r\n","\r"],"\n",$source));
        }
    }
}
