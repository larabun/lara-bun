/**
 * Auto-discovers React Server Components, detects "use client" files,
 * and builds server + SSR + browser bundles with manifest generation.
 *
 * Usage:
 *   bun <this-script> [source-dir] [out-dir]
 *
 * Defaults:
 *   source-dir: resources/js/rsc
 *   out-dir:    bootstrap/rsc
 */

import { join, basename, dirname, resolve } from "node:path";
import { mkdirSync, writeFileSync, readFileSync, existsSync, rmSync } from "node:fs";
import type { BunPlugin } from "bun";

// ─── React Compiler Plugin ──────────────────────────────────────────────────
// Opt-in: install `babel-plugin-react-compiler` and `@babel/core` to enable.
// Transforms client components with the React Compiler for automatic memoization.

let reactCompilerPlugin: BunPlugin | null = null;

try {
  const babel = await import("@babel/core");
  await import("babel-plugin-react-compiler");

  reactCompilerPlugin = {
    name: "react-compiler",
    setup(build) {
      build.onLoad({ filter: /\.(tsx|jsx)$/ }, async (args) => {
        // Skip node_modules
        if (args.path.includes("node_modules")) {
          return undefined;
        }

        const source = readFileSync(args.path, "utf-8");

        // Only compile client components — server components run on the server
        // and don't benefit from the React Compiler's memoization
        const trimmed = source.trimStart();
        if (!trimmed.startsWith('"use client"') && !trimmed.startsWith("'use client'")) {
          return undefined;
        }

        const result = await babel.transformAsync(source, {
          filename: args.path,
          plugins: [["babel-plugin-react-compiler", {}]],
          presets: [
            ["@babel/preset-typescript", { isTSX: true, allExtensions: true }],
          ],
          parserOpts: {
            plugins: ["jsx", "typescript"],
          },
        });

        if (!result?.code) {
          return undefined;
        }

        // Babel strips the "use client" directive — re-add it so Bun's
        // bundler still recognises this as a client component
        const code = result.code.startsWith('"use client"')
          ? result.code
          : `"use client";\n${result.code}`;

        return {
          contents: code,
          loader: "jsx",
        };
      });
    },
  };

  console.log("React Compiler enabled — client components will be auto-optimized.");
} catch {
  // babel-plugin-react-compiler not installed — skip silently
}

const sourceDir = process.argv[2] ?? join(process.cwd(), "resources/js/rsc");
const outDir = process.argv[3] ?? join(process.cwd(), "bootstrap/rsc");

// Auto-create tsconfig.json if it doesn't exist — needed for php.d.ts global types
const tsconfigPath = join(process.cwd(), "tsconfig.json");
if (!existsSync(tsconfigPath)) {
  const phpDtsPath = existsSync(join(process.cwd(), "vendor/larabun/lara-bun/resources/php.d.ts"))
    ? "vendor/larabun/lara-bun/resources/php.d.ts"
    : join(resolve(join(import.meta.dir, "..")), "resources/php.d.ts");

  writeFileSync(tsconfigPath, JSON.stringify({
    compilerOptions: {
      allowJs: true,
      target: "ESNext",
      module: "ESNext",
      moduleResolution: "bundler",
      jsx: "react-jsx",
      strict: true,
      isolatedModules: true,
      esModuleInterop: true,
      skipLibCheck: true,
      forceConsistentCasingInFileNames: true,
      noEmit: true,
      verbatimModuleSyntax: true,
      paths: {
        "@/*": ["./resources/js/rsc/*"],
        "lara-bun/*": [
          existsSync(join(process.cwd(), "vendor/larabun/lara-bun/resources/js"))
            ? "./vendor/larabun/lara-bun/resources/js/*"
            : resolve(join(import.meta.dir, "..")) + "/resources/js/*",
        ],
      },
    },
    include: [
      "resources/**/*.ts",
      "resources/**/*.tsx",
      "resources/**/*.d.ts",
      phpDtsPath,
    ],
  }, null, 2) + "\n");
  console.log(`Generated: ${tsconfigPath}`);
}

// Auto-append RSC generated paths to .gitignore if not already present
const gitignorePath = join(process.cwd(), ".gitignore");
const rscIgnoreEntries = [
  "/bootstrap/rsc",
  "/public/build/rsc",
  "/public/build/css",
  "resources/js/rsc/routes.generated.ts",
  "resources/js/rsc/server-actions.generated.ts",
  "resources/js/rsc/env.d.ts",
  "storage/framework/rsc-dev",
  "storage/framework/rsc-static",
];

if (existsSync(gitignorePath)) {
  const gitignore = readFileSync(gitignorePath, "utf-8");
  const missing = rscIgnoreEntries.filter((e) => !gitignore.includes(e));

  if (missing.length > 0) {
    const block = "\n# LaraBun RSC (generated)\n" + missing.join("\n") + "\n";
    writeFileSync(gitignorePath, gitignore.trimEnd() + "\n" + block);
    console.log(`Updated .gitignore with ${missing.length} RSC entries`);
  }
}

// ─── Generate env.d.ts from .env ─────────────────────────────────────────────
// Reads the project's .env file and generates a TypeScript declaration file
// so process.env.* gets autocomplete in RSC components.

const dotEnvPath = join(process.cwd(), ".env");

if (existsSync(dotEnvPath)) {
  const envKeys = readFileSync(dotEnvPath, "utf-8")
    .split("\n")
    .map((line) => line.trim())
    .filter((line) => line && !line.startsWith("#"))
    .map((line) => line.split("=")[0].trim())
    .filter((key) => key.length > 0);

  if (envKeys.length > 0) {
    const envDtsSource = `// Auto-generated by lara-bun from .env — do not edit
declare namespace NodeJS {
  interface ProcessEnv {
${envKeys.map((k) => `    ${k}: string;`).join("\n")}
  }
}
`;
    mkdirSync(sourceDir, { recursive: true });
    const envDtsPath = join(sourceDir, "env.d.ts");
    writeFileSync(envDtsPath, envDtsSource);
    console.log(`Generated: ${envDtsPath} (${envKeys.length} env var(s))`);
  }
}

const clientOutDir = join(outDir, "client");
const browserOutDir = join(process.cwd(), "public/build/rsc");

// Resolve package directory for alias plugin and package client components
const packageDir = process.env.LARA_BUN_PACKAGE_DIR
  ?? resolve(join(import.meta.dir, ".."));
const packageJsDir = join(packageDir, "resources/js");

const glob = new Bun.Glob("**/*.{tsx,ts,jsx,js}");

interface ComponentInfo {
  name: string;
  importAlias: string;
  relativePath: string;
  absolutePath: string;
  isClient: boolean;
}

interface PageMetadataInfo {
  componentName: string;
  absolutePath: string;
  hasStatic: boolean;
  hasDynamic: boolean;
}

interface PageRouteInfo {
  /** URL pattern with original bracket syntax, e.g. "/docs/[slug]" */
  urlPattern: string;
  /** Dynamic segment names, e.g. ["slug"] */
  params: string[];
  /** Whether this has a catch-all segment */
  hasCatchAll: boolean;
}

