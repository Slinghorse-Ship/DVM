<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration\Jobs;
use PDO;
use DomainException;
use Throwable;
use Kulturhus\Native\Shared\{Db, SecretBox};
use Kulturhus\Native\Communication\Campaigns;
use Kulturhus\Native\Delivery\{Outbox, MailAccount};
use Kulturhus\Verein\Infrastructure\Runtime\ServerKey;
/** Private orchestration; each original domain remains responsible for its own records. */
final class Worker
{
    public static function run(array $options, ?callable $personalHttp = null, ?callable $metaHttp = null,?callable $metaUploadHttp=null,?callable $eventHttp=null,?callable $pressSend=null): array
    {
        if (PHP_SAPI !== "cli") {
            throw new DomainException("Private CLI-Laufzeit erforderlich.");
        }
        $workerRun = null;
        try {
            if(isset($options['stories'])&&array_diff(array_keys($options),['stories','send','config','limit']))throw new DomainException('Story-Automatik separat ohne andere Versandoptionen aufrufen.');
            if (isset($options['fints']) && array_diff(array_keys($options), ['fints', 'config', 'limit'])) throw new DomainException('Bankabruf getrennt ohne Versand-, Zahlungs- oder Veröffentlichungsoptionen aufrufen.');
            if(isset($options['catalog-billing'])&&array_intersect(['fixture','send','receive','push','publish','meta','stadtlandfunk','social-check','events','mail-replies','press'],array_keys($options)))throw new DomainException('Tarifautomatik separat ohne Versandoptionen aufrufen.');
            if(isset($options['press'])&&array_intersect(['fixture','receive','push','publish','meta','stadtlandfunk','social-check','events','mail-replies'],array_keys($options)))throw new DomainException('Presseversand separat mit privater Vereinskonfiguration aufrufen.');
            if($pressSend!==null&&!isset($options['press']))throw new DomainException('Presse-Transport nur im eigenen Pressemodus verwenden.');
            if(isset($options['events'])&&array_intersect(['fixture','send','receive','push','publish','meta','stadtlandfunk','social-check','mail-replies'],array_keys($options)))throw new DomainException('Veranstaltungsabgleich separat mit privater Vereinskonfiguration aufrufen.');
            if (isset($options['meta']) && array_intersect(
                ['fixture', 'social-check', 'receive', 'push', 'publish', 'stadtlandfunk', 'mail-replies'],
                array_keys($options),
            )) {
                throw new DomainException('Meta separat mit privater Vereinskonfiguration aufrufen.');
            }
            if(isset($options['stadtlandfunk'])&&array_intersect(['fixture','social-check','receive','push','publish','meta','mail-replies'],array_keys($options)))throw new DomainException('StadtLand.Funk separat mit privater Vereinskonfiguration aufrufen.');
            if(isset($options['mail-replies'])&&array_intersect(['fixture','social-check','send','receive','push','publish','meta','stadtlandfunk'],array_keys($options)))throw new DomainException('Mailantworten separat mit privater Vereinskonfiguration abrufen.');
            if (
                isset($options["social-check"]) &&
                (isset($options["fixture"]) ||
                    array_intersect(
                        ["send", "receive", "push", "publish", "meta", "stadtlandfunk"],
                        array_keys($options),
                    ))
            ) {
                throw new DomainException(
                    "Social-Abgleich separat mit privater Vereinskonfiguration aufrufen.",
                );
            }
            $limit = filter_var(
                $options["limit"] ?? "50",
                FILTER_VALIDATE_INT,
                ["options" => ["min_range" => 1, "max_range" => 200]],
            );
            if ($limit === false) {
                throw new DomainException(
                    "Gültiges Workerlimit zwischen 1 und 200 erforderlich.",
                );
            }
            $nativeClub = null;
            $canSend = false;
            $workerActor = null;
            $fintsActor = null;
            $eventActor = null;
            $catalogActor = null;
            $automationStart = null;
            if (isset($options["fixture"])) {
                if (
                    isset($options["send"]) ||
                    isset($options["receive"]) ||
                    isset($options["push"]) ||
                    isset($options["publish"])
                ) {
                    throw new DomainException(
                        "Fixture-Worker darf keine Dienste oder Veröffentlichungen ausführen.",
                    );
                }
                $db = \Kulturhus\Native\TestSupport::db();
                $key =
                    getenv("KH_NATIVE_PRIVATE_KEY_FILE") ?:
                    dirname(__DIR__, 4) .
                        "/.local/native-cms-integration/private.key";
            } else {
                $file = $options["config"] ?? "";
                if (
                    !is_string($file) ||
                    !str_starts_with($file, "/") ||
                    is_link($file) ||
                    !is_file($file) ||
                    fileperms($file) & 0077
                ) {
                    throw new DomainException(
                        "Private Laufzeitdatei (0600) erforderlich.",
                    );
                }
                $c = require $file;
                if (
                    !is_array($c) ||
                    !str_starts_with($c["dsn"] ?? "", "mysql:")
                ) {
                    throw new DomainException(
                        "Native MySQL-Konfiguration erforderlich.",
                    );
                }
                if (isset($c["environment_file"])) {
                    $environmentFile = $c["environment_file"];
                    if (
                        !is_string($environmentFile) ||
                        !str_starts_with($environmentFile, "/") ||
                        is_link($environmentFile) ||
                        !is_file($environmentFile) ||
                        fileperms($environmentFile) & 0077
                    ) {
                        throw new DomainException(
                            "Private Umgebungskonfiguration prüfen.",
                        );
                    }
                    $environment = json_decode(
                        file_get_contents($environmentFile),
                        true,
                        64,
                        JSON_THROW_ON_ERROR,
                    );
                    if (
                        !is_array($environment) ||
                        ($environment["VEREIN_CLUB"] ?? null) !==
                            ($c["club_id"] ?? null)
                    ) {
                        throw new DomainException(
                            "Umgebung gehört nicht zum Workerverein.",
                        );
                    }
                    foreach ($environment as $name => $value) {
                        if (
                            !is_string($name) ||
                            !preg_match('/^[A-Z][A-Z0-9_]*$/D', $name) ||
                            !is_string($value)
                        ) {
                            throw new DomainException(
                                "Private Umgebungswerte prüfen.",
                            );
                        }
                        putenv($name . "=" . $value);
                    }
                }
                $db = new PDO($c["dsn"], $c["username"], $c["password"], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                $key = $c["key_file"];
                $managerKeyFile = $c["manager_key_file"] ?? null;
                $authKeyFile = $c["auth_key_file"] ?? null;
                $originalClub = $c["club_id"] ?? null;
                $origin = $c["origin"] ?? null;
                $workerActor =
                    $c["worker_actor"] ??
                    (getenv("VEREIN_WORKER_ACTOR") ?: null);
                $catalogActor=isset($options['catalog-billing'])?($c['catalog_actor']??$workerActor):null;
                if(isset($options['catalog-billing'])&&(!is_string($catalogActor)||$catalogActor===''||strlen($catalogActor)>128))throw new DomainException('Aktuellen Vereinszugang für die Tarifautomatik konfigurieren.');
                $eventActor=isset($options['events'])?($c['event_actor']??$workerActor):null;
                if($eventActor!==null&&(!is_string($eventActor)||$eventActor===''||strlen($eventActor)>128))throw new DomainException('Aktuellen Vereinszugang für den Veranstaltungsabgleich prüfen.');
                $automationStart =
                    $c["automation_start"] ??
                    (getenv("VEREIN_AUTOMATION_START") ?: null);
                $fintsActor = $c['fints_actor'] ?? (getenv('VEREIN_FINTS_WORKER_ACTOR') ?: null);
                unset($c, $environment);
                if (
                    !is_string($originalClub) ||
                    !is_string($origin) ||
                    !is_string($managerKeyFile) ||
                    !is_string($authKeyFile)
                ) {
                    throw new DomainException(
                        "Vollständige native Vereinskonfiguration erforderlich.",
                    );
                }
                $nativeClub = OriginalJobs::context(
                    $db,
                    $originalClub,
                    $origin,
                    $workerActor,
                    $automationStart,
                )["club"];
                putenv("KH_NATIVE_MANAGER=1");
                putenv("VEREIN_AUTH=native");
                putenv("KH_NATIVE_PRIVATE_KEY_FILE=" . $managerKeyFile);
                putenv("APP_URL=" . $origin);
                putenv("VEREIN_PUBLIC_URL=" . $origin);
                $gate = \Kulturhus\Native\Shared\OperationGate::shared(
                    dirname($key),
                );
                if (is_file(dirname($key) . "/recovery-hold")) {
                    throw new DomainException(
                        "Nach Wiederherstellung den Betrieb zuerst prüfen und explizit freigeben.",
                    );
                }
            }
            if (!isset($options["fixture"])) {
                $canSend = (isset($options['stadtlandfunk']) || isset($options['meta']) || isset($options['stories']))
                    ? isset($options['send']) && getenv('KULTURHUS_DISABLE_WEBSITE') !== '1'
                    :
                    isset($options["send"]) &&
                    \Kulturhus\Verein\MailPolicy::allowed($origin);
            }
            $box = SecretBox::fromFile($key);
            $canPublish =
                $nativeClub !== null &&
                isset($options["publish"]) &&
                getenv("KULTURHUS_DISABLE_WEBSITE") !== "1";
            if ($nativeClub !== null) {
                \Kulturhus\Native\Installer\SchemaRegistry::assertCurrent($db);
                $workerRun = WorkerRuns::begin($db, $nativeClub, [
                    "social-check" => isset($options["social-check"]),
                    "events" => isset($options['events']),
                    "mail-replies" => isset($options['mail-replies']),
                    "press" => isset($options['press']),
                    "catalog-billing" => isset($options['catalog-billing']),
                    "fints" => isset($options['fints']),
                    "send" => $canSend,
                    "receive" => isset($options["receive"]),
                    "push" => isset($options["push"]),
                    "meta" => isset($options["meta"]),
                    "stories" => isset($options['stories']),
                    "stadtlandfunk" => isset($options['stadtlandfunk']),
                    "publish" => $canPublish,
                ]);
            }
            if(isset($options['stories'])&&$nativeClub!==null){
                $automation=new \Kulturhus\Native\Communication\StoryAutomation($db,$box,getenv('KH_NATIVE_MANAGER_MEDIA_ROOT')?:dirname($key).'/media',$metaHttp!==null?\Closure::fromCallable($metaHttp):null,$metaUploadHttp!==null?\Closure::fromCallable($metaUploadHttp):null);
                $stories=$automation->run($nativeClub,min(20,$limit),$canSend);
                $result=['worker'=>'native-stories','story_automation'=>['prepared'=>$stories['prepared'],'review'=>$stories['review']],'deliveries'=>$stories['deliveries'],'transport'=>$stories['transport']];
                WorkerRuns::finish($db,$nativeClub,$workerRun,$result);return $result;
            }
            if (isset($options['fints']) && $nativeClub !== null) {
                $result = FinTs::run($db, $originalClub, $fintsActor, dirname($key));
                WorkerRuns::finish($db, $nativeClub, $workerRun, $result); return $result;
            }
            if(isset($options['catalog-billing'])&&$nativeClub!==null){
                $catalog=new \Kulturhus\Verein\CatalogBillingAutomation($db,$catalogActor);
                $actor=\Kulturhus\Verein\Permissions::actor($db,$catalogActor);if($actor['club_id']!==$originalClub)throw new DomainException('Tarifzugang gehört zu einem anderen Verein.');
                $result=['worker'=>'native-catalog-billing','catalog_billing'=>$catalog->run(null,min(20,$limit)),'transport'=>'local-only','deliveries'=>[]];
                WorkerRuns::finish($db,$nativeClub,$workerRun,$result);return $result;
            }
            if(isset($options['events'])&&$nativeClub!==null){
                $enabled=getenv('KULTURHUS_DISABLE_WEBSITE')!=='1';
                $events=['source'=>null,'results'=>[]];
                if($enabled){
                    $access=(new \Kulturhus\Native\Integration\ManagerBridge($db,$eventActor??'','native',static fn()=>null))->access();
                    if($access->club()!==$nativeClub)throw new DomainException('Veranstaltungszugang gehört nicht zum konfigurierten Verein.');
                    $events=\Kulturhus\Native\Events\Sync::run($db,$access,$box,$eventHttp);
                }
                $result=['worker'=>'native-events','events'=>$events,'transport'=>$enabled?'enabled':'disabled'];
                WorkerRuns::finish($db,$nativeClub,$workerRun,$result);return $result;
            }
            if(isset($options['press'])&&$nativeClub!==null){
                $mail=new MailAccount($db,$box,static fn()=>ServerKey::nativeRuntime($managerKeyFile));
                $send=$pressSend??static fn(string $club,array $message):string=>$mail->send($club,$message,'press');
                $deliveries=$canSend?(new Outbox($db,$box))->run(static fn($club,$payload,$kind)=>$send($club,$payload),$limit,['press'],$nativeClub):[];
                $result=['worker'=>'native-press','deliveries'=>$deliveries,'transport'=>$canSend?'enabled':'disabled'];
                WorkerRuns::finish($db,$nativeClub,$workerRun,$result);return $result;
            }
            if(isset($options['stadtlandfunk'])&&$nativeClub!==null){
                $publisher=new \Kulturhus\Native\Communication\PersonalPublication($db,$box,new \Kulturhus\Native\Integration\StadtLandFunk\Connection($db,$box,new \Kulturhus\Native\Integration\StadtLandFunk\Login($personalHttp)));
                $mediaRoot=dirname($key).'/media';
                $result=['worker'=>'native-stadtlandfunk','deliveries'=>$canSend?(new Outbox($db,$box))->run(static fn($club,$payload,$kind,$job)=>$publisher->send($club,$payload,$job,$mediaRoot),$limit,['stadtlandfunk'],$nativeClub):[],'transport'=>$canSend?'enabled':'disabled'];
                WorkerRuns::finish($db,$nativeClub,$workerRun,$result);return $result;
            }
            if (isset($options['meta']) && $nativeClub !== null) {
                $mediaRoot=getenv('KH_NATIVE_MANAGER_MEDIA_ROOT')?:dirname($key).'/media';
                $publisher = new \Kulturhus\Native\Communication\Meta($db, $box, $metaHttp,$mediaRoot,$metaUploadHttp);
                $result = [
                    'worker' => 'native-meta',
                    'deliveries' => $canSend ? (new Outbox($db, $box))->run(
                        static fn($club, $payload, $kind, $job) => $publisher->send($club, $payload, $job),
                        $limit,
                        ['meta'],
                        $nativeClub,
                    ) : [],
                    'transport' => $canSend ? 'enabled' : 'disabled',
                ];
                WorkerRuns::finish($db, $nativeClub, $workerRun, $result);
                return $result;
            }
            if(isset($options['mail-replies'])&&$nativeClub!==null){
                $result=['worker'=>'native-mail-replies','mail_replies'=>\Kulturhus\Native\Communication\Mail\Bridge::receive($db,$box,$nativeClub),'transport'=>'configured-mail-replies','deliveries'=>[]];
                WorkerRuns::finish($db,$nativeClub,$workerRun,$result);return $result;
            }
            if (isset($options["social-check"]) && $nativeClub !== null) {
                $webhookProcessor=new \Kulturhus\Native\Communication\MetaWebhookProcessor($db,$box,$metaHttp);
                $result = [
                    "worker" => "native-social-check",
                    "webhook_checks" => (new \Kulturhus\Native\Communication\MetaWebhookWorker($db,$box))->run($nativeClub,$webhookProcessor->read(...),min(100,$limit)),
                    "social_checks" => \Kulturhus\Native\Communication\Checks::run(
                        $db,
                        $box,
                        $nativeClub,
                    ),
                    "transport" => "read-only",
                    "deliveries" => [],
                ];
                WorkerRuns::finish($db, $nativeClub, $workerRun, $result);
                return $result;
            }
            $publications = [
                "published" => 0,
                "conflicted" => 0,
                "blocked" => 0,
                "failed" => 0,
            ];
            if ($canPublish) {
                foreach (
                    \Kulturhus\Native\Cms\PublicationSchedule::runDue(
                        $db,
                        limit: $limit,
                        club: $nativeClub,
                    )
                    as $publication
                ) {
                    if (
                        !array_key_exists($publication["status"], $publications)
                    ) {
                        throw new DomainException(
                            "Unbekanntes Veröffentlichungsergebnis prüfen.",
                        );
                    }
                    ++$publications[$publication["status"]];
                }
            }
            $reminders = \Kulturhus\Native\Communication\ConfirmationReminders::run(
                $db,
                $box,
                100,
                $nativeClub,
            );
            $drafts = Campaigns::runDigests($db, $box, null, $nativeClub);
            $eventDrafts = ['created'=>0, 'ready'=>0, 'review'=>0, 'failed'=>0];
            $eventClubs = Db::run($db, 'SELECT club_id FROM native_social_event_preparation_settings WHERE enabled=1'.($nativeClub !== null ? ' AND club_id=?' : ''), $nativeClub !== null ? [$nativeClub] : [])->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($eventClubs as $eventClub) {
                try {
                    $prepared = (new \Kulturhus\Native\Communication\EventPreparations($db, getenv('KH_NATIVE_MANAGER_MEDIA_ROOT') ?: dirname($key).'/media'))->run($eventClub, min(3, $limit));
                    foreach ($eventDrafts as $name=>$count) $eventDrafts[$name] += $prepared[$name];
                } catch (DomainException) { ++$eventDrafts['review']; }
            }
            $deliveries = [];
            $suggestions = 0;
            $members = null;
            $accountMails = null;
            $ticketMails = null;
            $factorMails = null;
            $originals = null;
            foreach (["newsletter", "social", "press"] as $module) {
                $table = \Kulturhus\Native\Communication\Schema::table(
                    $module,
                    "automations",
                );
                $plans = Db::run(
                    $db,
                    "SELECT DISTINCT club_id,actor_id,authority FROM $table WHERE status='enabled' AND due_at<=UTC_TIMESTAMP()" .
                        ($nativeClub !== null ? " AND club_id=?" : ""),
                    $nativeClub !== null ? [$nativeClub] : [],
                )->fetchAll();
                foreach ($plans as $plan) {
                    try {
                        $access = \Kulturhus\Native\Shared\Authority::resolve(
                            $db,
                            [
                                "created_by" => $plan["actor_id"],
                                "authority" => $plan["authority"],
                                "club_id" => $plan["club_id"],
                            ],
                        );
                        $access->require($module, "manage");
                        $suggestions += (new \Kulturhus\Native\Communication\Workspace(
                            $db,
                            $access,
                            $module,
                        ))->runAutomations(gmdate("Y-m-d H:i:s"));
                    } catch (DomainException) {
                    }
                }
            }
            $mail = new MailAccount(
                $db,
                $box,
                isset($managerKeyFile)
                    ? static fn() => ServerKey::nativeRuntime($managerKeyFile)
                    : null,
            );
            if (!isset($options["fixture"])) {
                $membershipSend = $canSend
                    ? static fn(array $message) => $mail->send(
                        $nativeClub,
                        $message,
                        "membership",
                    )
                    : null;
                $invoiceSend = $canSend
                    ? static fn(array $message) => $mail->send(
                        $nativeClub,
                        $message,
                        "contributions",
                    )
                    : null;
                $auth = \Kulturhus\Native\Auth\Runtime::service(
                    $db,
                    $authKeyFile,
                );
                $factorMails = $auth->deliverEmailFactors(
                    $nativeClub,
                    $canSend
                        ? static fn(array $message) => $mail->send(
                            $nativeClub,
                            $message,
                            "mfa",
                        )
                        : null,
                );
                $accountMails = \Kulturhus\Native\Auth\Mail::run(
                    $db,
                    $box,
                    $nativeClub,
                    $canSend
                        ? static fn(array $message) => $mail->send(
                            $nativeClub,
                            $message,
                            "password_reset",
                        )
                        : null,
                );
                $ticketMails = \Kulturhus\Native\Events\Tickets\Mail::run(
                    $db, $box, $nativeClub,
                    $canSend ? static fn(array $message) => $mail->send($nativeClub, $message, "tickets") : null,
                );
                $members = \Kulturhus\Native\Integration\MemberDelivery::run(
                    $db,
                    $auth,
                    $box,
                    $originalClub,
                    $origin,
                    $membershipSend,
                    $invoiceSend,
                );
                $originals = OriginalJobs::run(
                    $db,
                    $originalClub,
                    $origin,
                    ServerKey::nativeRuntime($managerKeyFile),
                    $box,
                    send: $canSend
                        ? static fn(
                            string $purpose,
                            array $message,
                        ) => $mail->send($nativeClub, $message, $purpose)
                        : null,
                    receive: isset($options["receive"]),
                    push: isset($options["push"]),
                    workerActor: $workerActor,
                    automationStart: $automationStart,
                    reminderSend: $canSend
                        ? (new \Kulturhus\Verein\Finance\Invoices\Reminder\ReminderTransport($db, ServerKey::nativeRuntime($managerKeyFile)))->send(...)
                        : null,
                );
            }
            if ($canSend) {
                $deliveries = (new Outbox($db, $box))->run(
                    static fn($club, $payload, $kind) => $mail->send(
                        $club,
                        $payload,
                        $kind==='press'?'press':'newsletter',
                    ),
                    $limit,
                    ["confirm", "reminder", "renewal", "campaign", "test", "press"],
                    $nativeClub,
                );
            }
            $result = [
                "worker" => "native-delivery",
                "drafts" => count($drafts),
                "reminders" => count($reminders),
                "suggestions" => $suggestions,
                "event_drafts" => $eventDrafts,
                "deliveries" => $deliveries,
                "members" => $members,
                "account_mails" => $accountMails,
                "ticket_mails" => $ticketMails,
                "factor_mails" => $factorMails,
                "original_jobs" => $originals,
                "publications" => $publications,
                "publication" => $canPublish ? "enabled" : "disabled",
                "transport" => $canSend ? "enabled" : "disabled",
            ];
            if ($workerRun !== null) {
                WorkerRuns::finish($db, $nativeClub, $workerRun, $result);
            }
            return $result;
        } catch (Throwable $failure) {
            if ($workerRun !== null) {
                try {
                    WorkerRuns::finish($db, $nativeClub, $workerRun, null);
                } catch (Throwable) {
                }
            }
            throw $failure;
        }
    }
}
