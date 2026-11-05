<?php declare(strict_types=1);

// Isolated service/queue checks. ProcessWire storage and every mail transport are in-memory doubles.
namespace ProcessWire {
    class ProcessWire {
        public object $pages;
        public object $cache;
        public object $database;
        public object $user;
        public function __construct() {
            $this->pages = new class {
                public function get(string $selector): Page {
                    if ($selector === '/system/newsletter-empfaenger/') return new Page(['id' => 1]);
                    preg_match('/email_hash=([a-f0-9]{64})/', $selector, $match);
                    return Page::$saved[$match[1] ?? ''] ?? new Page();
                }
            };
            $this->cache = new class {
                public array $values = [];
                public function get(array $keys): array { return array_intersect_key($this->values, array_flip($keys)); }
                public function save(string $key, mixed $value, int $ttl): bool { $this->values[$key] = $value; return true; }
            };
            $this->database = new class {
                public function prepare(string $sql): object { return new class {
                    public function execute(array $args): void {}
                    public function fetchColumn(): int { return 1; }
                }; }
            };
            $this->user = new class { public function isSuperuser(): bool { return false; } };
        }
    }
    class Page {
        public const statusHidden = 1024;
        public static array $saved = [];
        public static bool $failSave = false;
        private array $data;
        public function __construct(array $data = []) { $this->data = $data + ['id' => 0]; }
        public function __get(string $key): mixed { return $this->data[$key] ?? null; }
        public function __set(string $key, mixed $value): void { $this->data[$key] = $value; }
        public function of(bool $value): void {}
        public function addStatus(int $status): void {}
        public function save(?string $field = null): void {
            if (self::$failSave) throw new \RuntimeException('Synthetic storage failure');
            $this->data['id'] = $this->data['id'] ?: count(self::$saved) + 2;
            self::$saved[$this->data['email_hash']] = $this;
        }
    }
}
namespace Symfony\Component\Mime {
    class Address { public function __construct(...$arguments) {} }
    class Email { public function __call(string $name, array $arguments): self { return $this; } }
}
namespace Symfony\Component\Mailer {
    class Transport {
        public static bool $failBuild = false;
        public static function fromDsn(string $dsn): self {
            if (self::$failBuild) throw new \RuntimeException('Synthetic transport configuration failure');
            return new self();
        }
    }
    class Mailer {
        public static int $calls = 0;
        public static bool $failSend = false;
        public function __construct(Transport $transport) {}
        public function send(object $message): void {
            self::$calls++;
            if (self::$failSend) throw new \RuntimeException('Synthetic uncertain send');
        }
    }
}
namespace Kulturhus {
    class AppSecret { public static function value(object $wire): string { return str_repeat('test-only-', 5); } }
    class NewsletterCampaignService {
        public function __construct(object $wire) {}
        public function storeRecipientUnsubscribeToken(int $id, string $token): void {}
    }
    // The local PHP test runtime lacks mbstring. Test inputs are deliberately ASCII.
    function mb_strtolower(string $value): string { return strtolower($value); }
    function mb_substr(string $value, int $start, int $length): string { return substr($value, $start, $length); }
}
namespace {
    require __DIR__ . '/../processwire/site/src/MailDeliveryPolicy.php';
    require __DIR__ . '/../processwire/site/src/NewsletterService.php';
    require __DIR__ . '/../processwire/site/src/MembershipNotificationDelivery.php';
    require __DIR__ . '/../vereinsverwaltung/src/bootstrap.php';
    use Kulturhus\{NewsletterService, MembershipNotificationDelivery};
    use Kulturhus\Verein\{Database, DocumentCatalog};
    use ProcessWire\{ProcessWire, Page};
    use Symfony\Component\Mailer\{Mailer, Transport};
    $n = 0;
    function ok(bool $value, string $label): void { global $n; if (!$value) throw new \RuntimeException($label); $n++; echo "PASS $label\n"; }
    function denied(callable $fn, string $label): void { try { $fn(); } catch (\RuntimeException) { ok(true, $label); return; } throw new \RuntimeException($label); }
    foreach (['KULTURHUS_LOCAL_DEMO', 'KULTURHUS_DISABLE_MAIL', 'APP_ENV', 'APP_URL'] as $env) putenv($env);
    putenv('SMTP_DSN=synthetic://never-connect'); putenv('APP_URL=https://example.invalid');
    $wire = new ProcessWire(); $service = new NewsletterService($wire);
    for ($i = 1; $i <= 12; $i++) {
        $result = $service->requestConfirmedApplicationSubscription("member$i@example.invalid", 'Test', md5("application-$i"));
        ok(!empty($result['delivered']), "confirmed application $i gets its own quota");
    }
    ok(Mailer::$calls === 12 && !array_filter(array_keys($wire->cache->values), fn($key) => str_starts_with($key, 'newsletter-signup-ip-')), 'internal applications never consume shared IP quota');
    $recipient = reset(Page::$saved); $recipient->recipient_status = 'confirmed';
    $before = $wire->cache->values; putenv('SMTP_DSN');
    $result = $service->requestConfirmedApplicationSubscription('member1@example.invalid', 'Test', md5('already-confirmed'));
    ok(!empty($result['already_confirmed']) && $wire->cache->values === $before && Mailer::$calls === 12, 'existing confirmed subscriber bypasses transport and quota');
    putenv('SMTP_DSN=synthetic://never-connect');
    for ($i = 0; $i < 3; $i++) $service->requestConfirmedApplicationSubscription('limited@example.invalid', 'Test', md5("limited-$i"));
    $beforeCalls = Mailer::$calls;
    ok(!empty($service->requestConfirmedApplicationSubscription('limited@example.invalid', 'Test', md5('limited-four'))['retryable']) && Mailer::$calls === $beforeCalls, 'internal email quota still prevents repeated DOI messages');
    $publicWire = new ProcessWire(); $public = new NewsletterService($publicWire);
    for ($i = 0; $i < 8; $i++) $public->requestSubscription("public$i@example.invalid", 'Test', ['news'], ['ip' => '192.0.2.10', 'website' => '']);
    denied(fn() => $public->requestSubscription('public9@example.invalid', 'Test', ['news'], ['ip' => '192.0.2.10', 'website' => '']), 'public signup still enforces eight requests per IP');
    $publicEmail = new NewsletterService(new ProcessWire());
    for ($i = 0; $i < 3; $i++) $publicEmail->requestSubscription('public-limit@example.invalid', 'Test', ['news'], ['ip' => "192.0.2.$i", 'website' => '']);
    denied(fn() => $publicEmail->requestSubscription('public-limit@example.invalid', 'Test', ['news'], ['ip' => '192.0.2.99', 'website' => '']), 'public signup still enforces three requests per email');
    Page::$failSave = true; $beforeCalls = Mailer::$calls;
    ok(!empty($service->requestConfirmedApplicationSubscription('storage@example.invalid', 'Test', md5('storage'))['retryable']) && Mailer::$calls === $beforeCalls, 'storage failure is safely retryable before transport');
    Page::$failSave = false; Transport::$failBuild = true;
    ok(!empty($service->requestConfirmedApplicationSubscription('config@example.invalid', 'Test', md5('config'))['retryable']) && Mailer::$calls === $beforeCalls, 'transport construction failure is safely retryable');
    Transport::$failBuild = false; Mailer::$failSend = true;
    denied(fn() => $service->requestConfirmedApplicationSubscription('uncertain@example.invalid', 'Test', md5('uncertain')), 'failure after entering transport remains uncertain');
    Mailer::$failSend = false;