interface RouteManifestEntry {
  urlPattern: string;
  staticPaths?: Record<string, string[]>;
  where?: Record<string, string[]>;
  baseUrl?: string;
  intercepts?: { slot: string; component: string }[];
}

const pageMetadata: PageMetadataInfo[] = [];
const pageRoutes: PageRouteInfo[] = [];

function detectMetadataExports(filePath: string): { hasStatic: boolean; hasDynamic: boolean } {
  try {
    const content = readFileSync(filePath, "utf-8");
    return {
      hasStatic: /export\s+const\s+metadata\b/.test(content),
      hasDynamic: /export\s+(async\s+)?function\s+generateMetadata\b/.test(content),
    };
  } catch {
    return { hasStatic: false, hasDynamic: false };
  }
}

const serverComponents: ComponentInfo[] = [];
const clientComponents: ComponentInfo[] = [];
let aliasIndex = 0;

function hasDefaultExport(filePath: string): boolean {
  try {
    const content = readFileSync(filePath, "utf-8");
    return /export\s+default\b/.test(content);
  } catch {
    return false;
  }
}

function isClientFile(filePath: string): boolean {
  try {
    const content = readFileSync(filePath, "utf-8");
    const trimmed = content.trimStart();
    // Handle both formatted and minified files:
    // Formatted: "use client";\n...
    // Minified: "use client";import*as t from"react";...
    return trimmed.startsWith('"use client"') || trimmed.startsWith("'use client'");
  } catch {
    return false;
  }
}

function isServerActionFile(filePath: string): boolean {
  try {
    const content = readFileSync(filePath, "utf-8");
    const trimmed = content.trimStart();
    return trimmed.startsWith('"use server"') || trimmed.startsWith("'use server'");
  } catch {
    return false;
  }
}

interface ActionFileInfo {
  importAlias: string;
  relativePath: string;
  absolutePath: string;
  exports: string[];
}

const actionFiles: ActionFileInfo[] = [];

// ─── Auto-generate server actions from PHP config ───────────────────────────

const generatedActionsPath = join(sourceDir, "server-actions.generated.ts");

try {
  const proc = Bun.spawn(
    ["php", "artisan", "rsc:action-manifest", "--no-interaction"],
    { cwd: process.cwd(), stdout: "pipe", stderr: "pipe" }
  );

  const output = await new Response(proc.stdout).text();
  const exitCode = await proc.exited;

  if (exitCode === 0) {
    const actionMap: Record<string, string> = JSON.parse(output.trim());
    const entries = Object.entries(actionMap);

    if (entries.length > 0) {
      const lines = [
        `"use server";`,
        `// @generated — do not edit. Auto-discovered from app/Rsc/Actions/`,
        ``,
      ];

      for (const [jsName, phpCallable] of entries) {
        lines.push(
          `export async function ${jsName}(...args: unknown[]) {`,
          `  return await (globalThis as any).php("${phpCallable}", ...args);`,
          `}`,
          ``
        );
      }

      writeFileSync(generatedActionsPath, lines.join("\n"));
      console.log(`Generated: ${generatedActionsPath} (${entries.length} action(s))`);
    } else if (existsSync(generatedActionsPath)) {
      rmSync(generatedActionsPath);
      console.log(`Removed stale: ${generatedActionsPath}`);
    }
  } else {
    const stderr = await new Response(proc.stderr).text();
    console.warn(`Warning: rsc:action-manifest failed (exit ${exitCode}). Skipping action generation.`);
    if (stderr.trim()) {
      console.warn(stderr.trim());
    }
  }
} catch (err) {
  console.warn("Warning: Could not run rsc:action-manifest. Skipping action generation.", err);
}

// ─── Discover User Components ───────────────────────────────────────────────

if (!existsSync(sourceDir)) {
  mkdirSync(sourceDir, { recursive: true });
  console.log(`Created source directory: ${sourceDir}`);
}

// Scaffold starter app/layout.tsx and app/page.tsx if the app/ directory doesn't exist
const appDir = join(sourceDir, "app");

if (!existsSync(appDir)) {
  mkdirSync(appDir, { recursive: true });

  writeFileSync(join(appDir, "layout.tsx"), `export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <div>
      <header style={{ padding: '16px 24px', borderBottom: '1px solid #e5e7eb' }}>
        <h1 style={{ fontSize: 20, fontWeight: 600 }}>My App</h1>
      </header>
      <main style={{ padding: 24 }}>
        {children}
      </main>
    </div>
  );
}
`);

  writeFileSync(join(appDir, "page.tsx"), `export default function HomePage() {
  return (
    <div>
      <h2 style={{ fontSize: 28, fontWeight: 700, marginBottom: 12 }}>Welcome to LaraBun</h2>
      <p style={{ color: '#6b7280', lineHeight: 1.6 }}>
        Edit <code style={{ background: '#f3f4f6', padding: '2px 6px', borderRadius: 4 }}>resources/js/rsc/app/page.tsx</code> to get started.
      </p>
    </div>
  );
}
`);

  console.log("Scaffolded: app/layout.tsx, app/page.tsx");
}

for await (const path of glob.scan(sourceDir)) {
  if (
    path.startsWith("entry.") ||
    path.includes(".test.") ||
    path.includes(".spec.") ||
    path === "routes.generated.ts"
  ) {
    continue;
  }

  // Skip _ prefixed files only outside app/ (existing convention)
  if (basename(path).startsWith("_") && !path.startsWith("app/")) {
    continue;
  }

  const absolutePath = resolve(sourceDir, path);
  const base = basename(path).replace(/\.(tsx|ts|jsx|js)$/, "");

  // Only register entry-point files as components:
  // - page.*, layout.*, loading.*, default.* (RSC entry points)
  // - "use client" files (client component boundaries)
  // - "use server" files (server actions)
  // Everything else (lib/utils.ts, components/ui/button.tsx, etc.)
  // is bundled transitively when imported by entry points.
  const isEntryPoint = ["page", "layout", "loading", "default"].includes(base);

  if (!isEntryPoint && !isClientFile(absolutePath) && !isServerActionFile(absolutePath)) {
    continue;
  }

  // Server action files are NOT components — handle them separately
  if (isServerActionFile(absolutePath)) {
    const mod = await import(absolutePath);
    const exports = Object.entries(mod)
      .filter(([, v]) => typeof v === "function")
      .map(([name]) => name);

    if (exports.length > 0) {
      actionFiles.push({
        importAlias: `_A${actionFiles.length}`,
        relativePath: `./${path}`,
        absolutePath,
        exports,
      });
    }

    continue;
  }

  const name = path.startsWith("app/")
    ? path.replace(/\.(tsx|ts|jsx|js)$/, "")
    : basename(path).replace(/\.(tsx|ts|jsx|js)$/, "");
  const info: ComponentInfo = {
    name,
    importAlias: `_C${aliasIndex++}`,
    relativePath: `./${path}`,
    absolutePath,
    isClient: isClientFile(absolutePath),
  };

  if (info.isClient) {
    clientComponents.push(info);
  } else {
    serverComponents.push(info);
  }

  // Detect metadata exports from layout files (app/**/layout.tsx)
  if (path.startsWith("app/") && basename(path).match(/^layout\.(tsx|ts|jsx|js)$/)) {
    const meta = detectMetadataExports(absolutePath);
    if (meta.hasStatic || meta.hasDynamic) {
      pageMetadata.push({
        componentName: info.name,
        absolutePath,
        hasStatic: meta.hasStatic,
        hasDynamic: meta.hasDynamic,
      });
    }
  }

  // Detect metadata exports and collect route info from page files (app/**/page.tsx)
  if (path.startsWith("app/") && basename(path).match(/^page\.(tsx|ts|jsx|js)$/)) {
    const meta = detectMetadataExports(absolutePath);
    if (meta.hasStatic || meta.hasDynamic) {
      pageMetadata.push({
        componentName: info.name,
        absolutePath,
        hasStatic: meta.hasStatic,
        hasDynamic: meta.hasDynamic,
      });
    }

    // Extract route pattern from file path
    // e.g. "app/docs/[slug]/page.tsx" → "/docs/[slug]"
    const dir = path.replace(/\/page\.(tsx|ts|jsx|js)$/, "").replace(/^app\/?/, "");
    const segments = dir ? dir.split("/") : [];
    const params: string[] = [];
    let hasCatchAll = false;

    const urlSegments = segments
      .filter((s) => !s.startsWith("(")) // Strip route groups like (auth)
      .map((s) => {
        const catchAll = s.match(/^\[\.\.\.(\w+)\]$/);
        if (catchAll) {
          hasCatchAll = true;
          params.push(catchAll[1]);
          return s;
        }
        const dynamic = s.match(/^\[(\w+)\]$/);
        if (dynamic) {
          params.push(dynamic[1]);
          return s;
        }
        return s;
      });

    const urlPattern = "/" + urlSegments.join("/");
    pageRoutes.push({ urlPattern: urlPattern === "/" ? "/" : urlPattern, params, hasCatchAll });
  }
}

