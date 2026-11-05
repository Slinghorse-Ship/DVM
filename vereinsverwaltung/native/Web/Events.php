<?php
declare(strict_types=1);

namespace Kulturhus\Native\Web;

use DateTimeImmutable;
use DateTimeZone;
use Kulturhus\Native\Cms\{EventDetails, Events as Catalogue, Media};

/** Public programme/archive and details share the same immutable event catalogue. */
final class Events
{
    public function __construct(
        private Catalogue $catalogue,
        private Media $media,
        private string $origin,
        private array $identity = [],
        private ?PublishedLanguage $language = null,
        private ?\Closure $privateMediaUrl = null,
    ) {}

    public function index(array $container, array $query,?\Closure $pageLink=null,?\Closure $resolvedPage=null,?string $listingPath=null): string
    {
        $list = $this->catalogue->listing($query,null,20,$this->language!==null?$this->language->eventSearch(...):null);
        $resolvedPage?->__invoke((int)$list['page']);
        if($this->language!==null)$list['rows']=array_values(array_filter(array_map($this->language->event(...),$list['rows'])));
        $programPath=$listingPath??'/site/'.($this->language?->path('veranstaltungen')??'veranstaltungen');
        $e = Html::e(...);
        $year = $list["rows"]
            ? EventDetails::start($list["rows"][0])
                ->setTimezone(new DateTimeZone("Europe/Berlin"))
                ->format("Y")
            : substr($list["today"], 0, 4);
        $title = $list["archive"] ? "Programmarchiv" : "Programm " . $year;
        $intro = $list["archive"]
            ? "Vergangene Veranstaltungen zum Nachlesen und Wiederentdecken."
            : $container["excerpt"];
        $h =
            '<main id="main" class="subpage"><header class="page-hero page-hero--calendar"><p class="eyebrow">01 · Kalender</p><h1>' .
            $e($title) .
            '</h1><p class="lead">' .
            $e($intro) .
            '</p></header><section class="section"><nav class="program-filters" aria-label="Programm und Archiv"><a class="button' .
            ($list["archive"] ? " button--outline" : "") .
            '" href="'.Html::e($programPath).'"' .
            (!$list["archive"] ? ' aria-current="page"' : "") .
            '>Kommende Veranstaltungen</a><a class="button' .
            ($list["archive"] ? "" : " button--outline") .
            '" href="'.Html::e($programPath).'?archiv=1"' .
            ($list["archive"] ? ' aria-current="page"' : "") .
            ">Programmarchiv</a></nav>";
        $h .=
            '<form method="get" action="'.Html::e($programPath).'" class="program-filters"><input type="hidden" name="archiv" value="' .
            (int) $list["archive"] .
            '">' .
            Html::input(
                "q",
                "Suche",
                $list["query"],
                "search",
                'maxlength="150" placeholder="Titel, Beschreibung oder Ort"',
            ) .
            '<label>Jahr<select class="form-control" name="jahr"><option value="0">Alle Jahre</option>';
        foreach ($list["years"] as $option) {
            $h .=
                '<option value="' .
                $option .
                '"' .
                ($list["year"] === $option ? " selected" : "") .
                ">" .
                $option .
                "</option>";
        }
        $h .=
            '</select></label><label>Sortierung<select class="form-control" name="sort"><option value="asc"' .
            ($list["sort"] === "asc" ? " selected" : "") .
            '>Älteste zuerst</option><option value="desc"' .
            ($list["sort"] === "desc" ? " selected" : "") .
            '>Neueste zuerst</option></select></label><button class="btn btn-solid theme-primary button">Anzeigen</button></form><p>' .
            $list["total"] .
            ' Veranstaltungen</p><div class="event-list">';
        foreach ($list["rows"] as $page) {
            $h .= $this->card($page);
        }
        if (!$list["rows"]) {
            $h .=
                '<p class="empty-state">Keine Veranstaltungen für diese Auswahl gefunden.</p>';
        }
        $h .= "</div>";
        if ($list["pages"] > 1) {
            $h .=
                '<nav class="program-filters" aria-label="Weitere Veranstaltungen">';
            $numbers = array_unique([
                1,
                ...range(
                    max(1, $list["page"] - 2),
                    min($list["pages"], $list["page"] + 2),
                ),
                $list["pages"],
            ]);
            sort($numbers);
            $previous = 0;
            foreach ($numbers as $number) {
                if ($previous && $number > $previous + 1) {
                    $h .= '<span aria-hidden="true">…</span>';
                }
                $url =
                    $programPath."?" .
                    http_build_query(
                        [
                            "archiv" => (int) $list["archive"],
                            "jahr" => $list["year"],
                            "q" => $list["query"],
                            "sort" => $list["sort"],
                            "seite" => $number,
                        ],
                        "",
                        "&",
                        PHP_QUERY_RFC3986,
                    );
                if($pageLink)$url=$pageLink($number);
                $h .=
                    '<a class="btn btn-solid theme-primary button button--outline" href="' .
                    $e($url) .
                    '"' .
                    ($number === $list["page"] ? ' aria-current="page"' : "") .
                    ' aria-label="Seite ' .
                    $number .
                    '">' .
                    $number .
                    "</a>";
                $previous = $number;
            }
            $h .= "</nav>";
        }
        return $h . "</section></main>";
    }

