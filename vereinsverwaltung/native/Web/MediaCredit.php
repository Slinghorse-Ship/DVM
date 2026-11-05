<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;
/** Public credit and license from the particular published content original. */
final class MediaCredit
{
    public static function render(array $metadata, bool $links = true,?callable $interfaceText=null): string
    {
        $interfaceText??=static fn(string $source):string=>$source;
        $parts = [];
        if (($metadata['credit'] ?? '') !== '') $parts[] = Html::e($metadata['credit']);
        foreach (['source'=>'Quelle', 'license'=>'Lizenz'] as $key=>$fallback) {
            $name = $metadata[$key.'_name'] ?? ''; $url = $metadata[$key.'_url'] ?? '';
            if ($name === '' && $url === '') continue;
            $label=$name!==''?$name:$interfaceText($fallback);
            $parts[] = $url === '' || !$links ? Html::e($label) : '<a href="'.Html::e($url).'" rel="noopener noreferrer">'.Html::e($label).'</a>';
        }
        return $parts ? ' <span class="photo-credit">'.implode(' · ', $parts).'</span>' : '';
    }
}
