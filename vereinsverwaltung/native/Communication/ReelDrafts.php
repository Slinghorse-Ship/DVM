<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;
use PDO;
use Kulturhus\Native\Shared\Access;

/** The content save and encoded video each retain their existing actor-bound receipt. */
final class ReelDrafts
{
    public function __construct(private PDO $db, private Access $access, private string $mediaRoot) {}

    public function prepare(string $item, int $version, string $animation, string $requestKey): array
    {
        Content::id($item); ReelRenderer::animation($animation);
        if ($version < 1 || $version >= 4294967295 || !preg_match('/\A[a-f0-9]{32}\z/D', $requestKey)) throw new DomainException('Ursprüngliches Videoformular öffnen.');
        $this->access->require('social', 'edit');
        $workspace = new Workspace($this->db, $this->access, 'social', $this->mediaRoot);
        $basis = $workspace->revision($item, $version);
        if ($basis['kind'] !== 'reel' || !in_array($basis['status'], ['draft', 'review'], true)) throw new DomainException('Aktuellen Reel-Entwurf öffnen.');
        $data = Content::document('social', $basis);
        unset($data['audience'], $data['recipient_ids']);
        $data['id'] = $item; $data['status'] = 'draft'; $data['video_design'] = ['animation'=>$animation];
        $saved = (new EditionSaveRequests($this->db, $this->access, $this->mediaRoot, 'social'))->save($data, $version, $requestKey);
        (new ReelRenderer($this->db, $this->access, $this->mediaRoot))->render($item, $saved['version'], $animation, $requestKey);
        return $this->review($item, $requestKey) ?? throw new DomainException('Video noch nicht gespeichert.');
    }

    public function review(string $item, string $requestKey): ?array
    {
        Content::id($item);
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $requestKey)) throw new DomainException('Ursprüngliches Videoformular öffnen.');
        return (new VideoSaveRequests($this->db, $this->access, $this->mediaRoot))->review($item, $requestKey);
    }
}
