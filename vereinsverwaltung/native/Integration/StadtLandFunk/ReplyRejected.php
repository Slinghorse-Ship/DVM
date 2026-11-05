<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration\StadtLandFunk;

/** Complete known client rejections; ambiguous responses are never replayed. */
final class ReplyRejected extends \DomainException {}