    private function card(array $page): string
    {
        $e = Html::e(...);
        $date = EventDetails::start($page)->setTimezone(
            new DateTimeZone($page["event_timezone"] ?? "Europe/Berlin"),
        );
        $url = "/site/" . PublishedLanguage::pagePath($page);
        $cancelled = EventDetails::cancelled($page);
        $image = $this->image($page, false);
        $h =
            '<article class="event-row' .
            ($image !== "" ? " has-image" : "") .
            ($cancelled ? " is-cancelled" : "") .
            '">';
        if ($image !== "") {
            $h .=
                '<a class="event-row__media" href="' .
                $e($url) .
                '">' .
                $image .
                "</a>";
        }
        return $h .
            '<time datetime="' .
            $e($date->format(DATE_ATOM)) .
            '"><strong>' .
            $date->format("d") .
            "</strong><span>" .
            $e($date->format("m.Y")) .
            "<br>" .
            $date->format("H:i") .
            ' Uhr</span></time><div><p class="eyebrow">' .
            $e(EventDetails::label($page)) .
            '</p><h2><a href="' .
            $e($url) .
            '">' .
            $e($page["title"]) .
            "</a></h2><p>" .
            $e($page["excerpt"]) .
            "</p><p>" .
            $e($page["event_location"]) .
            '</p></div><a class="btn btn-solid theme-primary button" href="' .
            $e($url) .
            '">' .
            ($cancelled ? "Absage ansehen" : "Details") .
            "</a></article>";
    }

