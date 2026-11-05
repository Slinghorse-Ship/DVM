<?php
declare(strict_types=1);

namespace Kulturhus\Native\Web;

/** Fixed same-site content target; queries remain attached to their original link. */
final class PathRedirect
{
    public static function target(
        string $slug,
        string $request,
        bool $demo = false,
    ): string {
        if (
            !preg_match(
                "~\A[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*\z~D",
                $slug,
            ) ||
            strlen($slug) > 180
        ) {
            throw new \DomainException("Ungültiges Weiterleitungsziel.");
        }
        $target = $demo
            ? "/site/" . $slug
            : ($slug === "start"
                ? "/"
                : "/" . $slug . "/");
        $query = parse_url($request, PHP_URL_QUERY);
        if (
            is_string($query) &&
            $query !== "" &&
            strlen($query) <= 8192 &&
            !preg_match('/[\x00-\x20\x7f]/', $query)
        ) {
            $target .= "?" . $query;
        }
        return $target;
    }
}
