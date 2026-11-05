<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

/** Only presentation state: an explicit historical selection opens its own section. */
final class UiPanels
{
    public static function historyRequested(array $query):bool
    {
        foreach(array_keys($query)as$key)if(is_string($key)&&preg_match('/(?:history|revision|versions)(?:_|$)/',$key))return true;
        return false;
    }
}
