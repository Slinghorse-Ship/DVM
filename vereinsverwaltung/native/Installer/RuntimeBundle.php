<?php declare(strict_types=1);
namespace Kulturhus\Native\Installer;

/** Prepare the existing, checksum-verified OCR artifacts on the build machine. */
final class RuntimeBundle
{
    private const COMPONENTS = [
        "pdf-python",
        "ocr",
        "ocr-models",
        "heic",
        "glibc-compat-2.36",
    ];

    public static function build(
        string $source,
        string $target,
        array $verified,
    ): array {
        $store = new \PackageStore($source . "/distribution");
        $root = $target . "/app/runtime";
        $stage = $target . "/private/runtime-stage";
        \PackageStore::directory($root);
        \PackageStore::directory($stage);
        $sourceIds = [];
        $selected = [];
        foreach ($verified["linux"]["components"] as $component) {
            if (!in_array($component["id"], self::COMPONENTS, true)) {
                continue;
            }
            $archive = $store->materialize($component, $stage);
            $install = $component["install"];
            \PackageStore::extract(
                $archive,
                $root . "/" . \PackageStore::relative($install["target"]),
                $install["type"],
                (int) ($install["strip_components"] ?? 0),
            );
            unlink($archive);
            $sourceIds = array_merge(
                $sourceIds,
                $component["source_ids"] ?? [],
            );
            $selected[] = $component;
        }
        if (array_diff(self::COMPONENTS, array_column($selected, "id"))) {
            throw new \DomainException("Vollständiges OCR-Paket erforderlich.");
        }
        rmdir($stage);
        $sources = [];
        foreach ($verified["linux"]["sources"] as $entry) {
            if (!in_array($entry["id"], $sourceIds, true)) {
                continue;
            }
            foreach ($entry["parts"] as $part) {
                $relative = \PackageStore::relative($part["file"]);
                $input = fopen(
                    $source .
                        "/distribution/packages/linux-runtime/" .
                        $relative,
                    "rb",
                );
                try {
                    Deployment::copy(
                        $input,
                        $root . "/distribution/" . $relative,
                        $part,
                        0600,
                    );
                } finally {
                    fclose($input);
                }
            }
            $sources[] = $entry;
        }
        if (array_diff($sourceIds, array_column($sources, "id"))) {
            throw new \DomainException("OCR-Lizenzquellen fehlen.");
        }
        foreach (
            array_merge(
                $verified["source"]["files"],
                $verified["source"]["installer_files"],
            )
            as $entry
        ) {
            $prefix = "distribution/packages/linux-runtime/";
            if (!str_starts_with($entry["path"], $prefix)) {
                continue;
            }
            $name = substr($entry["path"], strlen($prefix));
            if (
                !str_starts_with($name, "LICENSES/") &&
                !str_starts_with($name, "provenance/") &&
                $name !== "README.md"
            ) {
                continue;
            }
            $bytes = (string) file_get_contents($source . "/" . $entry["path"]);
            if ($entry["normalized_lf"]) {
                $bytes = str_replace("\r\n", "\n", $bytes);
            }
            Deployment::file($root . "/distribution/" . $name, $bytes);
        }
        Deployment::file(
            $root . "/distribution/manifest.json",
            json_encode(
                [
                    "format" => 1,
                    "components" => $selected,
                    "sources" => $sources,
                    "requires" => [
                        "Linux x86_64",
                        "proc_open",
                        "Landlock ABI 6 and seccomp",
                        "executable private filesystem",
                    ],
                ],
                JSON_THROW_ON_ERROR |
                    JSON_PRETTY_PRINT |
                    JSON_UNESCAPED_SLASHES,
            ) . "\n",
        );
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS,
            ),
        );
        foreach ($iterator as $file) {
            if ($file->isLink() || !$file->isFile()) {
                throw new \DomainException(
                    "OCR-Paket darf keine Links oder Spezialdateien enthalten.",
                );
            }
            $files[] = [
                "path" =>
                    "runtime/" .
                    substr($file->getPathname(), strlen($root) + 1),
                "bytes" => $file->getSize(),
                "sha256" => hash_file("sha256", $file->getPathname()),
                "normalized_lf" => false,
                "executable" => ($file->getPerms() & 0111) !== 0,
            ];
        }
        return $files;
    }
}
