<?php declare(strict_types=1);
namespace Kulturhus\Verein\DocumentWorkflow;

require_once dirname(__DIR__).'/Documents/DocumentPreview.php';

/** Uses the existing bounded, private stdin/stdout PDF runtime; never downloads external resources. */
final class WorkflowPdf
{
    public static function render(array $data): string
    {
        return \Kulturhus\Verein\DocumentPreview::pdf(['document_workflow' => $data]);
    }

    public static function validate(string $bytes): void
    {
        try { self::render(['validate_attachment' => base64_encode($bytes)]); }
        catch (\DomainException $error) { throw new WorkflowException('Diese PDF kann nicht angehängt werden. Bitte eine unbeschädigte PDF ohne Passwort und aktive Inhalte verwenden.', 0, $error); }
    }
}
