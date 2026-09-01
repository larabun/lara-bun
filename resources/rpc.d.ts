/**
 * Call a function registered by the host application from a React Server
 * Component, during RSC rendering.
 *
 * On Laravel the name must be registered in config/bun.php under rsc.callables,
 * or auto-discovered from rsc.callables_dir (e.g. "UserCallable.getUser").
 */
declare function rpc<T = unknown>(functionName: string, args?: Record<string, unknown>): Promise<T>;

/**
 * Page metadata for RSC pages.
 *
 * @example
 * ```tsx
 * export const metadata: Metadata = {
 *   title: 'My Page',
 *   description: 'Page description',
 *   keywords: ['react', 'laravel'],
 * };
 * ```
 */
interface IconDescriptor {
  url: string | URL;
  type?: string;
  sizes?: string;
  color?: string;
  rel?: string;
  media?: string;
  fetchPriority?: 'high' | 'low' | 'auto';
}

type IconURL = string | URL;

interface Icons {
  icon?: IconURL | IconDescriptor | (IconURL | IconDescriptor)[];
  apple?: IconURL | IconDescriptor | (IconURL | IconDescriptor)[];
  shortcut?: IconURL | IconDescriptor | (IconURL | IconDescriptor)[];
  other?: IconDescriptor | IconDescriptor[];
}

interface Metadata {
  title?: string;
  description?: string;
  keywords?: string | string[];
  author?: string;
  robots?: string;
  icons?: IconURL | (IconURL | IconDescriptor)[] | Icons | null;
  'og:title'?: string;
  'og:description'?: string;
  'og:image'?: string;
  'og:url'?: string;
  'og:type'?: string;
  'og:site_name'?: string;
  'twitter:card'?: string;
  'twitter:title'?: string;
  'twitter:description'?: string;
  'twitter:image'?: string;
  'twitter:site'?: string;
  [key: string]: string | string[] | Icons | IconURL | (IconURL | IconDescriptor)[] | null | undefined;
}

type GenerateMetadata<P = Record<string, string>> = (params: P) => Metadata | Promise<Metadata>;
