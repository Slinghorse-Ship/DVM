<?php declare(strict_types=1);
require dirname(__DIR__, 2) . "/native/bootstrap.php";
require dirname(__DIR__, 2) . "/src/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\{Pages, Media, DataModel};
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\Integration\ManagerBridge;
$standalone = ($argv[1] ?? "") === "standalone";
$config = TestSupport::config();
if (
    PHP_SAPI !== "cli" ||
    ($standalone
        ? realpath((string) getenv("KH_NATIVE_TEST_CONFIG")) !==
                "/workspace/.setup/native-demo.json" ||
            ($config["label"] ?? "") !== "demo" ||
            ($config["owner"] ?? "") !== "cloud-onboarding-synthetic"
        : ($config["label"] ?? "") !== "integrated-manager")
) {
    throw new RuntimeException("Own synthetic versions fixture required.");
}
$db = TestSupport::db();
$access = $standalone
    ? new Access($db, "admin")
    : (new ManagerBridge(
        $db,
        "manager-admin",
        "native",
        static fn() => null,
    ))->access();
$runtime =
    dirname(getenv("KH_NATIVE_TEST_CONFIG")) .
    ($standalone
        ? "/runtime-" . $config["database"]
        : "/integrated-manager-runtime");
$pages = new Pages($db, $access);
$model = new DataModel($db, $access);
$prefix = "browser_version_";
$fields = array_column($model->fields(), null, "key");
foreach (
    [
        "amount" => ["type" => "decimal"],
        "active" => ["type" => "boolean"],
        "formatted" => ["type" => "richtext"],
    ]
    as $suffix => $spec
) {
    $key = $prefix . $suffix;
    $input = ["key" => $key, "label" => "Own historical " . $suffix] + $spec;
    if (!isset($fields[$key])) {
        $model->saveField($input);
    } elseif (
        !in_array(
            $fields[$key]["label"],
            [$input["label"], "Own changed formatted"],
            true,
        )
    ) {
        throw new RuntimeException("Unexpected version field.");
    } else {
        $model->saveField($input, $fields[$key]["version"]);
    }
}
$templateKey = $prefix . "template";
$templates = array_column($model->templates(), null, "key");
$templateInput = [
    "key" => $templateKey,
    "label" => "Own browser version template",
    "fields" => [$prefix . "amount", $prefix . "active", $prefix . "formatted"],
    "groups" => [
        [
            "key" => "own",
            "label" => "Own historical group",
            "fields" => [$prefix . "formatted"],
        ],
    ],
];
if (
    isset($templates[$templateKey]) &&
    $templates[$templateKey]["label"] !== $templateInput["label"]
) {
    throw new RuntimeException("Unexpected original version template.");
}
$template = $model->saveTemplate(
    $templateInput,
    $templates[$templateKey]["version"] ?? 0,
);
$media = new Media($db, $access, $runtime . "/media");
$images = [];
foreach (["a", "b"] as $suffix) {
    $name = "own-browser-versions-" . $suffix . ".png";
    $ids = Db::run(
        $db,
        "SELECT id FROM khcms_media WHERE club_id=? AND original_name=? AND alt=?",
        [$access->club(), $name, "Own versions picture " . $suffix],
    )->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) > 1) {
        throw new RuntimeException("Unexpected version pixels.");
    }
    if ($ids) {
        $images[] = $ids[0];
    } else {
        $pixels = imagecreatetruecolor(32, 24);
        imagefill(
            $pixels,
            0,
            0,
            imagecolorallocate($pixels, $suffix === "a" ? 24 : 180, 100, 80),
        );
        imagepng($pixels, $runtime . "/" . $name);
        unset($pixels);
        $images[] = $media->upload(
            $runtime . "/" . $name,
            $name,
            "Own versions picture " . $suffix,
        )["id"];
    }
}
$out = ["prefix" => $prefix, "images" => $images, "items" => []];
foreach (
    [
        "page" => [
            "template" => $templateKey,
            "slug" => "synthetic-versions-page",
            "view" => "website",
        ],
        "event" => [
            "template" => "event",
            "slug" => "synthetic-versions-event",
            "view" => "events",
        ],
        "news" => [
            "template" => "news",
            "slug" => "aktuelles/synthetic-versions-news",
            "view" => "news",
        ],
        "gallery" => [
            "template" => "gallery",
            "slug" => "galerie/synthetic-versions-gallery",
            "view" => "media",
        ],
    ]
    as $kind => $spec
) {
    $oldValues = [
        $prefix . "amount" => "999999999999999999.999997",
        $prefix . "active" => false,
        $prefix .
        "formatted" => "<p>Own historical <strong>format</strong> &lt;marker&gt;</p>",
    ];
    $baseline = [
        "title" => "Own browser versions " . $kind . " – original",
        "slug" => $spec["slug"],
        "template" => $spec["template"],
        "body_html" =>
            "<p>Own old <strong>body " . $kind . "</strong> &lt;marker&gt;</p>",
        "media_ids" => $images,
        "gallery_items" => [
            [
                "id" => $images[0],
                "caption" => "Own original first picture",
                "tags" => ["Original"],
            ],
            [
                "id" => $images[1],
                "caption" => "Own original second picture",
                "tags" => [],
            ],
        ],
        "nav_visible" => false,
    ];
    if ($kind === "page") {
        $baseline["custom_fields"] = $oldValues;
    }
    if ($kind === "news") {
        $baseline["news_date"] = "2026-10-10";
    }
    if ($kind === "event") {
        $baseline += [
            "event_start" => "2026-11-14T20:00",
            "event_end_local" => "2026-11-14T22:00",
            "event_location" => "Own old event location",
            "event_price" => "12.50",
        ];
    }
    $id = Db::run(
        $db,
        "SELECT id FROM khcms_pages WHERE club_id=? AND slug=?",
        [$access->club(), $spec["slug"]],
    )->fetchColumn();
    if ($id) {
        $old = $pages->get($id);
        $allowed = array_map(
            static fn($i) => "Own browser versions " . $kind . " – new " . $i,
            range(1, 30),
        );
        $allowed[] = $baseline["title"];
        if (!in_array($old["title"], $allowed, true)) {
            throw new RuntimeException("Unexpected original version page.");
        }
        $item = $pages->save(["id" => $id] + $baseline, $old["version"]);
    } else {
        $item = $pages->save($baseline);
    }
    $item = $pages->publish($item["id"], $item["version"]);
    $published = $item["version"];
    if ($kind === "page") {
        $definition = $model->field($prefix . "formatted");
        $model->saveField(
            array_replace($definition, ["label" => "Own changed formatted"]),
            $definition["version"],
        );
        $template = $model->saveTemplate(
            array_replace($templateInput, [
                "groups" => [
                    [
                        "key" => "own",
                        "label" => "Own changed <group>",
                        "fields" => [$prefix . "formatted"],
                        "columns" => 2,
                    ],
                ],
            ]),
            $template["version"],
        );
    }
    for ($i = 1; $i <= 30; $i++) {
        $input = array_replace($item, [
            "title" => "Own browser versions " . $kind . " – new " . $i,
            "body_html" =>
                "<p>Own new <em>body " . $kind . "</em> &lt;marker&gt;</p>",
        ]);
        if ($kind === "page") {
            $input["custom_fields"] = [
                $prefix . "amount" => "999999999999999999.999998",
                $prefix . "active" => true,
                $prefix .
                "formatted" => "<p>Own new <em>format</em> &lt;marker&gt;</p>",
            ];
        }
        if ($kind === "gallery") {
            $input["media_ids"] = array_reverse($images);
            $input["gallery_items"] = [
                [
                    "id" => $images[1],
                    "caption" => "Own changed second picture <literal>",
                    "tags" => ["Changed"],
                ],
                [
                    "id" => $images[0],
                    "caption" => "Own changed first picture",
                    "tags" => [],
                ],
            ];
        }
        if ($kind === "event") {
            $input["event_location"] = "Own new event location";
            $input["event_price"] = "12.51";
        }
        $item = $pages->save($input, $item["version"]);
    }
    $out["items"][] = [
        "kind" => $kind,
        "view" => $spec["view"],
        "id" => $item["id"],
        "current" => $item["version"],
        "published" => $published,
        "slug" => $spec["slug"],
        "old_title" => $baseline["title"],
        "new_title" => $item["title"],
        "old_values" => $kind === "page" ? $oldValues : [],
    ];
}
$path = $standalone
    ? dirname(__DIR__, 3) .
        "/.local/browser-tests/page-versions-standalone.json"
    : $runtime . "/page-versions-browser.json";
file_put_contents($path, json_encode($out, JSON_THROW_ON_ERROR));
chmod($path, 0600);
echo "Own four-renderer versions fixture ready; no provider actions." . "\n";
