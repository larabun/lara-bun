<?php

namespace RscKit;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The renderer is not answering.
 *
 * Thrown rather than returned so it renders as Laravel renders anything else
 * that goes wrong: the debug page while developing, with the message and a
 * stack trace, and an ordinary 502 in production.
 *
 * 502 specifically. This application is fine — it is the thing behind it that
 * is not answering — and a 500 would send whoever reads the log looking here.
 */
class RendererNotRunningException extends RuntimeException implements HttpExceptionInterface
{
    public function __construct(private string $url)
    {
        parent::__construct(sprintf(
            'The RSC renderer is not running at %s. Run `npm run dev` to start it '
                .'(or `bun run dev`), or set RSC_RENDERER_URL if it runs somewhere else.',
            $url,
        ));
    }

    public function getStatusCode(): int
    {
        return 502;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }

    public function url(): string
    {
        return $this->url;
    }
}
