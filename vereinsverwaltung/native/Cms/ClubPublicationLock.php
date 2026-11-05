<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;
use Kulturhus\Native\Shared\Db;

/** The same club mutex protects changes to both content and its field/template model. */
trait ClubPublicationLock
{
    private function lockClub():void
    {
        Db::run($this->db,'INSERT INTO khcms_state (club_id,generation) VALUES (?,0) ON DUPLICATE KEY UPDATE generation=generation',[$this->access->club()]);
        Db::run($this->db,'SELECT generation FROM khcms_state WHERE club_id=? FOR UPDATE',[$this->access->club()])->fetchColumn();
    }
}
