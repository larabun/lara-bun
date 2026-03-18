"use client";

import {
  type AnchorHTMLAttributes,
  type MouseEvent,
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState,
} from "react";

type PrefetchStrategy = "hover" | "mount" | "click" | "none" | boolean;

interface LinkProps extends Omit<AnchorHTMLAttributes<HTMLAnchorElement>, "href"> {
  href: string;
  prefetch?: PrefetchStrategy;
  cacheFor?: number;
  replace?: boolean;
  preserveScroll?: boolean;
}

const LinkStatusContext = createContext<{ pending: boolean }>({ pending: false });

export function useLinkStatus(): { pending: boolean } {
  return useContext(LinkStatusContext);
}

function isExternalUrl(url: string): boolean {
  try {
    return new URL(url, window.location.origin).origin !== window.location.origin;
  } catch {
    return false;
  }
}

function shouldInterceptClick(e: MouseEvent<HTMLAnchorElement>): boolean {
  return (
    !e.defaultPrevented &&
    e.button === 0 &&
    !e.metaKey &&
    !e.ctrlKey &&
    !e.shiftKey &&
    !e.altKey
  );
}

export default function Link({
  href,
  prefetch: prefetchProp = "hover",
  cacheFor,
  replace = false,
  preserveScroll = false,
  children,
  onClick,
  onMouseEnter,
  ...rest
}: LinkProps) {
  const [pending, setPending] = useState(false);

  const prefetchStrategy = prefetchProp === true
    ? "hover"
    : prefetchProp === false
      ? "none"
      : prefetchProp;

  const doPrefetch = useCallback(() => {
    if (isExternalUrl(href)) return;
    const fn = (window as any).__rsc_prefetch;
    fn?.(href, cacheFor);
  }, [href, cacheFor]);

  // Only useEffect needed: prefetch on mount strategy
  useEffect(() => {
    if (prefetchStrategy === "mount") {
      doPrefetch();
    }
  }, [prefetchStrategy, doPrefetch]);

  const handleClick = useCallback(
    (e: MouseEvent<HTMLAnchorElement>) => {
      onClick?.(e);

      if (e.defaultPrevented) return;

      const target = (e.currentTarget as HTMLAnchorElement).target;
      if (target && target !== "_self") return;
      if (!shouldInterceptClick(e) || isExternalUrl(href)) return;

      e.preventDefault();
      setPending(true);

      // navigate() returns a Promise — clear pending when it resolves or rejects
      const nav = (window as any).__rsc_navigate;
      const promise = nav?.(href, { replace, preserveScroll });
      promise?.then(
        () => setPending(false),
        () => setPending(false),
      );
    },
    [href, replace, preserveScroll, onClick]
  );

  const handleMouseEnter = useCallback(
    (e: MouseEvent<HTMLAnchorElement>) => {
      onMouseEnter?.(e);
      if (prefetchStrategy === "hover" || prefetchStrategy === "click") {
        doPrefetch();
      }
    },
    [prefetchStrategy, doPrefetch, onMouseEnter]
  );

  const handleTouchStart = useCallback(() => {
    if (prefetchStrategy === "hover" || prefetchStrategy === "click") {
      doPrefetch();
    }
  }, [prefetchStrategy, doPrefetch]);

  return (
    <LinkStatusContext.Provider value={{ pending }}>
      <a
        href={href}
        onClick={handleClick}
        onMouseEnter={handleMouseEnter}
        onTouchStart={handleTouchStart}
        data-pending={pending ? "" : undefined}
        {...rest}
      >
        {children}
      </a>
    </LinkStatusContext.Provider>
  );
}
