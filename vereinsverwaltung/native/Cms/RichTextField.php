<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use Kulturhus\Native\Shared\RichText;

/** Canonical formatted value and derived visible text for declarative fields. */
final class RichTextField
{
    public static function value(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                "Formatierter Text muss gültiger UTF-8-Text sein.",
            );
        }
        try {
            $safe = RichText::sanitize($value);
        } catch (\DomainException $error) {
            throw new \InvalidArgumentException($error->getMessage());
        }
        return self::plain($safe) === "" ? "" : $safe;
    }

    public static function plain(string $html): string
    {
        return preg_replace("/\A\s+|\s+\z/u", "", RichText::plain($html));
    }
}
