<?php

namespace LaravelRsc;

/**
 * What a server action invalidated.
 *
 * An action knows what it changed; the page does not. Marking it here lets the
 * answer to the action carry the re-rendered parts with it, so the browser
 * does not have to ask a second time for something the server already knew.
 *
 * Targets are names the page understands — a parallel slot, or `page` for the
 * page below its layouts. Marking nothing revalidates nothing, which is the
 * right default: most actions return what changed and the caller sets it.
 */
class Revalidation
{
    /** @var list<string> */
    private array $targets = [];

    /**
     * Mark something as needing to be rendered again.
     *
     * Called from an action, during the request that action is part of.
     */
    public function mark(string ...$targets): void
    {
        foreach ($targets as $target) {
            if (! in_array($target, $this->targets, true)) {
                $this->targets[] = $target;
            }
        }
    }

    /** @return list<string> */
    public function targets(): array
    {
        return $this->targets;
    }

    /**
     * Take what has been marked and forget it.
     *
     * Consumed once per callback response: the worker is told what this call
     * invalidated, and a later call in the same action starts clean.
     */
    public function flush(): array
    {
        $targets = $this->targets;
        $this->targets = [];

        return $targets;
    }
}