// ─── Discover Package Client Components ─────────────────────────────────────

// Scan the package's resources/js/ directory for "use client" files.
// Package client components get moduleId prefix "lara-bun/" (e.g., "lara-bun/Link.tsx")
const packageClientComponents: ComponentInfo[] = [];

if (existsSync(packageJsDir)) {
  for await (const path of glob.scan(packageJsDir)) {
    if (
      path.startsWith("entry.") ||
      path.includes(".test.") ||
      path.includes(".spec.") ||
      path.startsWith("_")
    ) {
      continue;
    }

    const absolutePath = resolve(packageJsDir, path);

    if (!isClientFile(absolutePath)) {
      continue;
    }

    const name = basename(path).replace(/\.(tsx|ts|jsx|js)$/, "");
    const info: ComponentInfo = {
      name,
      importAlias: `_C${aliasIndex++}`,
      relativePath: `lara-bun/${path}`,
      absolutePath,
      isClient: true,
    };

    packageClientComponents.push(info);
    clientComponents.push(info);
  }
}

const allComponents = [...serverComponents, ...clientComponents];

if (allComponents.length === 0) {
  console.error(`No RSC components found in: ${sourceDir}`);
  console.error("Create component files (e.g. Dashboard.tsx or user-profile.tsx)");
  process.exit(1);
}

console.log(`Found ${serverComponents.length} server component(s):`);
serverComponents.forEach((c) => console.log(`  ${c.name} ← ${c.relativePath}`));

if (clientComponents.length > 0) {
  console.log(`Found ${clientComponents.length} client component(s):`);
  clientComponents.forEach((c) => console.log(`  ${c.name} ← ${c.relativePath}`));
}

if (actionFiles.length > 0) {
  console.log(`Found ${actionFiles.length} server action file(s):`);
  actionFiles.forEach((a) => console.log(`  ${a.relativePath} → ${a.exports.join(", ")}`));
}

// Build a set of absolute paths for client files (for the plugin to intercept)
const clientAbsolutePaths = new Set(clientComponents.map((c) => c.absolutePath));

// Map from moduleId (used in manifests) to component info
// moduleId is the relative path like "./Counter.tsx" or "lara-bun/Link.tsx"
const clientModuleIds = new Map<string, ComponentInfo>();
for (const c of clientComponents) {
  clientModuleIds.set(c.relativePath, c);
}

// ─── Server Build ───────────────────────────────────────────────────────────

// Plugin that intercepts imports of "use client" files and replaces them
// with client module proxies for Flight serialization
const useClientPlugin: BunPlugin = {
  name: "use-client-proxy",
  setup(build) {
    // Create a filter that matches absolute paths of client components
    for (const absPath of clientAbsolutePaths) {
      const escaped = absPath.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
      build.onLoad({ filter: new RegExp(`^${escaped}$`) }, (args) => {
        // Find the component info for this path
        const comp = clientComponents.find((c) => c.absolutePath === args.path);
        if (!comp) return undefined;

        const moduleId = comp.relativePath;

        // Detect named exports to generate proper proxy exports
        const source = readFileSync(args.path, "utf-8");
        const namedExports: string[] = [];

        const exportMatches = source.matchAll(/export\s+(?:function|const|let|var|class)\s+(\w+)/g);
        for (const m of exportMatches) {
          namedExports.push(m[1]);
        }

        const braceMatches = source.matchAll(/export\s*\{([^}]+)\}/g);
        for (const m of braceMatches) {
          const names = m[1].split(",").map((s) => {
            const parts = s.trim().split(/\s+as\s+/);
            return parts[parts.length - 1].trim();
          });
          namedExports.push(...names.filter((n) => n && n !== "default"));
        }

        const proxyExports = namedExports
          .map((name) => `export const ${name} = proxy["${name}"];`)
          .join("\n");

        return {
          contents: `
import { createClientModuleProxy } from "react-server-dom-webpack/server.edge";
const proxy = createClientModuleProxy("${moduleId}");
export default proxy;
${proxyExports}
`,
          loader: "js",
        };
      });
    }
  },
};

