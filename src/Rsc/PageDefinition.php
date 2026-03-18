<?php

namespace LaraBun\Rsc;

class PageDefinition
{
    /**
     * @param  list<string>  $layouts
     * @param  list<string>  $loadings
     * @param  array<string, string>  $parallelSlots  Map of slot name → component name
     * @param  list<string>  $directoryConfigPaths
     */
    public function __construct(
        public string $componentName,
        public string $urlPattern,
        public array $layouts,
        public array $loadings,
        public array $parallelSlots,
        public bool $isDynamic,
        public ?string $routeConfigPath,
        public array $directoryConfigPaths,
    ) {}
}