    public function detail(array $page, string $custom = ""): string
    {
        $page['event_status']=\Kulturhus\Native\Cms\EventDetails::status($page);
        if($this->language!==null){$current=$this->catalogue->publicId($page['id']);$page=$current!==null?$this->language->event($current):null;if($page===null)return '<main id="main"><p>Diese Veranstaltung ist nicht veröffentlicht.</p></main>';}
        $tickets=$this->catalogue->ticketSales($page['id']);
        if($tickets['offer']!==null)$page['event_price']=\Kulturhus\Native\Payments\Money::decimal($tickets['offer']['price_cents']);
        $e = Html::e(...);
        $zone = new DateTimeZone($page["event_timezone"] ?? "Europe/Berlin");
        $start = EventDetails::start($page)?->setTimezone($zone);
        $end = EventDetails::end($page)?->setTimezone($zone);
        $today = new DateTimeImmutable(
            "today",
            new DateTimeZone("Europe/Berlin"),
        );
        $past = $start !== null && $start < $today;
        $cancelled = EventDetails::cancelled($page);
        $address = $page["event_address"] ?? "";
        $h =
            '<main id="main" class="subpage"><article><header class="page-hero page-hero--event"><a class="back-link" href="/site/'.Html::e($this->language?->path('veranstaltungen')??'veranstaltungen') .
            ($past ? "?archiv=1" : "") .
            '">← ' .
            ($past ? "Programmarchiv" : "Alle Veranstaltungen") .
            '</a><p class="eyebrow">' .
            $e(EventDetails::label($page)) .
            "</p><h1>" .
            $e($page["title"]) .
            "</h1></header>";
        if ($cancelled) {
            $h .=
                '<div class="event-alert" role="status"><strong>Diese Veranstaltung wurde abgesagt.</strong><span>Die Veranstaltung findet nicht statt.</span></div>';
        } elseif (($page["event_status"] ?? "") === "postponed") {
            $h .=
                '<div class="event-alert" role="status"><strong>Diese Veranstaltung wurde verschoben.</strong><span>Bitte die aktuellen Terminangaben beachten.</span></div>';
        }
        $h .=
            '<section class="event-facts event-overview" aria-label="Termin und Veranstaltungsort"><dl><div><dt>Datum</dt><dd>' .
            ($start
                ? '<time datetime="' .
                    $e($start->format(DATE_ATOM)) .
                    '">' .
                    $start->format("d.m.Y") .
                    "</time>"
                : "Termin folgt") .
            "</dd></div>";
        if ($start) {
            $h .=
                "<div><dt>Beginn</dt><dd>" .
                $start->format("H:i") .
                " Uhr<span>" .
                $e($zone->getName()) .
                "</span></dd></div>";
        }
        if ($end && $start && $end > $start) {
            $h .=
                '<div><dt>Ende</dt><dd><time datetime="' .
                $e($end->format(DATE_ATOM)) .
                '">' .
                $e(
                    $end->format(
                        $end->format("Y-m-d") === $start->format("Y-m-d")
                            ? "H:i"
                            : "d.m.Y · H:i",
                    ),
                ) .
                " Uhr</time></dd></div>";
        }
        $h .=
            "<div><dt>Ort</dt><dd>" .
            $e($page["event_location"] ?: "Ort folgt") .
            ($address !== ""
                ? "<span>" . nl2br($e($address)) . "</span>"
                : "") .
            "</dd></div>";
        $price = $page["event_price"] ?? "";
        if ($price !== "") {
            $h .=
                "<div><dt>Eintritt</dt><dd>" .
                ((float) $price === 0.0
                    ? "Eintritt frei"
                    : $e(number_format((float) $price, 2, ",", ".")) . " €") .
                "</dd></div>";
        }
        $h .= '</dl><div class="event-fact-actions">';
        if ($start) {
            $h .=
                '<a class="btn btn-solid theme-primary button button--outline" href="/feeds/termine/?event=' .
                $e($page["id"]) .
                '">Termin speichern</a>';
        }
        $location = trim($page["event_location"] . " " . $address);
        if ($location !== "") {
            $h .=
                '<a class="text-link" href="https://www.openstreetmap.org/search?query=' .
                rawurlencode($location) .
                '" target="_blank" rel="external noopener noreferrer">Anfahrt anzeigen</a>';
        }
        if ($tickets['provider']==='native'&&$tickets['offer']!==null) {
            $h.='<a class="btn btn-solid theme-primary button" href="/tickets/buy?event='.Html::e($page['id']).'">Tickets buchen</a>';
        } elseif ($tickets['provider']==='eventbrite'&&
            !$past &&
            !in_array(
                $page["event_status"] ?? "scheduled",
                ["cancelled", "sold_out", "postponed", "ended"],
                true,
            ) &&
            ($page["event_ticket_url"] ?? "") !== ""
        ) {
            $h .=
                '<a class="btn btn-solid theme-primary button" href="' .
                $e($page["event_ticket_url"]) .
                '" target="_blank" rel="external noopener noreferrer">Tickets</a>';
        }
        if (($page["event_reference_url"] ?? "") !== "") {
            $h .=
                '<a class="btn btn-solid theme-primary button button--outline" href="' .
                $e($page["event_reference_url"]) .
                '" target="_blank" rel="external noopener noreferrer">Weitere Informationen</a>';
        }
        $h .= "</div></section>";
        if ($page["excerpt"] !== "") {
            $h .=
                '<p class="lead event-intro">' . $e($page["excerpt"]) . "</p>";
        }
        $h .=
            $this->image($page, true) .
            '<div class="section event-description"><div class="prose kh-richtext">' .
            Html::content($page) .
            "</div>" .
            $custom .
            "</div></article>" .
            $this->structured($page, $start, $end) .
            "</main>";
        return $h;
    }

