/**
 * File watcher for RSC development with live reload.
 *
 * Watches the RSC source directory, re-runs build-rsc.ts on changes,
 * and notifies connected browsers via WebSocket to re-fetch the page
 * through RSC navigation (preserving client component state).
 *
 * Usage:
 *   bun <this-script> [source-dir]
 */

import { watch } from "node:fs";
import { join } from "node:path";

import { writeFileSync, unlinkSync } from "node:fs";

const sourceDir = process.argv[2] ?? join(process.cwd(), "resources/js/rsc");
const buildScript = join(import.meta.dir, "build-rsc.ts");
const bunPath = process.execPath;
const HMR_PORT = parseInt(process.env.RSC_HMR_PORT ?? "3001", 10);
const devFlagPath = join(process.cwd(), "storage/framework/rsc-dev");

// ─── WebSocket Server for Live Reload ───────────────────────────────────────

const clients = new Set<import("bun").ServerWebSocket<unknown>>();

const wsServer = Bun.serve({
  port: HMR_PORT,
  fetch(req, server) {
    if (server.upgrade(req)) return;
    return new Response("RSC HMR", { status: 200 });
  },
  websocket: {
    open(ws) {
      clients.add(ws);
    },
    close(ws) {
      clients.delete(ws);
    },
    message() {
      // No client → server messages needed
    },
  },
});

// Write dev flag so PHP knows the watcher is running
writeFileSync(devFlagPath, String(wsServer.port));
console.log(`HMR WebSocket running on ws://localhost:${wsServer.port}`);

function notifyClients(): void {
  for (const ws of clients) {
    try {
      ws.send("reload");
    } catch {
      clients.delete(ws);
    }
  }
}

// ─── Build Runner ───────────────────────────────────────────────────────────

let debounceTimer: ReturnType<typeof setTimeout> | null = null;
let building = false;
let pendingRebuild = false;

async function runBuild(): Promise<void> {
  if (building) {
    pendingRebuild = true;
    return;
  }

  building = true;

  const proc = Bun.spawn([bunPath, buildScript], {
    cwd: process.cwd(),
    stdout: "pipe",
    stderr: "pipe",
    env: { ...process.env, NODE_ENV: "development" },
  });

  const [stdout, stderr] = await Promise.all([
    new Response(proc.stdout).text(),
    new Response(proc.stderr).text(),
  ]);
  const exitCode = await proc.exited;

  building = false;

  if (exitCode === 0) {
    console.log(`Rebuilt successfully.`);
    // Wait for bun:serve --watch to restart with the new bundle
    await Bun.sleep(500);
    notifyClients();
  } else {
    console.error("\n--- Build failed ---\n");
    if (stdout) console.log(stdout);
    if (stderr) console.error(stderr);
  }

  if (pendingRebuild) {
    pendingRebuild = false;
    runBuild();
  }
}

// ─── File Watcher ───────────────────────────────────────────────────────────

function onFileChange(filename: string | null): void {
  if (
    filename &&
    (filename.includes(".generated.") ||
      filename === "env.d.ts" ||
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

const watcher = watch(sourceDir, { recursive: true }, (_event, filename) => {
  onFileChange(filename as string | null);
});

console.log(`Watching ${sourceDir} for changes...`);

function shutdown(): void {
  watcher.close();
  wsServer.stop();
  try { unlinkSync(devFlagPath); } catch {}
  process.exit(0);
}

process.on("SIGINT", shutdown);
process.on("SIGTERM", shutdown);