// User path alias plugin — resolves @/* and ~/* imports from tsconfig.json paths.
// Reads the tsconfig to support whatever alias the user has configured.
// Package alias plugin — resolves "lara-bun/*" imports to the package directory
// so server components can `import Link from 'lara-bun/Link'`
const packageAliasPlugin: BunPlugin = {
  name: "lara-bun-alias",
  setup(build) {
    build.onResolve({ filter: /^lara-bun\// }, (args) => {
      const subPath = args.path.replace(/^lara-bun\//, "");

      // Try exact path first, then with extensions
      const candidates = [
        join(packageJsDir, subPath),
        join(packageJsDir, `${subPath}.tsx`),
        join(packageJsDir, `${subPath}.ts`),
        join(packageJsDir, `${subPath}.jsx`),
        join(packageJsDir, `${subPath}.js`),
      ];

      for (const candidate of candidates) {
        if (existsSync(candidate)) {
          return { path: candidate };
        }
      }

      return undefined;
    });
  },
};

// Catch-all plugin for "use client" files not pre-discovered during scanning.
// Handles node_modules (e.g., next-themes) AND user files outside the component
// map (e.g., @/components/mode-toggle.tsx imported from a page).
const externalClientModules = new Set<string>();

const useClientCatchAllPlugin: BunPlugin = {
  name: "use-client-catch-all",
  setup(build) {
    build.onLoad({ filter: /\.(tsx|ts|jsx|js|mjs|cjs)$/ }, (args) => {
      // Already handled by useClientPlugin
      if (clientAbsolutePaths.has(args.path)) {
        return undefined;
      }

      if (!isClientFile(args.path)) {
        return undefined;
      }

      // Determine moduleId based on file location
      const nodeModulesIndex = args.path.lastIndexOf("node_modules/");
      let moduleId: string;

      if (nodeModulesIndex !== -1) {
        moduleId = args.path.slice(nodeModulesIndex + "node_modules/".length);
      } else if (args.path.startsWith(sourceDir)) {
        // User file inside rsc/ — use relative path (e.g., "./components/mode-toggle.tsx")
        moduleId = "./" + args.path.slice(sourceDir.length + 1);
      } else {
        moduleId = args.path;
      }

      externalClientModules.add(args.path);

      // Register as a client component for manifest generation
      const info: ComponentInfo = {
        name: moduleId,
        importAlias: `_C${aliasIndex++}`,
        relativePath: moduleId,
        absolutePath: args.path,
        isClient: true,
      };

      if (!clientModuleIds.has(moduleId)) {
        clientComponents.push(info);
        clientModuleIds.set(moduleId, info);
        clientAbsolutePaths.add(args.path);
      }

      // Detect named exports from the original file to generate proper proxy exports
      const source = readFileSync(args.path, "utf-8");
      const namedExports: string[] = [];

      // Match: export function Name, export const Name, export class Name, export { Name }
      const exportMatches = source.matchAll(/export\s+(?:function|const|let|var|class)\s+(\w+)/g);
      for (const m of exportMatches) {
        namedExports.push(m[1]);
      }

      // Match: export { Foo, Bar } or export { Foo as Bar }
      const braceMatches = source.matchAll(/export\s*\{([^}]+)\}/g);
      for (const m of braceMatches) {
        const names = m[1].split(",").map((s) => {
          const parts = s.trim().split(/\s+as\s+/);
          return parts[parts.length - 1].trim();
        });
        namedExports.push(...names.filter((n) => n && n !== "default"));
      }

      const proxyExports = namedExports
        .map((name) => `export const ${name} = proxy["${name}"];`)
        .join("\n");

      return {
        contents: `
import { createClientModuleProxy } from "react-server-dom-webpack/server.edge";
const proxy = createClientModuleProxy("${moduleId}");
export default proxy;
${proxyExports}
`,
        loader: "js",
      };
    });
  },
};

// ─── CSS Collection Plugin ───────────────────────────────────────────────────
// Collects CSS file imports from server components and tracks which component
// imported each CSS file. This allows per-page CSS — layout CSS loads globally,
// page CSS loads only when that page renders.
const collectedCssFiles = new Set<string>();
// Map: component name (e.g. "app/layout", "app/(main)/(home)/page") → set of CSS paths
const cssByComponent = new Map<string, Set<string>>();

const cssCollectorPlugin: BunPlugin = {
  name: "css-collector",
  setup(build) {
    build.onResolve({ filter: /\.css$/ }, (args) => {
      const resolved = args.importer
        ? resolve(dirname(args.importer), args.path)
        : resolve(args.path);

      if (existsSync(resolved)) {
        collectedCssFiles.add(resolved);

        // Track which component imported this CSS
        if (args.importer) {
          // Normalize to component name matching the entry bundle's naming
          // e.g. /path/to/resources/js/rsc/app/layout.tsx → "app/layout"
          const componentName = args.importer.startsWith(sourceDir + "/")
            ? args.importer.slice(sourceDir.length + 1).replace(/\.(tsx|ts|jsx|js)$/, "")
            : args.importer.replace(/\.(tsx|ts|jsx|js)$/, "");

          if (!cssByComponent.has(componentName)) {
            cssByComponent.set(componentName, new Set());
          }
          cssByComponent.get(componentName)!.add(resolved);
        }
      }

      return { path: resolved, namespace: "css-stub" };
    });

    build.onLoad({ filter: /.*/, namespace: "css-stub" }, () => {
      return { contents: "", loader: "js" };
    });
  },
};

const serverPlugins: BunPlugin[] = [packageAliasPlugin, cssCollectorPlugin];
if (clientComponents.length > 0) {
  serverPlugins.push(useClientPlugin);
}
serverPlugins.push(useClientCatchAllPlugin);

// Generate server entry that imports all components (client ones will be proxied)
// Only import user-space server/client components — package client components
// are resolved through the alias plugin when referenced from server components
const userComponents = allComponents.filter(
  (c) => !c.relativePath.startsWith("lara-bun/")
);

const serverImports = userComponents
  .map((c) => `import ${c.importAlias} from "${c.absolutePath}";`)
  .join("\n");

const serverComponentMap = userComponents
  .map((c) => `  "${c.name}": ${c.importAlias},`)
  .join("\n");

const clientManifestParam =
  clientComponents.length > 0
    ? "clientManifest: Record<string, unknown>"
    : "";
const clientManifestArg =
  clientComponents.length > 0 ? "clientManifest" : "null";

const actionImports = actionFiles
  .map((a) => `import * as ${a.importAlias} from "${a.absolutePath}";`)
  .join("\n");

const actionRegistrations = actionFiles
  .map(
    (a) => `for (const [name, fn] of Object.entries(${a.importAlias})) {
  if (typeof fn === "function") {
    registerServerReference(fn, "${a.relativePath}", name);
  }
}`
  )
  .join("\n");

const actionMapEntries = actionFiles
  .map((a) => `  "${a.relativePath}": ${a.importAlias},`)
  .join("\n");

if (pageMetadata.length > 0) {
  console.log(`Found ${pageMetadata.length} page(s) with metadata exports:`);
  pageMetadata.forEach((m) =>
    console.log(`  ${m.componentName} — ${m.hasStatic ? "static" : ""}${m.hasStatic && m.hasDynamic ? " + " : ""}${m.hasDynamic ? "dynamic" : ""}`)
  );
}

const hasActions = actionFiles.length > 0;

const flightImports = hasActions
  ? "import { renderToReadableStream, registerServerReference, decodeReply as _decodeReply } from \"react-server-dom-webpack/server.edge\";"
  : "import { renderToReadableStream } from \"react-server-dom-webpack/server.edge\";";

const actionReExports = hasActions
  ? `\n// Re-export for rsc-handler (which cannot import server.edge directly)\nexport const decodeReply = _decodeReply;\nexport const renderActionStream = renderToReadableStream;\n`
  : "";

// Generate metadata imports and resolveMetadata function
const metadataImports = pageMetadata
  .map((m, i) => {
    const parts: string[] = [];
    if (m.hasStatic) parts.push(`metadata as _meta${i}`);
    if (m.hasDynamic) parts.push(`generateMetadata as _genMeta${i}`);
    return `import { ${parts.join(", ")} } from "${m.absolutePath}";`;
  })
  .join("\n");

