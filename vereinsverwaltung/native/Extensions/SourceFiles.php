<?php declare(strict_types=1);
namespace Kulturhus\Native\Extensions;

/** Reviewed host modules declare every helper and asset used by their registered services. */
interface SourceFiles
{
    /** Absolute local files below the module's source directory; never supplied by HTTP. */
    public function sourceFiles(): array;
}
