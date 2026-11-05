<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Banking\Notice;

use Kulturhus\Verein\Settings\GeneralDraft;

final class DebitDraft
{
    public static function section(string $entry): string { return 'debit-notice.'.hash('sha256', $entry); }
    public static function revision(array $draft): string
    {
        return hash('sha256', DebitRequests::json(['key'=>$draft['key'], 'input'=>$draft['input'],
            'review'=>$draft['review']['hash'] ?? null, 'pending'=>$draft['pending'], 'accepted'=>$draft['accepted']]));
    }
    public static function open(array &$session, array $actor, array $input, bool $fresh = false): array
    {
        return GeneralDraft::open($session, $actor, self::section($input['entry']), DebitInput::capture($input), $fresh);
    }
    public static function get(array $session, array $actor, string $entry, mixed $id): array
    {
        return GeneralDraft::get($session, $actor, self::section($entry), $id);
    }
    public static function store(array &$session, array $draft): void { $session['general_settings_drafts'][$draft['id']] = $draft; }
    public static function replace(array $draft, array $input, bool $newKey = false): array
    {
        return GeneralDraft::replace($draft, DebitInput::capture($input), $newKey);
    }
    public static function complete(array $draft, string $key): array
    {
        $draft['pending'] = false;
        $draft['accepted'] = $key;
        $draft['review'] = null;
        $draft['previous'] = [];
        return $draft;
    }
    public static function discard(array &$session, array $draft): void { GeneralDraft::discard($session, $draft); }
}