const metadataMapEntries = pageMetadata
  .map((m, i) => {
    const parts: string[] = [];
    if (m.hasStatic) parts.push(`static: _meta${i} as Record<string, unknown>`);
    if (m.hasDynamic) parts.push(`generate: _genMeta${i}`);
    return `  "${m.componentName}": { ${parts.join(", ")} },`;
  })
  .join("\n");

const metadataBlock = pageMetadata.length > 0
  ? `
${metadataImports}

const metadataMap: Record<string, { static?: Record<string, unknown>; generate?: (props: Record<string, unknown>) => unknown }> = {
${metadataMapEntries}
};

export async function resolveMetadata(
  component: string,
  props: Record<string, unknown>,
  layouts: { component: string }[] = []
): Promise<Record<string, unknown> | null> {
  const entry = metadataMap[component];
  if (!entry) return null;

  const metadata = entry.generate
    ? (await entry.generate(props)) as Record<string, unknown>
    : { ...(entry.static ?? {}) };

  if (!metadata) return null;

  // Apply title.template from the nearest layout that defines one.
  // Layouts are ordered outermost-first, so iterate in reverse to find
  // the closest layout with a template.
  if (metadata.title && typeof metadata.title === "string") {
    for (let i = layouts.length - 1; i >= 0; i--) {
      const layoutMeta = metadataMap[layouts[i].component];
      if (!layoutMeta) continue;

      const layoutData = layoutMeta.static as Record<string, unknown> | undefined;
      const titleConfig = layoutData?.title;

      if (titleConfig && typeof titleConfig === "object" && titleConfig !== null && "template" in titleConfig) {
        const template = (titleConfig as { template: string }).template;
        metadata.title = template.replace("%s", metadata.title as string);
        break;
      }
    }
  }

  // If no page title but layout has a default title, use it
  if (!metadata.title) {
    for (let i = layouts.length - 1; i >= 0; i--) {
      const layoutMeta = metadataMap[layouts[i].component];
      if (!layoutMeta) continue;

      const layoutData = layoutMeta.static as Record<string, unknown> | undefined;
      const titleConfig = layoutData?.title;

      if (titleConfig && typeof titleConfig === "object" && titleConfig !== null && "default" in titleConfig) {
        metadata.title = (titleConfig as { default: string }).default;
        break;
      }
    }
  }

  return metadata;
}
`
  : `
export async function resolveMetadata(): Promise<null> {
  return null;
}
`;

const entrySource = `// Auto-generated by lara-bun build-rsc — do not edit
${flightImports}
import { createElement, Suspense } from "react";
${serverImports}
${hasActions ? actionImports : ''}
${actionReExports}
${metadataBlock}
interface LayoutEntry {
  component: string;
  props: Record<string, unknown>;
}

const components: Record<string, React.ComponentType<any>> = {
${serverComponentMap}
};
${hasActions ? `
${actionRegistrations}

const actions: Record<string, Record<string, Function>> = {
${actionMapEntries}
};

export function getServerAction(moduleId: string, name: string): Function | undefined {
  return (actions[moduleId] as any)?.[name];
}
` : ''}
function buildElement(
  component: string,
  props: Record<string, unknown>,
  layouts: LayoutEntry[],
  loadings: string[] = [],
  parallelSlots: Record<string, string | { component: string; props: Record<string, unknown> }> = {}
): React.ReactElement {
  const Component = components[component];

  if (!Component) {
    throw new Error(
      \`Unknown RSC component: "\${component}". Available: \${Object.keys(components).join(", ")}\`
    );
  }

  let element = createElement(Component, props);

  // Wrap in loading.tsx Suspense boundaries (innermost first).
  for (let i = loadings.length - 1; i >= 0; i--) {
    const Loading = components[loadings[i]];
    if (Loading) {
      element = createElement(Suspense, { fallback: createElement(Loading, null) }, element);
    }
  }

  // Render parallel slot components (@folder convention).
  // Each slot becomes a named prop on the layout.
  // Slots can be a string (component name, uses page props) or an object
  // {component, props} for route interception overrides.
  const slotElements: Record<string, React.ReactElement> = {};
  for (const [slotName, slotInfo] of Object.entries(parallelSlots)) {
    if (typeof slotInfo === "object" && slotInfo !== null && "component" in slotInfo) {
      // Route interception override — use the interceptor's component and props
      const SlotComponent = components[slotInfo.component];
      if (SlotComponent) {
        slotElements[slotName] = createElement(SlotComponent, slotInfo.props);
      }
    } else {
      // Normal parallel slot — use page props
      const SlotComponent = components[slotInfo as string];
      if (SlotComponent) {
        slotElements[slotName] = createElement(SlotComponent, props);
      }
    }
  }

  // Wrap in layouts: layouts[0] is outermost, layouts[last] is innermost.
  // The innermost layout receives parallel slots as props alongside children.
  for (let i = layouts.length - 1; i >= 0; i--) {
    const Layout = components[layouts[i].component];
    if (!Layout) {
      throw new Error(
        \`Unknown layout component: "\${layouts[i].component}". Available: \${Object.keys(components).join(", ")}\`
      );
    }
    // Pass parallel slots to the innermost layout (closest to page)
    const layoutProps = i === layouts.length - 1
      ? { ...layouts[i].props, ...slotElements, children: element }
      : { ...layouts[i].props, children: element };
    element = createElement(Layout, layoutProps);
  }

  return element;
}

export async function renderRsc(
  component: string,
  props: Record<string, unknown>,
  ${clientManifestParam ? `${clientManifestParam},` : ""}
  layouts: LayoutEntry[] = [],
  loadings: string[] = [], parallelSlots: Record<string, string | { component: string; props: Record<string, unknown> }> = {}
): Promise<string> {
  const element = buildElement(component, props, layouts, loadings, parallelSlots);
  const stream = renderToReadableStream(element, ${clientManifestArg});

  return await new Response(stream).text();
}

export function renderRscStream(
  component: string,
  props: Record<string, unknown>,
  ${clientManifestParam ? `${clientManifestParam},` : ""}
  layouts: LayoutEntry[] = [],
  loadings: string[] = [], parallelSlots: Record<string, string | { component: string; props: Record<string, unknown> }> = {}
): ReadableStream {
  const element = buildElement(component, props, layouts, loadings, parallelSlots);
  return renderToReadableStream(element, ${clientManifestArg});
}
`;

// Clean output directories and stale static cache to prevent serving old chunk hashes
rmSync(browserOutDir, { recursive: true, force: true });
rmSync(outDir, { recursive: true, force: true });
const staticCacheDir = join(process.cwd(), "storage/framework/rsc-static");
rmSync(staticCacheDir, { recursive: true, force: true });
mkdirSync(outDir, { recursive: true });

const entryPath = join(outDir, "entry.rsc.tsx");
writeFileSync(entryPath, entrySource);
console.log(`Generated: ${entryPath}`);

// ─── Generate Typed Routes ──────────────────────────────────────────────────

