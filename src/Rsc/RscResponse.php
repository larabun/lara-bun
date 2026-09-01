<?php

namespace LaraBun\Rsc;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use LaraBun\BunBridge;
use LaraBun\BunServiceProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RscResponse implements Responsable
{
    protected ?string $rootView = null;

    /** @var array<string, mixed> */
    protected array $viewData = [];

    protected ?string $version = null;

    protected int $statusCode = 200;

    /** @var list<array{component: string, props: array<string, mixed>}> */
    protected array $layouts = [];

    /** @var list<string> */
    protected array $loadingComponents = [];

    /** @var array<string, string> */
    protected array $parallelSlotComponents = [];

    /** @var array<string, array{component: string, props: array<string, mixed>}> */
    protected array $slotOverrides = [];

    /**
     * @param  array<string, mixed>  $props
     */
    public function __construct(
        protected string $component,
        protected array $props = [],
    ) {}

    /**
     * Wrap the page in a layout component. Layouts are ordered outermost-first:
     * `->layout('A')->layout('B')` produces `<A><B><Page /></B></A>`.
     *
     * Duplicate component names are ignored — first registration wins.
     *
     * @param  array<string, mixed>  $props
     */
    public function layout(string $component, array $props = []): static
    {
        foreach ($this->layouts as $existing) {
            if ($existing['component'] === $component) {
                return $this;
            }
        }

        $this->layouts[] = ['component' => $component, 'props' => $props];

        return $this;
    }

    /**
     * @param  list<string>  $loadings
     */
    public function loadings(array $loadings): static
    {
        $this->loadingComponents = $loadings;

        return $this;
    }

    public function getLoadings(): array
    {
        return $this->loadingComponents;
    }

    /**
     * @param  array<string, string>  $slots
     */
    public function parallelSlots(array $slots): static
    {
        $this->parallelSlotComponents = $slots;

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getParallelSlots(): array
    {
        return $this->parallelSlotComponents;
    }

    /**
     * Override a parallel slot with a different component and props.
     * Used for route interception — the interceptor replaces the default slot content.
     *
     * @param  array<string, mixed>  $props
     */
    public function overrideSlot(string $slot, string $component, array $props = []): static
    {
        $this->slotOverrides[$slot] = ['component' => $component, 'props' => $props];

        return $this;
    }

    /**
     * @return array<string, array{component: string, props: array<string, mixed>}>
     */
    public function getSlotOverrides(): array
    {
        return $this->slotOverrides;
    }

    public function rootView(string $rootView): static
    {
        $this->rootView = $rootView;

        return $this;
    }

    public function withViewData(string $key, mixed $value): static
    {
        $this->viewData[$key] = $value;

        return $this;
    }

    public function withProp(string $key, mixed $value): static
    {
        $this->props[$key] = $value;

        return $this;
    }

    public function version(string $version): static
    {
        $this->version = $version;

        return $this;
    }

    public function status(int $status): static
    {
        $this->statusCode = $status;

        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        $version = $this->version ?? $this->resolveVersion();

        // Only serve Flight payload for actual SPA fetches, not browser reloads.
        // Browser navigation (including tab duplicate/restore) sends Accept: text/html.
        // SPA fetch() sends Accept: */* with the X-RSC header.
        // Flight responses also include Cache-Control: no-store to prevent Chrome
        // from replaying cached Flight responses on tab duplicate/restore.
        $isRscRequest = $request->hasHeader(Header::X_RSC) || $request->hasHeader(Header::X_RSC_ACTION);
        $isBrowserNav = str_contains($request->header('Accept', ''), 'text/html');

        if ($isRscRequest && ! $isBrowserNav) {
            return $this->toStreamedRscResponse($version);
        }

        return $this->toStreamedHtmlResponse($version, $request);
    }

    /**
     * Stream the raw Flight payload for SPA navigation.
     * Uses chunked transfer encoding so React can progressively render
     * as Flight bytes arrive via createFromReadableStream(response.body).
     *
     * The payload is self-describing: @vitejs/plugin-rsc encodes client
     * references and stylesheet <link>s into the Flight stream itself, and
     * <title>/<meta> are rendered inside the tree for React 19 to hoist. So
     * the response carries no asset or metadata headers — only the version,
     * which RscMiddleware uses to force a reload after a redeploy.
     */
    protected function toStreamedRscResponse(string $version): StreamedResponse
    {
        $bridge = app(BunBridge::class);
        $generator = $bridge->rscStream($this->component, $this->props, $this->layouts, $this->loadingComponents, $this->parallelSlotComponents, $this->slotOverrides);

        // First yield is the stream-start frame — read it eagerly so headers
        // are settled before the body starts streaming. Page metadata lands in
        // viewData as defaults (route.php viewData takes precedence) for
        // prerendered HTML, which builds its own <head>.
        $meta = $generator->current();
        $this->applyMetadataDefaults($meta['metadata'] ?? null);

        $headers = [
            'Content-Type' => 'text/x-component',
            Header::X_RSC_VERSION => $version,
            'X-Accel-Buffering' => 'no',
        ];

        return new StreamedResponse(function () use ($generator): void {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $generator->next();

            while ($generator->valid()) {
                echo $generator->current();
                flush();
                $generator->next();
            }
        }, $this->statusCode, $headers);
    }

    /**
     * Stream the initial-load HTML.
     *
     * The worker returns a COMPLETE HTML document — the root layout renders
     * <html> and @vitejs/plugin-rsc injects the client bootstrap script + CSS
     * <link>s into the streamed markup, with Suspense completions streaming as
     * async content resolves. We stream it straight through.
     */
    protected function toStreamedHtmlResponse(string $version, Request $request): StreamedResponse
    {
        $bridge = app(BunBridge::class);
        $nonce = BunServiceProvider::cspNonce();
        $generator = $bridge->rscHtmlStream($this->component, $this->props, $this->layouts, $this->loadingComponents, $this->parallelSlotComponents, $this->slotOverrides, $nonce);

        // First yield: {clientChunks, metadata}
        $meta = $generator->current();
        $this->applyMetadataDefaults($meta['metadata'] ?? null);

        return new StreamedResponse(function () use ($generator): void {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $generator->next();

            while ($generator->valid()) {
                $value = $generator->current();

                // String yields are HTML chunks; the trailing {rscPayload} array
                // is skipped (the client hydrates from the RSC endpoint).
                if (! is_array($value)) {
                    echo $value;
                    flush();
                }

                $generator->next();
            }
        }, $this->statusCode, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function getComponent(): string
    {
        return $this->component;
    }

    /**
     * @return array<string, mixed>
     */
    public function getProps(): array
    {
        return $this->props;
    }

    /**
     * @return list<array{component: string, props: array<string, mixed>}>
     */
    public function getLayouts(): array
    {
        return $this->layouts;
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        return $this->viewData;
    }

    public function getVersion(): string
    {
        return $this->version ?? $this->resolveVersion();
    }

    /**
     * Apply metadata from the RSC bundle as viewData defaults.
     * Existing viewData (from route.php) takes precedence.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function applyMetadataDefaults(?array $metadata): void
    {
        if ($metadata === null) {
            return;
        }

        foreach ($metadata as $key => $value) {
            if (! isset($this->viewData[$key])) {
                $this->viewData[$key] = $value;
            }
        }
    }

    /**
     * Build HTML meta tags from viewData metadata keys.
     *
     * Recognises 'title' (→ <title>), 'description' (→ <meta name="description">),
     * and any 'og:*' / 'twitter:*' keys (→ <meta property="..."> / <meta name="...">).
     */
    public function buildMetaTags(): string
    {
        $tags = [];

        if (isset($this->viewData['title'])) {
            $tags[] = '    <title>'.e($this->viewData['title']).'</title>';
        }

        $metaKeys = ['description', 'author', 'robots'];

        foreach ($metaKeys as $key) {
            if (isset($this->viewData[$key]) && is_string($this->viewData[$key])) {
                $tags[] = '    <meta name="'.e($key).'" content="'.e($this->viewData[$key]).'">';
            }
        }

        // Keywords can be a string or array — join arrays with commas
        if (isset($this->viewData['keywords'])) {
            $keywords = is_array($this->viewData['keywords'])
                ? implode(', ', $this->viewData['keywords'])
                : $this->viewData['keywords'];
            $tags[] = '    <meta name="keywords" content="'.e($keywords).'">';
        }

        // Icons / favicon
        if (isset($this->viewData['icons'])) {
            $tags = [...$tags, ...$this->buildIconTags($this->viewData['icons'])];
        }

        foreach ($this->viewData as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'og:')) {
                $resolved = $this->isUrlMetaKey($key) ? $this->resolveMetaUrl($value) : $value;
                $tags[] = '    <meta property="'.e($key).'" content="'.e($resolved).'">';
            } elseif (str_starts_with($key, 'twitter:')) {
                $resolved = $this->isUrlMetaKey($key) ? $this->resolveMetaUrl($value) : $value;
                $tags[] = '    <meta name="'.e($key).'" content="'.e($resolved).'">';
            }
        }

        return implode("\n", $tags);
    }

    /**
     * Build <link> tags for icons/favicons from metadata.
     *
     * Supports:
     *   - string: icons: "/favicon.ico"
     *   - array: icons: [{ url: "/icon.png", sizes: "32x32" }]
     *   - object: icons: { icon: "/favicon.ico", apple: "/apple-touch-icon.png" }
     *
     * @return string[]
     */
    protected function buildIconTags(mixed $icons): array
    {
        $tags = [];

        if (is_string($icons)) {
            $tags[] = '    <link rel="icon" href="'.e($icons).'">';

            return $tags;
        }

        if (! is_array($icons)) {
            return $tags;
        }

        // Indexed array — list of icon descriptors or URLs
        if (array_is_list($icons)) {
            foreach ($icons as $icon) {
                $tags[] = $this->buildSingleIconTag($icon, 'icon');
            }

            return $tags;
        }

        // Associative array — keyed by category (icon, apple, shortcut, other)
        $relMap = [
            'icon' => 'icon',
            'apple' => 'apple-touch-icon',
            'shortcut' => 'shortcut icon',
        ];

        foreach ($relMap as $key => $rel) {
            if (! isset($icons[$key])) {
                continue;
            }

            $items = is_array($icons[$key]) && array_is_list($icons[$key])
                ? $icons[$key]
                : [$icons[$key]];

            foreach ($items as $icon) {
                $tags[] = $this->buildSingleIconTag($icon, $rel);
            }
        }

        // "other" — uses rel from the descriptor
        if (isset($icons['other'])) {
            $others = is_array($icons['other']) && array_is_list($icons['other'])
                ? $icons['other']
                : [$icons['other']];

            foreach ($others as $icon) {
                if (is_array($icon)) {
                    $tags[] = $this->buildSingleIconTag($icon, $icon['rel'] ?? 'icon');
                }
            }
        }

        return $tags;
    }

    /**
     * Build a single <link> tag for an icon.
     *
     * @param  string|array<string, mixed>  $icon
     */
    protected function buildSingleIconTag(string|array $icon, string $defaultRel): string
    {
        if (is_string($icon)) {
            return '    <link rel="'.e($defaultRel).'" href="'.e($this->resolveMetaUrl($icon)).'">';
        }

        $href = $this->resolveMetaUrl((string) ($icon['url'] ?? ''));
        $rel = $icon['rel'] ?? $defaultRel;
        $attrs = 'rel="'.e($rel).'" href="'.e($href).'"';

        if (! empty($icon['type'])) {
            $attrs .= ' type="'.e($icon['type']).'"';
        }

        if (! empty($icon['sizes'])) {
            $attrs .= ' sizes="'.e($icon['sizes']).'"';
        }

        if (! empty($icon['color'])) {
            $attrs .= ' color="'.e($icon['color']).'"';
        }

        if (! empty($icon['media'])) {
            $attrs .= ' media="'.e($icon['media']).'"';
        }

        if (! empty($icon['fetchPriority'])) {
            $attrs .= ' fetchpriority="'.e($icon['fetchPriority']).'"';
        }

        return '    <link '.$attrs.'>';
    }

    /**
     * Resolve a relative URL to an absolute URL using the app's URL.
     * Leaves absolute URLs (with a scheme) untouched.
     */
    protected function resolveMetaUrl(string $value): string
    {
        if ($value === '' || parse_url($value, PHP_URL_SCHEME) !== null) {
            return $value;
        }

        return url($value);
    }

    /**
     * Check if a meta key typically contains a URL that should be resolved.
     */
    protected function isUrlMetaKey(string $key): bool
    {
        return in_array($key, [
            'og:image',
            'og:url',
            'og:audio',
            'og:video',
            'twitter:image',
        ], true);
    }

    /**
     * Hash the client build so a redeploy invalidates in-flight SPA sessions.
     *
     * Must stay in step with RscMiddleware::version() — that compares the
     * client's X-RSC-Version against its own reading of the same directory,
     * so a divergence here 409s every navigation.
     */
    protected function resolveVersion(): string
    {
        $buildDir = config('bun.rsc.assets_dir', public_path('build/rsc-vite'));

        if (! is_dir($buildDir)) {
            return '';
        }

        $mtime = 0;

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($buildDir)) as $file) {
            if ($file->isFile() && $file->getMTime() > $mtime) {
                $mtime = $file->getMTime();
            }
        }

        return md5((string) $mtime);
    }
}
