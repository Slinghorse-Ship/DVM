<?php declare(strict_types=1);
namespace Kulturhus\Native\System;
final class OperationUnconfirmed extends \RuntimeException
{public function __construct(\Throwable $previous){parent::__construct('Systemänderung nicht bestätigt. Aktuellen Stand neu öffnen und den ursprünglichen Auftrag prüfen.',0,$previous);}}