if (pageRoutes.length > 0) {
  // Fetch route manifest from PHP for staticPaths and where constraints
  let routeManifest: RouteManifestEntry[] = [];

  try {
    const proc = Bun.spawn(
      ["php", "artisan", "rsc:route-manifest", "--no-interaction"],
      { cwd: process.cwd(), stdout: "pipe", stderr: "pipe" }
    );

    const output = await new Response(proc.stdout).text();
    const exitCode = await proc.exited;

    if (exitCode === 0) {
      routeManifest = JSON.parse(output.trim());
    } else {
      console.warn("Warning: rsc:route-manifest failed. Falling back to string params.");
    }
  } catch {
    console.warn("Warning: Could not run rsc:route-manifest. Falling back to string params.");
  }

  // Build typed routes from the PHP route manifest which has both URL patterns
  // and domain info. This avoids duplicate keys when multiple route groups
  // map to the same URL on different domains.
  interface TypedRoute {
    key: string;          // Unique key for RouteParams (may include domain prefix)
    urlPattern: string;   // The actual URL pattern (e.g. "/")
    params: string[];
    baseUrl?: string;
    staticPaths?: Record<string, string[]>;
    where?: Record<string, string[]>;
  }

  const typedRoutes: TypedRoute[] = [];
  const seenKeys = new Set<string>();

  for (const entry of routeManifest) {
    const tsPattern = entry.urlPattern.replace(/\{(\w+)\}/g, "[$1]");

    // Extract params from pattern
    const params: string[] = [];
    const paramMatches = tsPattern.matchAll(/\[(?:\.\.\.)?(\w+)\]/g);
    for (const m of paramMatches) {
      params.push(m[1]);
    }

    // For domain routes with duplicate URL patterns, prefix with the domain
    let key = tsPattern;
    if (seenKeys.has(key) && entry.baseUrl) {
      // Extract hostname from baseUrl for the key
      try {
        const host = new URL(entry.baseUrl).hostname.split(".")[0];
        key = `${host}:${tsPattern}`;
      } catch {
        key = `${entry.baseUrl}${tsPattern}`;
      }
    }
    seenKeys.add(key);

    typedRoutes.push({
      key,
      urlPattern: tsPattern,
      params,
      baseUrl: entry.baseUrl,
      staticPaths: entry.staticPaths,
      where: entry.where,
    });
  }

  const sorted = typedRoutes.sort((a, b) => a.key.localeCompare(b.key));

  const routeParamEntries = sorted
    .map((r) => {
      if (r.params.length === 0) {
        return `  '${r.key}': Record<string, never>;`;
      }

      const paramTypes = r.params
        .map((p) => {
          const values = r.staticPaths?.[p]
            ?? r.staticPaths?.["_default"]
            ?? r.where?.[p];

          if (values && values.length > 0) {
            return `${p}: ${values.map((v) => `'${v}'`).join(" | ")}`;
          }
          return `${p}: string`;
        })
        .join("; ");
      return `  '${r.key}': { ${paramTypes} };`;
    })
    .join("\n");

  // Collect domain mappings for routes that have domains
  const domainEntries: string[] = [];
  for (const r of sorted) {
    if (r.baseUrl) {
      domainEntries.push(`  '${r.key}': '${r.baseUrl}',`);
    }
  }

  const domainMapBlock = domainEntries.length > 0
    ? `\nconst domainRoutes: Partial<Record<RoutePath, string>> = {\n${domainEntries.join("\n")}\n};\n`
    : "";

  const routesSource = `// Auto-generated by lara-bun — do not edit
// Regenerated on every \`rsc:build\`

export interface RouteParams {
${routeParamEntries}
}

export type RoutePath = keyof RouteParams;

type HasParams<T> = T extends Record<string, never> ? false : true;
${domainMapBlock}
/**
 * Generate a typed URL for an RSC page route.
 *
 * Returns a relative path for same-domain routes and a full URL
 * (resolved at build time) for routes on a different domain.
 *
 * @example
 * route('/')                                    // "/"
 * route('/docs/[slug]', { slug: 'metadata' })   // "/docs/metadata"
 * route('docs:/')                               // "http://docs.app.test/"
 * route('/onboarding')                          // "http://merchant.app.test/onboarding"
 */
export function route<T extends RoutePath>(
  path: T,
  ...args: HasParams<RouteParams[T]> extends true ? [RouteParams[T]] : []
): string {
  const params = args[0] as Record<string, string> | undefined;

  // Domain-prefixed keys (e.g. "docs:/") — extract the actual URL path
  let url: string = (path as string).includes(':') ? (path as string).split(':').slice(1).join(':') : path;

  if (params) {
    for (const [key, value] of Object.entries(params)) {
      url = url.replace(\`[...\${key}]\`, encodeURIComponent(value));
      url = url.replace(\`[\${key}]\`, encodeURIComponent(value));
    }
  }
${domainEntries.length > 0 ? `
  const baseUrl = domainRoutes[path];

  if (baseUrl) {
    return \`\${baseUrl}\${url}\`;
  }
` : ""}
  return url;
}
`;

  const routesPath = join(sourceDir, "routes.generated.ts");
  writeFileSync(routesPath, routesSource);
  console.log(`Generated: ${routesPath} (${sorted.length} route(s))`);

  // Generate intercept manifest for client-side route interception
  const interceptEntries: { urlPattern: string; slot: string }[] = [];

  for (const entry of routeManifest) {
    if (entry.intercepts) {
      const tsPattern = entry.urlPattern.replace(/\{(\w+)\}/g, "[$1]");

      for (const intercept of entry.intercepts) {
        interceptEntries.push({ urlPattern: tsPattern, slot: intercept.slot });
      }
    }
  }

  if (interceptEntries.length > 0) {
    writeFileSync(
      join(outDir, "intercept-manifest.json"),
      JSON.stringify(interceptEntries, null, 2)
    );
    console.log(`Generated: ${join(outDir, "intercept-manifest.json")} (${interceptEntries.length} intercept(s))`);
  }
}

const serverResult = await Bun.build({
  entrypoints: [entryPath],
  outdir: outDir,
  target: "bun",
  conditions: ["react-server"],
  plugins: serverPlugins,
  define: {
    "process.env.NODE_ENV": '"production"',
  },
});

if (!serverResult.success) {
  console.error("Server build failed:");
  serverResult.logs.forEach((log) => console.error(log));
  process.exit(1);
}

console.log(`Built server bundle: ${join(outDir, "entry.rsc.js")}`);

if (externalClientModules.size > 0) {
  console.log(`Discovered ${externalClientModules.size} external client module(s):`);
  for (const mod of externalClientModules) {
    const nodeModulesIndex = mod.lastIndexOf("node_modules/");
    const shortPath = nodeModulesIndex !== -1
      ? mod.slice(nodeModulesIndex + "node_modules/".length)
      : mod;
    console.log(`  ${shortPath}`);
  }
}

// ─── Action Manifest ────────────────────────────────────────────────────────

if (actionFiles.length > 0) {
  const actionManifest: Record<string, string[]> = {};

  for (const a of actionFiles) {
    actionManifest[a.relativePath] = a.exports;
  }

  writeFileSync(
    join(outDir, "action-manifest.json"),
    JSON.stringify(actionManifest, null, 2)
  );
  console.log(`Generated: ${join(outDir, "action-manifest.json")}`);
}

// ─── Client Builds + Manifests ──────────────────────────────────────────────

if (clientComponents.length === 0) {
  console.log("No client components — skipping client builds and manifests.");
  process.exit(0);
}

// a) SSR client build — builds client components for server-side HTML rendering
mkdirSync(clientOutDir, { recursive: true });

// Note: React Compiler is NOT applied to SSR builds.
// The compiler's runtime (`react/compiler-runtime`) uses createContext,
// which is unavailable under react-server conditions in the Bun worker.
const ssrPlugins: BunPlugin[] = [packageAliasPlugin];

const ssrResult = await Bun.build({
  entrypoints: clientComponents.map((c) => c.absolutePath),
  outdir: clientOutDir,
  target: "bun",
  naming: "[name].[ext]",
  plugins: ssrPlugins,
  external: ["react", "react-dom"],
  define: {
    "process.env.NODE_ENV": '"production"',
  },
});

if (!ssrResult.success) {
  console.error("SSR client build failed:");
  ssrResult.logs.forEach((log) => console.error(log));
  process.exit(1);
}

console.log(`Built SSR client bundles: ${clientOutDir}/`);

// Generate SSR module map — maps moduleId to the actual SSR output filename
// Needed because external client modules (e.g. next-themes) have paths like
// "next-themes/dist/index.mjs" where basename alone would be ambiguous.
const ssrFileMap: Record<string, string> = {};

for (const output of ssrResult.outputs) {
  const outputName = basename(output.path).replace(/\.[^.]+$/, "");

  // Find the client component whose entry produced this output
  for (const c of clientComponents) {
    const entryName = basename(c.absolutePath).replace(/\.[^.]+$/, "");

    if (entryName === outputName) {
      ssrFileMap[c.relativePath] = basename(output.path);
    }
  }
}

writeFileSync(
  join(outDir, "ssr-module-map.json"),
  JSON.stringify(ssrFileMap, null, 2)
);
console.log(`Generated: ${join(outDir, "ssr-module-map.json")}`);

// b) Browser client build — builds client components + hydration entry for browser
mkdirSync(browserOutDir, { recursive: true });

// Generate a lightweight hydration entry — client components self-register
// via separate wrapper entries, so we don't need to import them here.
const createRscAppPath = join(packageJsDir, "createRscApp.ts");

// Read the intercept manifest (generated during route manifest phase)
const interceptManifestPath = join(outDir, "intercept-manifest.json");
let interceptManifestJson = "[]";

if (existsSync(interceptManifestPath)) {
  interceptManifestJson = readFileSync(interceptManifestPath, "utf-8");
}

const hydrateEntrySource = `// Auto-generated hydration entry — do not edit
// __webpack_require__ and __webpack_chunk_load__ are pre-defined in the
// inline <script> block rendered by @rscScripts so they exist before this
// ES module initializes (ES module imports are hoisted above module body code).
import { createRscApp } from "${createRscAppPath}";

