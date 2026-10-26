<?php

namespace App\Site\Data;

final readonly class BuildResult
{
    /**
     * @param  list<string>  $files  paths relative to $dir that were rendered (assets are copied separately)
     */
    public function __construct(
        public string $dir,
        public array $files,
    ) {}
}
