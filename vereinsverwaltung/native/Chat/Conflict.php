<?php declare(strict_types=1);
namespace Kulturhus\Native\Chat;

/** The caller must reload and deliberately resolve an optimistic-write conflict. */
final class Conflict extends \DomainException {}
