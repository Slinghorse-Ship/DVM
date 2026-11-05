<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;
use Kulturhus\Native\Cms\{Pages, Media, HomeLayout};
use Kulturhus\Native\Web\{Html, Articles, PublishedLanguage};

/** Public home reads only immutable published website and event originals. */
final class HomePage
{
    public function __construct(
        private Pages $pages,
        private Media $media,
        private ?Articles $articles,
        private array $identity,
        private ?PublishedLanguage $language=null,
    ) {}
    public function render(array $page, string $body): string
    {
        $v = $page["home_layout"] ?? HomeLayout::defaults();
        $links = array_column($this->pages->navigation(), null, "slug");
        $actions = in_array("events", $v["sections"], true)
            ? '<a class="btn btn-solid theme-primary button" href="#termine">Termine entdecken</a>'
            : (isset($links["veranstaltungen"])
                ? '<a class="btn btn-solid theme-primary button" href="/site/'.Html::e($this->language?->path('veranstaltungen')??'veranstaltungen').'">Termine entdecken</a>'
                : "");
        if (isset($links["verein"])) {
            $actions .=
                '<a class="btn btn-solid theme-primary button button--outline" href="/site/'.Html::e($this->language?->path('verein')??'verein').'">Den Verein kennenlernen</a>';
        }
        // The signup form is a built-in public route, independent of CMS menu visibility.
        $actions .=
            '<a class="btn btn-solid theme-primary button button--coral" href="/newsletter/">Newsletter abonnieren</a>';
        $intro =
            $v["hero_html"] !== ""
                ? '<div class="lead kh-richtext">' .
                    \Kulturhus\Native\Shared\RichText::sanitize(
                        $v["hero_html"],
                    ) .
                    "</div>"
                : '<p class="lead">' . Html::e($page["excerpt"]) . "</p>";
        $html =
            '<main id="main"' .
            (isset($page["home_layout"])
                ? ' class="native-home-configured"'
                : "") .
            '><section class="hero hero--home"><div class="hero__content"><p class="eyebrow">' .
            Html::e(
                trim((string) ($this->identity["name"] ?? "")) ?: "Mein Verein",
            ) .
            "</p><h1>" .
            Html::e(
                $v["hero_title"] !== "" ? $v["hero_title"] : $page["title"],
            ) .
            "</h1>" .
            $intro .
            '<div class="hero__actions">' .
            $actions .
            '</div></div><div class="hero__poster" aria-hidden="true"><span>' .
            Html::e($v["poster_top"]) .
            "</span><strong>" .
            Html::e($v["poster_main"]) .
            "</strong><small>" .
            Html::e($v["poster_sub"]) .
            "</small></div></section>";
        foreach ($v["sections"] as $section) {
            $html .= match ($section) {
                "highlights" => $this->highlights($v["highlights"]),
                "events" => $this->event($v, $links),
                "news" => $this->articles
                    ? $this->articles->latest(3, $v["news_heading"],$this->language)
                    : "",
                "content" => trim($page["body"]) !== "" ||
                ($page["body_html"] ?? "") !== "" ||
                !empty($page["custom_fields"])
                    ? '<section class="section native-home-intro"><div class="prose">' .
                        $body .
                        "</div></section>"
                    : "",
                "newsletter" => $this->newsletter($v),
            };
        }
        return $html . "</main>";
    }
    private function highlights(array $values): string
    {
        if (!$values) {
            return "";
        }
        return '<div class="culture-strip" aria-label="Was uns ausmacht">' .
            implode(
                '<i aria-hidden="true">✦</i>',
                array_map(
                    static fn($v) => "<span>" . Html::e($v) . "</span>",
                    $values,
                ),
            ) .
            "</div>";
    }
    private function event(array $v, array $links): string
    {
        $event = $this->pages->events()->next();
        if($event!==null&&$this->language!==null)$event=$this->language->event($event);
        $year = $event
            ? substr($event["event_start"], 0, 4)
            : (new \DateTimeImmutable(
                "now",
                new \DateTimeZone("Europe/Berlin"),
            ))->format("Y");
        $html =
            '<section id="termine" class="section section--events"><div class="section-heading section-heading--numbered"><div><p class="eyebrow">01 · Demnächst</p><h2>' .
            Html::e(str_replace("{jahr}", $year, $v["events_heading"])) .
            "</h2></div>" .
            (isset($links["veranstaltungen"])
                ? '<a class="text-link" href="/site/'.Html::e($this->language?->path('veranstaltungen')??'veranstaltungen').'">Zum Programm</a>'
                : "") .
            "</div>";
        if (!$event) {
            return $html .
                '<p class="empty-state">Neue Termine werden gerade vorbereitet.</p></section>';
        }
        $months = [
            "01" => "JAN",
            "02" => "FEB",
            "03" => "MÄR",
            "04" => "APR",
            "05" => "MAI",
            "06" => "JUN",
            "07" => "JUL",
            "08" => "AUG",
            "09" => "SEP",
            "10" => "OKT",
            "11" => "NOV",
            "12" => "DEZ",
        ];
        $image = "";
        foreach ($event["media_ids"] ?? [] as $id) {
            $variant = $this->media->publicVariant($id, "medium", $event);
            if (!$variant) {
                continue;
            }
            $image =
                '<a class="event-feature__media" href="/site/' .
                Html::e(PublishedLanguage::pagePath($event)) .
                '"><img loading="lazy" decoding="async" src="/media/' .
                Html::e($id) .
                '/medium" width="' .
                Html::e($variant["width"]) .
                '" height="' .
                Html::e($variant["height"]) .
                '" alt="' .
                Html::e($variant["alt"]) .
                '">' . \Kulturhus\Native\Web\MediaCredit::render($variant,false) . '</a>';
            break;
        }
        return $html .
            '<article class="event-feature' .
            ($image !== "" ? " has-image" : "") .
            '">' .
            $image .
            '<div class="date-disc"><strong>' .
            Html::e(substr($event["event_start"], 8, 2)) .
            "</strong><span>" .
            $months[substr($event["event_start"], 5, 2)] .
            " " .
            Html::e($year) .
            '</span></div><div><p class="eyebrow">Vereinsleben</p><h3><a href="/site/' .
            Html::e(PublishedLanguage::pagePath($event)) .
            '">' .
            Html::e($event["title"]) .
            "</a></h3><p>" .
            Html::e($event["excerpt"]) .
            '</p><div class="actions"><a class="btn btn-solid theme-primary button" href="/site/' .
            Html::e(PublishedLanguage::pagePath($event)) .
            '">Mehr erfahren</a></div></div>' .
            "</article></section>";
    }
    private function newsletter(array $v): string
    {
        return '<section class="newsletter-callout"><div class="newsletter-callout__burst" aria-hidden="true">Post!</div><div><p class="eyebrow">Post vom Verein</p><h2>' .
            Html::e($v["newsletter_heading"]) .
            "</h2><p>" .
            Html::e($v["newsletter_text"]) .
            '</p></div><a class="btn btn-solid theme-primary button button--ink" href="/newsletter/">Newsletter abonnieren</a></section>';
    }
}
