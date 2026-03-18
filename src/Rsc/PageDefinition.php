<?php

namespace LaraBun\Rsc;

class PageDefinition
{
    /**
     * @param  list<string>  $layouts
     * @param  list<string>  $loadings
     * @param  array<string, string>  $parallelSlots  Map of slot name → component name
     * @param  list<string>  $directoryConfigPaths
     * @param  list<array{slot: string, component: string, interceptedUrl: string}>  $interceptRoutes
     */
    public function __construct(
        public string $componentName,
        public string $urlPattern,
        public array $layouts = [],
        public array $loadings = [],
        public array $parallelSlots = [],
        public bool $isDynamic = false,
        public ?string $routeConfigPath = null,
        public array $directoryConfigPaths = [],
        public array $interceptRoutes = [],
    ) {}
}