const interceptManifest: { urlPattern: string; slot: string }[] = ${interceptManifestJson};

const container = document.getElementById("rsc-root");
if (container) {
  createRscApp(container, {}, interceptManifest);
}
`;

const hydrateEntryPath = join(outDir, "entry.hydrate.tsx");
writeFileSync(hydrateEntryPath, hydrateEntrySource);

// Generate self-registering wrapper entries for each client component.
// Each wrapper imports the component and registers it into window.__RSC_MODULES__.
// With splitting: true, Bun extracts shared dependencies (React, ReactDOM)
// into common chunks, so each page only loads the components it actually uses.
const wrapperEntryPaths: string[] = [];
const wrapperIndexToModuleId = new Map<number, string>();

for (let i = 0; i < clientComponents.length; i++) {
  const c = clientComponents[i];
  const wrapperSource = `// Auto-generated wrapper — do not edit
import * as mod from "${c.absolutePath}";
(window as any).__RSC_MODULES__["${c.relativePath}"] = mod;
`;
  const wrapperPath = join(outDir, `_register_${i}.tsx`);
  writeFileSync(wrapperPath, wrapperSource);
  wrapperEntryPaths.push(wrapperPath);
  wrapperIndexToModuleId.set(i, c.relativePath);
}

// Plugin that intercepts imports of "use server" files in the browser build
// and replaces them with createServerReference stubs that call through the
// Flight action protocol instead of executing server code in the browser.
const useServerPlugin: BunPlugin = {
  name: "use-server-browser-stub",
  setup(build) {
    for (const action of actionFiles) {
      const escaped = action.absolutePath.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
      build.onLoad({ filter: new RegExp(`^${escaped}$`) }, () => {
        const navigatePath = join(packageJsDir, "navigate.ts");
        const exports = action.exports
          .map(
            (name) =>
              `export const ${name} = createServerReference("${action.relativePath}#${name}", callServer);`
          )
          .join("\n");

        return {
          contents: `
