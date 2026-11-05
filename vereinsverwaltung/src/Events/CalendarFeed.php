<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;
use Kulturhus\Verein\Events\Calendar\{
    AppointmentSource,
    BirthdaySource,
    CommunicationSource,
    DocumentCheckSource,
    LoanSource,
    WorkflowSource,
};

/** Views of existing records. Never inserts appointments, documents or delivery jobs. */
final class CalendarFeed
{
    public function __construct(
        private PDO $db,
        private string $actor,
        private Registry $registry,
        private ?\Kulturhus\Native\Shared\Access $communication = null,
    ) {}

    public static function range(string $mode, \DateTimeImmutable $day): array
    {
        $first = $day->modify("first day of this month");
        [$start, $days] = match ($mode) {
            "month" => [$first->modify("monday this week"), 42],
            "agenda" => [$first, (int) $first->format("t")],
            "week" => [$day->modify("monday this week"), 7],
            "day" => [$day, 1],
            default => throw new DomainException("Ungültige Kalenderansicht."),
        };
        return [
            "start" => $start,
            "days" => $days,
            "from" => $start->format("Y-m-d"),
            "to" => $start->modify("+" . $days . " days")->format("Y-m-d"),
        ];
    }

    public function clubFilters(): array
    {
        return [
            "all" => "Alles",
            "events" => "Veröffentlichte Veranstaltungen",
            "internal" => "Vereinstermine",
            "external" => "Externe & veröffentlichte Veranstaltungen",
            "board" => "Vorstand & Versammlungen",
        ];
    }

    public function filters(): array
    {
        $filters = $this->clubFilters();
        if ((new BirthdaySource($this->registry))->available()) {
            $filters["birthdays"] = "Geburtstage";
        }
        if (
            (new LoanSource(
                new Inventory($this->db, $this->actor),
            ))->available()
        ) {
            $filters["loans"] = "Ausleihen & Rückgaben";
        }
        if (
            (new DocumentCheckSource(
                $this->db,
                $this->actor,
                $this->registry,
            ))->available()
        ) {
            $filters["deadlines"] = "Fristen & Nachweise";
        }
        if ((new WorkflowSource($this->db, $this->actor))->available()) {
            $filters["workflows"] = "Dokumenten- & HSE-Fristen";
        }
        if ((new \Kulturhus\Verein\Events\Calendar\InspectionSource($this->db, $this->actor))->available()) $filters['inspections']='Gefährdungsprüfungen';
        if ($this->communication) {
            $filters += (new CommunicationSource(
                $this->db,
                $this->actor,
                $this->communication,
            ))->filters();
        }
        return $filters;
    }

    public function between(
        string $from,
        string $to,
        string $filter = "all",
        bool $clubOnly = false,
    ): array {
        foreach ([$from, $to] as $date) {
            $d = \DateTimeImmutable::createFromFormat(
                "!Y-m-d",
                $date,
                new \DateTimeZone("Europe/Berlin"),
            );
            if (!$d || $d->format("Y-m-d") !== $date) {
                throw new DomainException("Ungültiger Kalenderzeitraum.");
            }
        }
        if (
            $from >= $to ||
            (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))
                ->days > 366
        ) {
            throw new DomainException(
                "Kalenderzeitraum muss zwischen einem Tag und einem Jahr liegen.",
            );
        }
        if (!isset(($clubOnly ? $this->clubFilters() : $this->filters())[$filter])) {
            throw new DomainException(
                "Diese Kalenderansicht ist nicht verfügbar.",
            );
        }
        if (!$this->registry->canReadCalendar()) {
            throw new DomainException(
                "Keine Berechtigung für diesen Kalender.",
            );
        }
        $rows = (new AppointmentSource($this->registry))->rows(
            $from,
            $to,
            $filter,
        );
        if (!$clubOnly) {
            if (in_array($filter, ["all", "birthdays"], true)) {
                $rows = array_merge(
                    $rows,
                    (new BirthdaySource($this->registry))->rows($from, $to),
                );
            }
            if (in_array($filter, ["all", "loans"], true)) {
                $rows = array_merge(
                    $rows,
                    (new LoanSource(new Inventory($this->db, $this->actor)))->rows(
                        $from,
                        $to,
                    ),
                );
            }
            if (in_array($filter, ["all", "deadlines"], true)) {
                $rows = array_merge(
                    $rows,
                    (new DocumentCheckSource(
                        $this->db,
                        $this->actor,
                        $this->registry,
                    ))->rows($from, $to),
                );
            }
            if (in_array($filter, ["all", "workflows"], true)) {
                $rows = array_merge(
                    $rows,
                    (new WorkflowSource($this->db, $this->actor))->rows($from, $to),
                );
            }
            if (in_array($filter,['all','inspections'],true)) $rows=array_merge($rows,(new \Kulturhus\Verein\Events\Calendar\InspectionSource($this->db,$this->actor))->rows($from,$to));
            if ($this->communication) {
                $rows = array_merge(
                    $rows,
                    (new CommunicationSource(
                        $this->db,
                        $this->actor,
                        $this->communication,
                    ))->rows($from, $to, $filter),
                );
            }
        }
        usort(
            $rows,
            static fn($a, $b) => [$a["starts_at"], $a["id"]] <=> [
                $b["starts_at"],
                $b["id"],
            ],
        );
        return $rows;
    }
}
