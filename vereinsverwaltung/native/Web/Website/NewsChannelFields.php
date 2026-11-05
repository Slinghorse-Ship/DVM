<?php
declare(strict_types=1);

namespace Kulturhus\Native\Web\Website;

use InvalidArgumentException;
use Kulturhus\Native\Cms\NewsChannels;
use Kulturhus\Native\Web\Html;

/** Shared controls for the article editor and generic news templates. */
final class NewsChannelFields
{
    public static function render(array $page): string
    {
        $selected = NewsChannels::normalize(
            $page["news_channels"] ?? NewsChannels::ALL,
        );
        $html =
            '<fieldset class="news-channel-fields"><legend>Veröffentlichungskanäle</legend>' .
            Html::hidden("news_channels_present", "1");
        foreach (
            ["website" => "Website", "rss" => "RSS-Feed"]
            as $channel => $label
        ) {
            $html .=
                '<label><input class="check" type="checkbox" name="news_channels[]" value="' .
                $channel .
                '"' .
                (in_array($channel, $selected, true) ? " checked" : "") .
                "> " .
                $label .
                "</label>";
        }
        return $html .
            '<p class="help">Die Auswahl wird mit dem Entwurf gespeichert und erst mit der nächsten Veröffentlichung wirksam. Ohne Website ist der Beitrag nur im ausgewählten RSS-Feed sichtbar. Ohne Kanal erscheint er in keiner öffentlichen Ausgabe.</p></fieldset>';
    }

    /** Missing marker preserves an earlier client’s stored selection; an unchecked group is []. */
    public static function fromPost(array $post): ?array
    {
        if (!array_key_exists("news_channels_present", $post)) {
            return array_key_exists("news_channels", $post)
                ? NewsChannels::normalize($post["news_channels"])
                : null;
        }
        if ($post["news_channels_present"] !== "1") {
            throw new InvalidArgumentException(
                "Ungültige Beitragskanalauswahl.",
            );
        }
        return NewsChannels::normalize($post["news_channels"] ?? []);
    }
}