import { createServerReference } from "react-server-dom-webpack/client.browser";
import { getCallServer } from "${navigatePath}";
function callServer(id, args) { return getCallServer()(id, args); }
${exports}
`,
          loader: "js",
        };
      });
    }
  },
};

const browserPlugins: BunPlugin[] = [packageAliasPlugin];
if (actionFiles.length > 0) {
  browserPlugins.push(useServerPlugin);
}
if (reactCompilerPlugin) {
  browserPlugins.push(reactCompilerPlugin);
}

const browserResult = await Bun.build({
  entrypoints: [hydrateEntryPath, ...wrapperEntryPaths],
  outdir: browserOutDir,
  target: "browser",
  format: "esm",
  splitting: true,
  minify: true,
  naming: "[name]-[hash].[ext]",
  plugins: browserPlugins,
  define: {
    "process.env.NODE_ENV": '"production"',
  },
});

if (!browserResult.success) {
  console.error("Browser client build failed:");
  browserResult.logs.forEach((log) => console.error(log));
  process.exit(1);
}

// ─── Match browser outputs to inputs and build structured manifest ───────────
//
// Classify each output as: hydrate entry, wrapper entry (component), or shared chunk.
// Shared chunks are extracted by Bun's code splitting (React, ReactDOM, etc.).

const publicPrefix = join(process.cwd(), "public");

interface BrowserManifest {
  entry: string;
  shared: string[];
  modules: Record<string, string[]>;
}

const browserManifest: BrowserManifest = {
  entry: "",
  shared: [],
  modules: {},
};

// Build a map from wrapper basename (without extension) to module ID
const wrapperBasenameToModuleId = new Map<string, string>();
for (let i = 0; i < clientComponents.length; i++) {
  wrapperBasenameToModuleId.set(`_register_${i}`, clientComponents[i].relativePath);
}

for (const output of browserResult.outputs) {
  const relativePath = output.path.replace(publicPrefix, "");
  const outputBasename = basename(output.path);

  // Strip -[hash].js suffix to get the original entry name.
  // Bun uses base36 hashes (alphanumeric), not hex.
  const nameWithoutHash = outputBasename.replace(/-[a-z0-9]+\.js$/, "");

  if (nameWithoutHash === "entry.hydrate") {
    browserManifest.entry = relativePath;
  } else if (wrapperBasenameToModuleId.has(nameWithoutHash)) {
    // Wrapper entry — look up module ID
    browserManifest.modules[wrapperBasenameToModuleId.get(nameWithoutHash)!] = [relativePath];
  } else {
    // Shared dependency chunk (React, ReactDOM, etc.)
    browserManifest.shared.push(relativePath);
  }
}

console.log(`Built browser bundles: ${browserOutDir}/`);
console.log(`  entry: ${browserManifest.entry}`);
console.log(`  shared: ${browserManifest.shared.length} chunk(s)`);
browserManifest.shared.forEach((c) => console.log(`    ${c}`));
console.log(`  modules: ${Object.keys(browserManifest.modules).length} component(s)`);
Object.entries(browserManifest.modules).forEach(([id, chunks]) =>
  console.log(`    ${id} → ${chunks.join(", ")}`)
);

// c) Generate manifests

// Client manifest — used by server during Flight serialization.
// Maps moduleId -> { id, chunks, name }
// The `chunks` array tells Flight which URLs to load via __webpack_chunk_load__
// before calling __webpack_require__ for this module.
const clientManifest: Record<
  string,
  { id: string; chunks: string[]; name: string }
> = {};

for (const c of clientComponents) {
  clientManifest[c.relativePath] = {
    id: c.relativePath,
    chunks: browserManifest.modules[c.relativePath] ?? [],
    name: "default",
  };
}

writeFileSync(
  join(outDir, "client-manifest.json"),
  JSON.stringify(clientManifest, null, 2)
);
console.log(`Generated: ${join(outDir, "client-manifest.json")}`);

// SSR manifest — used by rsc-handler during createFromReadableStream
// Structure: { moduleMap: { [moduleId]: { [exportName]: { id, chunks, name } } }, moduleLoading, serverModuleMap }
const ssrModuleMap: Record<
  string,
  Record<string, { id: string; chunks: string[]; name: string }>
> = {};

for (const c of clientComponents) {
  ssrModuleMap[c.relativePath] = {
    "*": {
      id: c.relativePath,
      chunks: [],
      name: "*",
    },
    "default": {
      id: c.relativePath,
      chunks: [],
      name: "default",
    },
  };
}

// Server module map — used by SSR to resolve server action references in Flight payloads.
// Format is flat: { [moduleId]: { id, chunks } }. The export name comes from the
// "#exportName" suffix in the reference ID, not from the map structure.
const serverModuleMap: Record<string, { id: string; chunks: string[] }> = {};

for (const a of actionFiles) {
  serverModuleMap[a.relativePath] = {
    id: a.relativePath,
    chunks: [],
  };
}

const ssrManifest = {
  moduleMap: ssrModuleMap,
  moduleLoading: null,
  serverModuleMap,
};

writeFileSync(
  join(outDir, "ssr-manifest.json"),
  JSON.stringify(ssrManifest, null, 2)
);
console.log(`Generated: ${join(outDir, "ssr-manifest.json")}`);

// Browser manifest — structured manifest used by PHP for script rendering.
// Contains entry, shared chunks, and per-module chunk URLs.
writeFileSync(
  join(outDir, "browser-manifest.json"),
  JSON.stringify(browserManifest, null, 2)
);
console.log(`Generated: ${join(outDir, "browser-manifest.json")}`);

// ─── CSS Compilation ─────────────────────────────────────────────────────────
// Compile collected CSS files (from server component imports) with Tailwind CSS.
// Output to public/build/css/ and record paths in css-chunks.json for the
// Blade template to link.

if (collectedCssFiles.size > 0) {
  const cssOutDir = join(process.cwd(), "public/build/css");
  rmSync(cssOutDir, { recursive: true, force: true });
  mkdirSync(cssOutDir, { recursive: true });

  const cssFileToUrl = new Map<string, string>();

  for (const cssFile of collectedCssFiles) {
    const name = basename(cssFile, ".css");
    const tmpFile = join(cssOutDir, `${name}.tmp.css`);

    const twProc = Bun.spawn(
      ["npx", "--yes", "@tailwindcss/cli@latest", "-i", cssFile, "-o", tmpFile, "--minify"],
      { cwd: process.cwd(), stdout: "pipe", stderr: "pipe" }
    );

    const twExit = await twProc.exited;
    let cssContent: string;

    if (twExit === 0) {
      cssContent = readFileSync(tmpFile, "utf-8");
    } else {
      const stderr = await new Response(twProc.stderr).text();
      console.warn(`Warning: Tailwind CSS compilation failed for ${cssFile}. Falling back to raw copy.`);
      if (stderr.trim()) console.warn(stderr.trim());
      cssContent = readFileSync(cssFile, "utf-8");
    }

    const hasher = new Bun.CryptoHasher("md5");
    hasher.update(cssContent);
    const hash = hasher.digest("hex").slice(0, 8);

    const hashedName = `${name}-${hash}.css`;
    const outFile = join(cssOutDir, hashedName);
    writeFileSync(outFile, cssContent);
    try { rmSync(tmpFile); } catch {}

    // Copy font/asset files referenced in the compiled CSS
    const urlRefs = cssContent.matchAll(/url\(\.\/([^)]+)\)/g);
    for (const match of urlRefs) {
      const assetRelPath = match[1];
      const assetDest = join(cssOutDir, assetRelPath);
      if (existsSync(assetDest)) continue;

      const searchDirs = [dirname(cssFile), join(process.cwd(), "node_modules")];
      let found = false;

      for (const dir of searchDirs) {
        const assetSrc = join(dir, assetRelPath);
        if (existsSync(assetSrc)) {
          mkdirSync(dirname(assetDest), { recursive: true });
          writeFileSync(assetDest, readFileSync(assetSrc));
          found = true;
          break;
        }
      }

      if (!found) {
        const fileName = basename(assetRelPath);
        const nodeModules = join(process.cwd(), "node_modules");
        const searchGlob = new Bun.Glob(`**/${fileName}`);
        for (const m of searchGlob.scanSync(nodeModules)) {
          mkdirSync(dirname(assetDest), { recursive: true });
          writeFileSync(assetDest, readFileSync(join(nodeModules, m)));
          break;
        }
      }
    }

    cssFileToUrl.set(cssFile, `/build/css/${hashedName}`);
    console.log(`Built CSS: ${outFile}`);
  }

  // Build CSS manifest: maps component names to their CSS URLs.
  // Layout CSS is marked as global (loaded on every page).
  // Page CSS is per-page (loaded only when that page renders).
  const cssManifest: Record<string, string[]> = {};

  for (const [componentName, cssFiles] of cssByComponent) {
    const urls = [...cssFiles]
      .map((f) => cssFileToUrl.get(f))
      .filter((u): u is string => u !== undefined);

    if (urls.length > 0) {
      cssManifest[componentName] = urls;
    }
  }

  writeFileSync(
    join(outDir, "css-manifest.json"),
    JSON.stringify(cssManifest, null, 2)
  );
  console.log(`Generated: ${join(outDir, "css-manifest.json")} (${Object.keys(cssManifest).length} component(s))`);

  // Also write css-chunks.json with ALL CSS for backwards compat / Blade
  const allCssUrls = [...cssFileToUrl.values()];
  writeFileSync(
    join(outDir, "css-chunks.json"),
    JSON.stringify(allCssUrls, null, 2)
  );
}
