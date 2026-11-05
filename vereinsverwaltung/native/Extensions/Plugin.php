<?php declare(strict_types=1);
namespace Kulturhus\Native\Extensions;

interface Plugin
{
    public function definition(): Definition;
    public function install(Context $context): void;
    public function upgrade(Context $context, string $previousVersion): void;
    public function uninstall(Context $context): void;
    /** Read-only initialization; callbacks are restricted to host-owned contracts. */
    public function initialize(HookScope $hooks, UrlScope $urls, Context $context): void;
}
