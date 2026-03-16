/**
 * File watcher for RSC development.
 *
 * Watches the RSC source directory and re-runs build-rsc.ts on changes.
 * Uses Bun's native fs.watch with debouncing to avoid redundant rebuilds.
 *
 * Usage:
 *   bun <this-script> [source-dir]
 */

import { watch } from "node:fs";
import { join } from "node:path";
import { Glob } from "bun";

const sourceDir = process.argv[2] ?? join(process.cwd(), "resources/js/rsc");
const buildScript = join(import.meta.dir, "build-rsc.ts");
const bunPath = process.execPath;

let debounceTimer: ReturnType<typeof setTimeout> | null = null;
let building = false;
let pendingRebuild = false;

async function runBuild(): Promise<void> {
  if (building) {
    pendingRebuild = true;
    return;
  }

  building = true;
  console.log("\n--- Rebuilding... ---\n");

  const proc = Bun.spawn([bunPath, buildScript], {
    cwd: process.cwd(),
    stdout: "inherit",
    stderr: "inherit",
  });

  await proc.exited;

  building = false;

  if (pendingRebuild) {
    pendingRebuild = false;
    runBuild();
  }
}

function onFileChange(filename: string | null): void {
  // Ignore generated files and hidden files
  if (
    filename &&
    (filename.includes(".generated.") ||
      filename.startsWith(".") ||
      filename.includes("node_modules"))
  ) {
    return;
  }

  if (debounceTimer) {
    clearTimeout(debounceTimer);
  }

  debounceTimer = setTimeout(() => {
    debounceTimer = null;
    runBuild();
  }, 100);
}

// Watch source directory recursively
const watcher = watch(sourceDir, { recursive: true }, (_event, filename) => {
  onFileChange(filename as string | null);
});

// Also watch route.php files in the app directory
const appDir = join(sourceDir, "app");
const routeGlob = new Glob("**/route.php");

try {
  for await (const _ of routeGlob.scan(appDir)) {
    // The recursive watcher on sourceDir already covers these
    break;
  }
} catch {
  // app/ directory might not exist yet
}

console.log(`Watching ${sourceDir} for changes...`);

process.on("SIGINT", () => {
  watcher.close();
  process.exit(0);
});

process.on("SIGTERM", () => {
  watcher.close();
  process.exit(0);
});
