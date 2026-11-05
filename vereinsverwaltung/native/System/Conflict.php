<?php declare(strict_types=1);
namespace Kulturhus\Native\System;
final class Conflict extends \DomainException
{public function __construct(){parent::__construct('Systemeinstellung inzwischen geändert. Aktuellen Stand ausdrücklich neu öffnen.');}}
