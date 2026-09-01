<?php

namespace LaravelRsc\Rsc;

use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class PageScanner
{
    /** @var list<PageDefinition> */
    protected array $pages = [];

    public function __construct(
        protected string $appDir,
    ) {}

    public function scan(): void
    {
        $this->pages = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->appDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if (! preg_match('/^page\.(tsx|ts|jsx|js)$/', $file->getFilename())) {
                continue;
            }

            // Skip pages inside intercept directories — they are only used as interceptors
            $relativePath = $this->relativePath($file->getRealPath());

            if ($this->isInsideInterceptDir($relativePath)) {
                continue;
            }

            $this->pages[] = $this->buildDefinition($file);
        }

        // Collect all intercept routes and match them to target pages
        $allIntercepts = $this->collectAllInterceptRoutes();

        foreach ($this->pages as $page) {
            foreach ($allIntercepts as $intercept) {
                if ($page->urlPattern === $intercept['interceptedUrl']) {
                    $page->interceptRoutes[] = $intercept;
                }
            }
        }

        usort($this->pages, fn (PageDefinition $a, PageDefinition $b) => strcmp($a->urlPattern, $b->urlPattern));
    }

    /**
     * @return list<PageDefinition>
     */
    public function getPages(): array
    {
        return $this->pages;
    }

    protected function buildDefinition(SplFileInfo $file): PageDefinition
    {
        $pageDir = dirname($file->getRealPath());
        $relativePath = $this->relativePath($file->getRealPath());
        $relativeDir = dirname($relativePath);

        if ($relativeDir === '.') {
            $relativeDir = '';
        }

        $componentName = 'app/'.preg_replace('/\.(tsx|ts|jsx|js)$/', '', $relativePath);
        $segments = $relativeDir !== '' ? explode('/', $relativeDir) : [];

        $urlSegments = [];
        foreach ($segments as $segment) {
            // Strip route groups: (groupName) → no URL segment
            if (preg_match('/^\(.*\)$/', $segment)) {
                continue;
            }

            // Strip parallel routes: @slotName → no URL segment
            if (str_starts_with($segment, '@')) {
                continue;
            }

            $urlSegments[] = $this->convertSegment($segment);
        }

        $urlPattern = $urlSegments !== [] ? implode('/', $urlSegments) : '/';
        $isDynamic = (bool) preg_match('/\{[^}]+\}/', $urlPattern);
        $layouts = $this->collectLayouts($pageDir);
        $loadings = $this->collectLoadings($pageDir);
        $parallelSlots = $this->collectParallelSlots($pageDir);
        $routeConfigPath = $this->findRouteConfig($pageDir);
        $directoryConfigPaths = $this->collectDirectoryConfigs($pageDir);

        return new PageDefinition(
            componentName: $componentName,
            urlPattern: $urlPattern,
            layouts: $layouts,
            loadings: $loadings,
            parallelSlots: $parallelSlots,
            isDynamic: $isDynamic,
            routeConfigPath: $routeConfigPath,
            directoryConfigPaths: $directoryConfigPaths,
        );
    }

    /**
     * Convert a directory segment to a Laravel route parameter.
     *
     * [slug] → {slug}
     * [...path] → {path}
     * about → about
     */
    protected function convertSegment(string $segment): string
    {
        // Catch-all: [...param]
        if (preg_match('/^\[\.\.\.(\w+)\]$/', $segment, $matches)) {
            return '{'.$matches[1].'}';
        }

        // Dynamic: [param]
        if (preg_match('/^\[(\w+)\]$/', $segment, $matches)) {
            return '{'.$matches[1].'}';
        }

        return $segment;
    }

    /**
     * Walk up from page directory to app root, collecting layout files.
     * Returns outermost-first (app/layout before app/docs/layout).
     *
     * @return list<string>
     */
    protected function collectLayouts(string $pageDir): array
    {
        $layouts = [];
        $current = $pageDir;
        $appDirReal = realpath($this->appDir);

        while (true) {
            $layout = $this->findLayout($current);

            if ($layout !== null) {
                $layoutRelative = $this->relativePath($layout);
                $componentName = 'app/'.preg_replace('/\.(tsx|ts|jsx|js)$/', '', $layoutRelative);
                $layouts[] = $componentName;
            }

            if (realpath($current) === $appDirReal) {
                break;
            }

            $parent = dirname($current);

            if ($parent === $current) {
                break;
            }

            $current = $parent;
        }

        // Reverse so outermost (root) layout is first
        return array_reverse($layouts);
    }

    /**
     * Walk up from page directory to app root, collecting loading files.
     * Returns outermost-first (app/loading before app/docs/loading).
     *
     * @return list<string>
     */
    protected function collectLoadings(string $pageDir): array
    {
        $loadings = [];
        $current = $pageDir;
        $appDirReal = realpath($this->appDir);

        while (true) {
            $loading = $this->findLoading($current);

            if ($loading !== null) {
                $loadingRelative = $this->relativePath($loading);
                $componentName = 'app/'.preg_replace('/\.(tsx|ts|jsx|js)$/', '', $loadingRelative);
                $loadings[] = $componentName;
            }

            if (realpath($current) === $appDirReal) {
                break;
            }

            $parent = dirname($current);

            if ($parent === $current) {
                break;
            }

            $current = $parent;
        }

        return array_reverse($loadings);
    }

    /**
     * Scan the page's parent directories for @slot parallel route directories.
     * Each @slot directory that contains a page file becomes a named slot.
     *
     * @return array<string, string> Map of slot name → component name
     */
    protected function collectParallelSlots(string $pageDir): array
    {
        $slots = [];
        $current = $pageDir;
        $appDirReal = realpath($this->appDir);

        // Walk up to find the nearest layout's directory, check for @slots there
        while (true) {
            // Scan for @slot directories at this level
            if (is_dir($current)) {
                $entries = scandir($current);

                foreach ($entries as $entry) {
                    if (! str_starts_with($entry, '@')) {
                        continue;
                    }

                    $slotDir = $current.'/'.$entry;

                    if (! is_dir($slotDir)) {
                        continue;
                    }

                    $slotName = substr($entry, 1); // Remove @ prefix
                    $slotPage = $this->findPage($slotDir);

                    if ($slotPage !== null) {
                        $slotRelative = $this->relativePath($slotPage);
                        $slots[$slotName] = 'app/'.preg_replace('/\.(tsx|ts|jsx|js)$/', '', $slotRelative);
                    }
                }
            }

            if (realpath($current) === $appDirReal) {
                break;
            }

            $parent = dirname($current);

            if ($parent === $current) {
                break;
            }

            $current = $parent;
        }

        return $slots;
    }

    /**
     * Check if a page file lives inside an intercept pattern directory.
     * e.g. @modal/(.)photo/[id]/page.tsx → true
     */
    protected function isInsideInterceptDir(string $relativePath): bool
    {
        $segments = explode('/', dirname($relativePath));

        foreach ($segments as $segment) {
            if (preg_match('/^\(\.{1,3}\)/', $segment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Scan all @slot directories in the app tree for intercept routes.
     *
     * @return list<array{slot: string, component: string, interceptedUrl: string}>
     */
    protected function collectAllInterceptRoutes(): array
    {
        $intercepts = [];
        $appDirReal = realpath($this->appDir);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->appDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isDir()) {
                continue;
            }

            if (! str_starts_with($file->getFilename(), '@')) {
                continue;
            }

            $slotDir = $file->getRealPath();
            $slotName = substr($file->getFilename(), 1);
            $parentDir = dirname($slotDir);

            $slotIntercepts = $this->scanInterceptPatterns($slotDir, $parentDir, $slotName);
            $intercepts = array_merge($intercepts, $slotIntercepts);
        }

        return $intercepts;
    }

    /**
     * Detect route interception patterns inside @slot directories.
     * Returns a map of intercepted URL patterns → slot component names.
     *
     * Convention:
     *   (.)folder  — intercepts at the same segment level
     *   (..)folder — intercepts one level up
     *   (...)folder — intercepts from the app root
     *
     * @return array<string, array{slot: string, component: string, interceptedUrl: string}>
     */
    protected function collectInterceptRoutes(string $pageDir): array
    {
        $intercepts = [];
        $current = $pageDir;
        $appDirReal = realpath($this->appDir);

        while (true) {
            if (is_dir($current)) {
                $entries = scandir($current);

                foreach ($entries as $entry) {
                    if (! str_starts_with($entry, '@')) {
                        continue;
                    }

                    $slotDir = $current.'/'.$entry;

                    if (! is_dir($slotDir)) {
                        continue;
                    }

                    $slotName = substr($entry, 1);
                    $slotIntercepts = $this->scanInterceptPatterns($slotDir, $current, $slotName);
                    $intercepts = array_merge($intercepts, $slotIntercepts);
                }
            }

            if (realpath($current) === $appDirReal) {
                break;
            }

            $parent = dirname($current);

            if ($parent === $current) {
                break;
            }

            $current = $parent;
        }

        return $intercepts;
    }

    /**
     * @return array<string, array{slot: string, component: string, interceptedUrl: string}>
     */
    private function scanInterceptPatterns(string $slotDir, string $parentDir, string $slotName): array
    {
        $intercepts = [];
        $entries = scandir($slotDir);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryPath = $slotDir.'/'.$entry;

            if (! is_dir($entryPath)) {
                continue;
            }

            // Detect intercept prefixes: (.), (..), (...)
            $prefix = null;
            $rest = null;

            if (preg_match('/^\(\.{1,3}\)(.+)$/', $entry, $matches)) {
                $prefix = substr($entry, 0, strpos($entry, ')') + 1);
                $rest = $matches[1];
            }

            if ($prefix === null) {
                continue;
            }

            // Find page.tsx recursively under this intercept directory
            $this->findPagesRecursive($entryPath, $parentDir, $prefix, $rest, $slotName, $intercepts);
        }

        return $intercepts;
    }

    private function findPagesRecursive(
        string $dir,
        string $parentDir,
        string $prefix,
        string $pathAfterPrefix,
        string $slotName,
        array &$intercepts
    ): void {
        $page = $this->findPage($dir);

        if ($page !== null) {
            $relativePage = $this->relativePath($page);
            $componentName = 'app/'.preg_replace('/\.(tsx|ts|jsx|js)$/', '', $relativePage);

            // Calculate the intercepted URL based on the prefix
            $appDirReal = realpath($this->appDir);
            $parentRelative = str_replace($appDirReal.'/', '', realpath($parentDir));

            if ($parentRelative === realpath($appDirReal)) {
                $parentRelative = '';
            }

            $parentSegments = $parentRelative !== '' ? explode('/', $parentRelative) : [];

            // Strip route groups from parent segments
            $parentSegments = array_values(array_filter($parentSegments, fn ($s) => ! preg_match('/^\(.*\)$/', $s)));

            // Calculate base URL based on intercept prefix
            if ($prefix === '(.)') {
                // Same level — use parent segments as-is
                $baseSegments = $parentSegments;
            } elseif ($prefix === '(..)') {
                // One level up
                array_pop($parentSegments);
                $baseSegments = $parentSegments;
            } else {
                // (...) — from root
                $baseSegments = [];
            }

            // Build the remaining path from the intercept dir structure
            $interceptDir = $dir;
            $remainingPath = $pathAfterPrefix;

            // Convert remaining path segments
            $remainingSegments = explode('/', $remainingPath);
            $urlSegments = [];

            foreach ($remainingSegments as $seg) {
                if (preg_match('/^\(.*\)$/', $seg)) {
                    continue;
                }

                $urlSegments[] = $this->convertSegment($seg);
            }

            $allSegments = array_merge($baseSegments, $urlSegments);
            $interceptedUrl = $allSegments !== [] ? implode('/', $allSegments) : '/';

            $intercepts[] = [
                'slot' => $slotName,
                'component' => $componentName,
                'interceptedUrl' => $interceptedUrl,
            ];
        }

        // Check subdirectories for nested pages
        $entries = scandir($dir);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || ! is_dir($dir.'/'.$entry)) {
                continue;
            }

            $subPath = $pathAfterPrefix.'/'.$entry;
            $this->findPagesRecursive($dir.'/'.$entry, $parentDir, $prefix, $subPath, $slotName, $intercepts);
        }
    }

    protected function findPage(string $dir): ?string
    {
        foreach (['tsx', 'ts', 'jsx', 'js'] as $ext) {
            $path = $dir.'/page.'.$ext;

            if (file_exists($path)) {
                return $path;
            }

            // Also check for default.tsx (used for parallel route defaults)
            $defaultPath = $dir.'/default.'.$ext;

            if (file_exists($defaultPath)) {
                return $defaultPath;
            }
        }

        return null;
    }

    protected function findLoading(string $dir): ?string
    {
        foreach (['tsx', 'ts', 'jsx', 'js'] as $ext) {
            $path = $dir.'/loading.'.$ext;

            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    protected function findLayout(string $dir): ?string
    {
        foreach (['tsx', 'ts', 'jsx', 'js'] as $ext) {
            $path = $dir.'/layout.'.$ext;

            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Find route.php in the same directory as the page file.
     */
    protected function findRouteConfig(string $pageDir): ?string
    {
        $path = $pageDir.'/route.php';

        return file_exists($path) ? $path : null;
    }

    /**
     * Walk up from page directory, collecting ancestor route.php files
     * (excluding the page's own route.php). Outermost first.
     *
     * @return list<string>
     */
    protected function collectDirectoryConfigs(string $pageDir): array
    {
        $configs = [];
        $current = dirname($pageDir);
        $appDirReal = realpath($this->appDir);

        while (true) {
            $configPath = $current.'/route.php';

            if (file_exists($configPath)) {
                $configs[] = $configPath;
            }

            if (realpath($current) === $appDirReal) {
                break;
            }

            $parent = dirname($current);

            if ($parent === $current) {
                break;
            }

            $current = $parent;
        }

        // Reverse so outermost ancestor is first
        return array_reverse($configs);
    }

    /**
     * Get relative path from app directory.
     */
    protected function relativePath(string $absolutePath): string
    {
        $appDirReal = realpath($this->appDir).'/';

        return Str::after($absolutePath, $appDirReal);
    }
}
