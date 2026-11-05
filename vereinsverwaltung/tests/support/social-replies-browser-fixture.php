<?php declare(strict_types=1);
require __DIR__ . "/social-workspace-browser-fixture.php";
use Kulturhus\Native\Communication\Attention;
use Kulturhus\Native\Integration\Accounts;
use Kulturhus\Native\Shared\Db;

$current = (new Accounts($db, $box))->get($club, "meta");
Db::run(
    $db,
    "INSERT INTO native_social_objects(club_id,id,channel,remote_id,kind,caption,payload_json,last_seen) VALUES(?,?,'facebook','123_701','post','Synthetischer Antwort-Browsertermin',?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json)",
    [
        $club,
        substr(hash("sha256", "reply-browser-original"), 0, 32),
        json_encode(
            [
                "connection_id" => Accounts::binding($current),
                "thumbnail" => "",
                "permalink" => "",
            ],
            JSON_THROW_ON_ERROR,
        ),
    ],
);
(new Attention($db, $box))->observe(
    $access,
    "facebook",
    "comment",
    "123_701",
    [
        [
            "id" => "browser_reply_comment",
            "text" => "Gibt es einen barrierefreien Eingang?",
            "name" => "Synthetischer Besucher",
            "title" => "Synthetischer Antwort-Browsertermin",
        ],
    ],
    true,
);
$objectId = substr(hash("sha256", "reply-browser-original"), 0, 32);
Db::run(
    $db,
    "UPDATE native_social_objects SET comments_cipher=?,comments_at=UTC_TIMESTAMP() WHERE club_id=? AND id=?",
    [
        $box->seal("social-comments:" . $club . ":" . $objectId, [
            "comments" => [
                [
                    "id" => "browser_own_comment",
                    "text" => "Eigene Antwort aus dem Kommentarstrang.",
                    "name" => "Synthetischer Verein",
                    "created_at" => "2026-10-09 12:00:00",
                    "hidden" => false,
                    "incoming" => false,
                    "reply_to" => "",
                ],
                [
                    "id" => "browser_reply_comment",
                    "text" => "Gibt es einen barrierefreien Eingang?",
                    "name" => "Synthetischer Besucher",
                    "created_at" => "2026-10-09 12:05:00",
                    "hidden" => false,
                    "incoming" => true,
                    "reply_to" => "browser_own_comment",
                ],
            ],
        ]),
        $club,
        $objectId,
    ],
);
echo "Own synthetic Meta comment prepared for private reply UI; no provider request.\n";