    private function image(array $page, bool $detail): string
    {
        $h = "";
        $e = Html::e(...);
        foreach ($page["media_ids"] ?? [] as $id) {
            $m = $this->privateMediaUrl?$this->media->variant($id,'medium'):$this->media->publicVariant($id, "medium", $page);
            $thumb = $this->privateMediaUrl?$this->media->variant($id,'thumb'):$this->media->publicVariant($id, "thumb", $page);
            $large = $this->privateMediaUrl?$this->media->variant($id,'large'):$this->media->publicVariant($id, "large", $page);
            if (!$m || !$thumb) {
                continue;
            }
            $url=fn(string $variant):string=>$this->privateMediaUrl?($this->privateMediaUrl)($id,$variant):'/media/'.$id.'/'.$variant;
            $set =
                $e($url('thumb'))." " .
                $thumb["width"] .
                "w, " . $e($url('medium'))." " .
                $m["width"] .
                "w";
            if ($large && $large["width"] > $m["width"]) {
                $set .=
                    ", " . $e($url('large')) . " " . $large["width"] . "w";
            }
            $image =
                '<img loading="lazy" decoding="async" src="' .
                $e($url('medium')) .
                '" srcset="' .
                $set .
                '" sizes="' .
                ($detail
                    ? "(max-width: 1180px) calc(100vw - 2rem), 1150px"
                    : "(max-width: 700px) 90vw, 300px") .
                '" width="' .
                $m["width"] .
                '" height="' .
                $m["height"] .
                '" alt="' .
                $e($m["alt"]) .
                '">';
            if (!$detail) {
                return $image . MediaCredit::render($m,false);
            }
            $h .=
                '<figure class="event-detail-image">' .
                $image .
                "<figcaption>" .
                MediaCredit::render($m) .
                "</figcaption></figure>";
        }
        return $h;
    }

    private function structured(
        array $page,
        ?DateTimeImmutable $start,
        ?DateTimeImmutable $end,
    ): string {
        $url = rtrim($this->origin, "/") . "/site/" . PublishedLanguage::pagePath($page);
        $data = [
            "@context" => "https://schema.org",
            "@type" => "Event",
            "name" => $page["title"],
            "url" => $url,
            "eventStatus" =>
                "https://schema.org/" .
                match ($page["event_status"] ?? "scheduled") {
                    "cancelled" => "EventCancelled",
                    "postponed" => "EventPostponed",
                    default => "EventScheduled",
                },
            "eventAttendanceMode" =>
                "https://schema.org/OfflineEventAttendanceMode",
            "location" => [
                "@type" => "Place",
                "name" => $page["event_location"],
                "address" => $page["event_address"] ?? "",
            ],
            "description" => $page["excerpt"] ?: $page["body"],
            "organizer" => [
                "@type" => "Organization",
                "name" =>
                    trim((string) ($this->identity["name"] ?? "")) ?:
                    "Mein Verein",
                "url" => rtrim($this->origin, "/") . "/",
            ],
        ];
        if ($start) {
            $data["startDate"] = $start->format(DATE_ATOM);
        }
        if ($end) {
            $data["endDate"] = $end->format(DATE_ATOM);
        }
        foreach ($page["media_ids"] ?? [] as $id) {
            if ($this->media->publicVariant($id, "large", $page)) {
                $data["image"] =
                    rtrim($this->origin, "/") . "/media/" . $id . "/large";
                break;
            }
        }
        return '<script type="application/ld+json">' .
            json_encode(
                $data,
                JSON_THROW_ON_ERROR |
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES |
                    JSON_HEX_TAG |
                    JSON_HEX_AMP |
                    JSON_HEX_APOS |
                    JSON_HEX_QUOT,
            ) .
            "</script>";
    }
}
