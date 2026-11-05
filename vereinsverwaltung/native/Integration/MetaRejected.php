<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration;

/** A complete Graph 4xx error is a rejection; network/5xx/unreadable writes remain ambiguous. */
final class MetaRejected extends \DomainException {}
