<?php declare(strict_types=1);
namespace Kulturhus\Verein\Communication;

/** A human form still belongs to an earlier saved revision. */
final class MailboxConflict extends \DomainException {}