    $db = Database::connect(':memory:'); Database::migrate($db); $db->exec("INSERT INTO clubs VALUES('test','Synthetic');");
    $add = static function(string $name, bool $confirmed = true, bool $optin = true) use ($db): string {
        $id = md5($name);
        DocumentCatalog::query($db, 'INSERT INTO application_confirmations(id,club_id,request_key,email,payload,configuration_version,consent,newsletter,token_hash,created_at,expires_at,confirmed_at,application_id,newsletter_status) VALUES(?,?,?,?,?,1,1,?,?,1700000000,1900000000,?,?,?)', [$id, 'test', $id, "$name@example.invalid", '{"first_name":"Test"}', (int) $optin, hash('sha256', $id), $confirmed ? 1700000001 : null, md5("app-$name"), 'pending']);
        return $id;
    };
    $state = static fn(string $id): string => DocumentCatalog::query($db, 'SELECT newsletter_status FROM application_confirmations WHERE id=?', [$id])->fetchColumn();
    $id = $add('retry'); $calls = 0;
    $retry = static function() use (&$calls): array { $calls++; return ['retryable' => true]; };
    MembershipNotificationDelivery::dispatchNewsletterRequests($db, 'test', $retry, 1800000000);
    ok($calls === 1 && $state($id) === 'retry:1800000300:1', 'first safe failure waits five minutes');
    MembershipNotificationDelivery::dispatchNewsletterRequests($db, 'test', $retry, 1800000299);
    ok($calls === 1, 'no repeated processing before due time');
    MembershipNotificationDelivery::dispatchNewsletterRequests($db, 'test', $retry, 1800000300);
    ok($calls === 2 && $state($id) === 'retry:1800002100:2', 'second safe failure waits thirty minutes');
    MembershipNotificationDelivery::dispatchNewsletterRequests($db, 'test', $retry, 1800002100);
    MembershipNotificationDelivery::dispatchNewsletterRequests($db, 'test', $retry, 1800010000);
    ok($calls === 3 && $state($id) === 'failed_before_send', 'three safe failures stop automatic retries');
    $id = $add('smtp');
    MembershipNotificationDelivery::dispatchNewsletterRequests($db, 'test', static function() { throw new \RuntimeException('Unknown SMTP outcome'); }, 1800000000);
    MembershipNotificationDelivery::dispatchNewsletterRequests($db, 'test', $retry, 1800010000);
    ok($state($id) === 'uncertain' && $calls === 3, 'uncertain transport is never automatically retried');
    $id = $add('recovered'); MembershipNotificationDelivery::dispatchNewsletterRequests($db, 'test', $retry, 1800000000);
    MembershipNotificationDelivery::dispatchNewsletterRequests($db, 'test', static fn() => ['delivered' => true], 1800000300);
    ok($state($id) === 'done', 'safe deferred retry can recover');
    $unconfirmed = $add('unconfirmed', false); $noOptin = $add('no-optin', true, false);
    MembershipNotificationDelivery::dispatchNewsletterRequests($db, 'test', $retry, 1800010000);
    ok($state($unconfirmed) === 'pending' && $state($noOptin) === 'pending', 'unconfirmed and non-opt-in applications never enter newsletter delivery');
    $id = $add('local'); $beforeCalls = Mailer::$calls; $beforeQueueCalls = $calls;
    putenv('KULTURHUS_LOCAL_DEMO=1');
    denied(fn() => $service->requestConfirmedApplicationSubscription('local@example.invalid', 'Test', md5('local')), 'internal subscription preserves local mail block');
    MembershipNotificationDelivery::dispatchNewsletterRequests($db, 'test', $retry, 1800010000);
    ok($state($id) === 'pending' && $calls === $beforeQueueCalls && Mailer::$calls === $beforeCalls, 'local queue makes no storage or transport transition');
    echo "$n membership/newsletter checks passed; in-memory doubles only, no mail sent\n";
}
