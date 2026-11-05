<?php declare(strict_types=1);
namespace Kulturhus\Verein\Events\Calendar;

use Kulturhus\Verein\ManagerDocumentWorkflows;
use PDO;

/** Date metadata from the optional document/HSE module, with its own live permissions. */
final class WorkflowSource
{
    public function __construct(private PDO $db, private string $actor) {}

    public function available(): bool
    {
        return ManagerDocumentWorkflows::installed($this->db) &&
            (new ManagerDocumentWorkflows($this->db, $this->actor))->allows();
    }

    public function rows(string $from, string $to): array
    {
        if (!$this->available()) return [];
        $statuses = ['draft' => 'Entwurf', 'submitted' => 'In Prüfung',
            'returned' => 'Zur Überarbeitung', 'approved' => 'Freigegeben'];
        $rows = [];
        foreach ((new ManagerDocumentWorkflows($this->db, $this->actor))->store->deadlines($from, $to) as $document) {
            foreach ($document['dates'] as $date => $labels) {
                $rows[] = Entry::dated('workflow:'.$document['id'].':'.$date,
                    implode(' / ', $labels).': '.$document['title'], $date, 'workflows',
                    ['view' => 'documents', 'tab' => 'workflows', 'wf_doc' => $document['id'],
                        'wf_revision' => (int)$document['version']], true, $statuses[$document['status']]);
            }
        }
        return $rows;
    }
}
