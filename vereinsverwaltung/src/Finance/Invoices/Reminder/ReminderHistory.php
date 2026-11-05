<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Invoices\Reminder;

use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;

/** Personal originals only. Invalid controls never fall back to another page. */
final class ReminderHistory
{
    public static function read(PDO $db, string $actorId, string $section, string $id, array $query): array
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $id) || !in_array($section, [ReminderRequests::SECTION, ReminderDecisions::SECTION], true)) throw new DomainException('Eigene ursprüngliche Erinnerung auswählen.', 404);
        $field = $section === ReminderRequests::SECTION ? 'invoice' : 'job';
        $search = $query['query'] ?? '';
        $rawPage = $query['page'] ?? '1';
        $page = is_string($rawPage) || is_int($rawPage) ? (string)$rawPage : '';
        $error = '';
        if (!is_string($search) || preg_match('//u', $search) !== 1 || mb_strlen($search) > 150 || preg_match('/[\x00-\x1f\x7f]/', $search)) $error = 'Ganze Erinnerungssuche ohne Steuerzeichen mit höchstens 150 Zeichen eingeben.';
        if (!preg_match('/\A[1-9][0-9]{0,8}\z/D', $page)) $error = 'Verfügbare genaue Erinnerungsseite auswählen.';
        $own = !$db->inTransaction();
        try {
            if ($own) $db->exec('START TRANSACTION');
            $actor = (new ReminderRequests($db, $actorId))->assertAccess();
            $base = ['rows'=>[], 'total'=>0, 'pages'=>1, 'page'=>null, 'page_input'=>$page, 'query'=>is_string($search) ? $search : '', 'per_page'=>25, 'error'=>$error];
            if ($error === '') {
                $where = " WHERE club_id=? AND actor_id=? AND section=? AND JSON_UNQUOTE(JSON_EXTRACT(input_json,'$.".$field."'))=?";
                $args = [$actor['club_id'], $actor['id'], $section, $id];
                if ($search !== '') {
                    $where .= " AND LOCATE(LOWER(?),LOWER(CONCAT_WS(CHAR(10),request_key,created_at,input_json)))>0";
                    $args[] = $search;
                }
                $total = (int)Db::run($db, 'SELECT COUNT(*) FROM native_settings_requests'.$where.' FOR SHARE NOWAIT', $args)->fetchColumn();
                $pages = max(1, (int)ceil($total / 25));
                if ((int)$page > $pages) $base = array_replace($base, ['total'=>$total, 'pages'=>$pages, 'error'=>'Verfügbare genaue Erinnerungsseite auswählen.']);
                else {
                    $rows = Db::run($db, 'SELECT request_key,created_at FROM native_settings_requests'.$where.' ORDER BY created_at DESC,request_key DESC LIMIT 25 OFFSET '.(((int)$page-1)*25).' FOR SHARE NOWAIT', $args)->fetchAll();
                    $base = array_replace($base, ['rows'=>$rows, 'total'=>$total, 'pages'=>$pages, 'page'=>(int)$page]);
                }
            }
            if ($own) $db->commit();
            return $base;
        } catch (\Throwable $failure) {
            if ($own && $db->inTransaction()) $db->rollBack();
            if ($failure instanceof \PDOException && in_array((int)($failure->errorInfo[1] ?? 0), [1205,3572], true)) throw new DomainException('Rechnung oder Erinnerung wird gerade bearbeitet. Erneut prüfen.', 503, $failure);
            throw $failure;
        }
    }
}
