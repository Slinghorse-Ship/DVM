<section class="calendar-board"><div class="calendar-top"><h2><?= e(
    $calendarText($months[(int) $day->format("n") - 1]) . " " . $day->format("Y"),
) ?></h2><div class="segments"><?php foreach (
    [
        "day" => "Tag",
        "week" => "Woche",
        "month" => "Monat",
        "agenda" => "Agenda",
    ]
    as $k => $label
): ?><a class="<?= $calendarMode === $k ? "active" : "" ?>" href="<?= e(
    url([
        "view" => "calendar",
        "date" => $day->format("Y-m-d"),
        "mode" => $k,
        "q" => $term,
        "calendar_filter" => $calendarFilter,
    ]),
) ?>"><?= e($calendarText($label)) ?></a><?php endforeach; ?></div></div><?php if (
    $calendarMode === "agenda"
): ?><div class="calendar-agenda"><?php
foreach (
    $events
    as $ev
): ?><article class="card"><span class="calendar-source <?= e(
    \Kulturhus\Verein\Events\Calendar\Presentation::css($ev),
) ?>"><?= e(
    $calendarText(\Kulturhus\Verein\Events\Calendar\Presentation::label($ev)),
) ?></span><span class="muted"><?= e(
    dateDe(substr(localDateTime($ev["starts_at"]), 0, 10)) .
        " · " .
        (!empty($ev["all_day"]) ? $calendarText("Ganztägig") : timeDe($ev["starts_at"])),
) ?></span><h3><a href="<?= e(
    url($ev["route"] ?? ["view" => "calendar", "event" => $ev["id"]]),
) ?>"><?= e($ev["title"]) ?></a></h3><p><?= e(
    $ev["location"],
) ?></p></article><?php endforeach;
if (
    !$events
): ?><p class="empty-state"><?= e($calendarText('Keine Einträge in dieser Monatsansicht.')) ?></p><?php endif;
?></div><?php else: ?><div class="calendar-scroll" tabindex="0" role="region" aria-label="<?= e($calendarText('Kalenderraster')) ?>"><div class="calendar-grid <?= $calendarMode ===
"day"
    ? "single-day"
    : "" ?>"><?php
if ($days > 1):
    foreach (
        ["Mo", "Di", "Mi", "Do", "Fr", "Sa", "So"]
        as $weekday
    ): ?><div class="weekday"><?= e($calendarText($weekday)) ?></div><?php endforeach;
endif;
for ($i = 0; $i < $days; ++$i):

    $date = $rangeStart->modify("+" . $i . " days");
    $ymd = $date->format("Y-m-d");
    ?><div class="calendar-cell <?= $date->format("m") !== $day->format("m")
    ? "outside"
    : "" ?> <?= $ymd === \Kulturhus\Verein\Events\Calendar\Presentation::today()
     ? "today"
     : "" ?>"><span class="day-number"><?= $date->format(
    "j",
) ?></span><?php foreach ($events as $ev):
    $start = substr(localDateTime($ev["starts_at"]), 0, 10);
    $end = substr(localDateTime($ev["ends_at"]), 0, 10);
    if ($start <= $ymd && $end >= $ymd): ?><a class="calendar-event <?= e(
    $ev["category"],
) ?> <?= e(
     \Kulturhus\Verein\Events\Calendar\Presentation::css($ev),
 ) ?>" href="<?= e(
    url($ev["route"] ?? ["view" => "calendar", "event" => $ev["id"]]),
) ?>"><span class="calendar-source-name"><?= e(
    $calendarText(\Kulturhus\Verein\Events\Calendar\Presentation::label($ev)),
) ?></span><span><?= e(
    !empty($ev["all_day"]) ? $calendarText("Ganztägig") : timeDe($ev["starts_at"]),
) ?></span> <?= e($ev["title"]) ?></a><?php endif;
endforeach; ?></div><?php
endfor;
?></div></div><?php endif; ?></section>
