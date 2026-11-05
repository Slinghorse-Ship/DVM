<?php declare(strict_types=1);
namespace Kulturhus\Native\System;
final class RequestRejected extends \DomainException
{
    public function __construct(string $message,int $status=422){parent::__construct($message,$status);}
}
