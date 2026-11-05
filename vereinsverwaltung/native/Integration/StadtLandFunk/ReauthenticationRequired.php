<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration\StadtLandFunk;

/** Only an explicitly expired/revoked refresh grant permits one fresh account login. */
final class ReauthenticationRequired extends \DomainException {}
