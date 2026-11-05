<?php declare(strict_types=1);

// Isolated regression checks: no ProcessWire bootstrap, database writes or live content.
namespace ProcessWire {
    class WirePermissionException extends \RuntimeException {}
    class Page {
        public const statusUnpublished = 2048;
        public const statusTrash = 8192;
        public const statusFlagged = 128;
        public array $data;
        public array $metadata = [];
        public object $services;
        public bool $allowPublish = true;
        public function __construct(array $data = []) {
            $this->data = $data + ['id' => 77, 'template' => (object) ['name' => 'news'], 'title' => 'Open Air', 'name' => 'neue-website-neuigkeit', 'parent_id' => 40, 'status' => self::statusUnpublished, 'workflow_status' => 'published', 'publish_at' => 1000, 'channels' => 'website,rss', 'body' => '<p>Ein Abend mit Musik.</p>', 'summary' => ''];
            $this->services = (object) [
                'input' => (object) ['post' => (object) ['submit_save' => null, 'submit_publish' => null], 'get' => (object) ['field' => null, 'fields' => null]],
                'user' => new class { public bool $allowed = true; public function isSuperuser(): bool { return false; } public function hasPermission(...$args): bool { return $this->allowed; } },
                'sanitizer' => new class { public function pageName(string $value, bool $beautify): string { return trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $value)), '-'); } },
                'pages' => new class { public array $occupied = []; public function get(string $selector): object { foreach ($this->occupied as $name) if (str_contains($selector, ', name=' . $name . ',')) return (object) ['id' => 88]; return (object) ['id' => 0]; } },
            ];
        }
        public function __get(string $key) { return $this->data[$key] ?? null; }
        public function __set(string $key, $value): void { $this->data[$key] = $value; }
        public function wire(): object { return $this->services; }
        public function getUnformatted(string $key) { return $this->__get($key); }
        public function isUnpublished(): bool { return $this->hasStatus(self::statusUnpublished); }
        public function isTrash(): bool { return $this->hasStatus(self::statusTrash); }
        public function hasStatus(int $status): bool { return ((int) $this->status & $status) !== 0; }
        public function addStatus(int $status): void { $this->status |= $status; }
        public function removeStatus(int $status): void { $this->status &= ~$status; }
        public function publishable(): bool { return $this->allowPublish; }
        public function editable(): bool { return true; }
        public function meta(string $key = '', $value = null) {
            if ($key === '') return new class($this) { public function __construct(private Page $page) {} public function remove(string $key): void { unset($this->page->metadata[$key]); } };
            if ($value === null) return $this->metadata[$key] ?? null;
            return $this->metadata[$key] = $value;
        }
    }
}

namespace {
    require $argv[1] ?? dirname(__DIR__) . '/processwire/site/src/NewsPublication.php';
    use Kulturhus\NewsPublication;
    use ProcessWire\Page;

    $checks = 0;
    $expect = static function (bool $condition, string $message) use (&$checks): void {
        $checks++;
        if (!$condition) throw new \RuntimeException($message);
    };
    $prepare = new \ReflectionMethod(NewsPublication::class, 'prepareSave');
    $pending = static function (array $data = []): Page {
        $page = new Page($data);
        $page->meta(NewsPublication::PENDING_META, true);
        return $page;
    };
    $public = new Page(['status' => 1]);
    $expect(NewsPublication::isPublic($public, 'website', 2000), 'Ready website news must be public.');
    $expect(NewsPublication::isPublic($public, 'rss', 2000), 'Selected RSS channel must be public.');
    foreach ([['status' => Page::statusUnpublished], ['status' => Page::statusTrash], ['workflow_status' => 'draft'], ['workflow_status' => 'review'], ['publish_at' => 3000], ['channels' => ''], ['channels' => 'notwebsite,rss'], ['title' => NewsPublication::PLACEHOLDER_TITLE]] as $override) {
        $expect(!NewsPublication::isPublic(new Page($override + ['status' => 1]), 'website', 2000), 'Non-public state must not leak: ' . json_encode($override));
    }
    $first = $pending();
    $prepare->invoke(null, $first, true);
    $expect(!$first->isUnpublished() && $first->name === 'open-air', 'First complete save must publish with the title slug.');
    $summaryOnly = $pending(['body' => '', 'summary' => 'Kurze Nachricht']);
    $prepare->invoke(null, $summaryOnly, true);
    $expect(!$summaryOnly->isUnpublished(), 'A summary is sufficient actual content.');
    $empty = $pending(['body' => '<p>&nbsp;</p>', 'summary' => '']);
    $prepare->invoke(null, $empty, true);
    $expect($empty->isUnpublished(), 'Visually empty content must remain private.');
    $keep = $pending();
    $keep->wire()->input->post->submit_save = 'Save';
    $prepare->invoke(null, $keep, true);
    $expect($keep->isUnpublished() && !$keep->meta(NewsPublication::PENDING_META), 'Explicit keep-unpublished must cancel automatic publication.');
    $draft = $pending(['workflow_status' => 'draft']);
    $prepare->invoke(null, $draft, true);
    $expect($draft->isUnpublished() && !$draft->meta(NewsPublication::PENDING_META), 'An explicit draft must remain a draft.');
    $future = $pending(['publish_at' => 3000]);
    $prepare->invoke(null, $future, true);
    $expect(!NewsPublication::isPublic($future, 'website', 4000), 'Pending content remains private until custom fields have successfully persisted.');
    $future->meta()->remove(NewsPublication::PENDING_META);
    $expect(!$future->isUnpublished() && !NewsPublication::isPublic($future, 'website', 2000) && NewsPublication::isPublic($future, 'website', 4000), 'Scheduled content must become available only when due.');
    $editor = $pending();
    $editor->wire()->user->allowed = false;
    $prepare->invoke(null, $editor, true);
    $expect($editor->isUnpublished(), 'An editor without content-publish must never auto-publish.');
    $blocked = new Page(['status' => 1]);
    $blocked->wire()->user->allowed = false;
    $denied = false;
    try { $prepare->invoke(null, $blocked, true); } catch (\ProcessWire\WirePermissionException) { $denied = true; }
    $expect($denied, 'Changing public content requires publishing rights.');
    $inline = $pending();
    $inline->wire()->input->post->submit_save = 'Save';
    $prepare->invoke(null, $inline, false);
    $expect(!$inline->isUnpublished() && $inline->name === 'open-air', 'Field-save follow-up must use the same publication and slug rules.');
    $singleFieldForm = $pending(['body' => '', 'summary' => '']);
    $singleFieldForm->wire()->input->post->submit_save = 'Save';
    $singleFieldForm->wire()->input->get->fields = 'title';
    $prepare->invoke(null, $singleFieldForm, true);
    $expect($singleFieldForm->isUnpublished() && $singleFieldForm->meta(NewsPublication::PENDING_META), 'Saving only title in a field editor must not cancel the pending article workflow.');
    $stable = new Page(['name' => 'existing-url', 'title' => 'New Title']);
    $prepare->invoke(null, $stable, true);
    $expect($stable->name === 'existing-url', 'Later title changes must keep existing URLs stable.');
    $collision = $pending();
    $collision->wire()->pages->occupied = ['open-air', 'open-air-2'];
    $prepare->invoke(null, $collision, true);
    $expect($collision->name === 'open-air-3', 'Slug collisions must get a unique numeric suffix.');
    echo $checks . " news publication regression checks passed.\n";
}
