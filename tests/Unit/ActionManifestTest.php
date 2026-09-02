<?php

/**
 * The generated "use server" module is the only place the host global's name is
 * written into app-facing code, and nothing typechecks it.
 *
 * When the global was renamed php() → rpc(), this file kept calling the old one
 * and no test noticed: it built cleanly, and only failed when an action was
 * actually submitted in a browser.
 */

use Illuminate\Support\Facades\Config;
use LaravelRsc\Support\ActionManifest;

test('generated actions call the configured host global', function () {
    $source = ActionManifest::render(['todosAdd' => 'Todos.add'], 'rpc');

    expect($source)->toContain('(globalThis as any).rpc("Todos.add", ...args)')
        ->and($source)->not->toContain('.php(');
});

test('renaming the host global renames it everywhere it is called', function () {
    // The rename that broke this only touched some call sites. Nothing may
    // hardcode the name — it comes from config, so one change moves all of it.
    $source = ActionManifest::render(['todosAdd' => 'Todos.add', 'todosList' => 'Todos.list'], 'callHost');

    expect(substr_count($source, '(globalThis as any).callHost('))->toBe(2)
        ->and($source)->not->toContain('rpc(');
});

test('the module is marked "use server" so the bundler treats it as an action', function () {
    expect(ActionManifest::render(['todosAdd' => 'Todos.add'], 'rpc'))
        ->toStartWith('"use server";');
});

test('every discovered action becomes an exported async function', function () {
    $source = ActionManifest::render([
        'todosAdd' => 'Todos.add',
        'todosDelete' => 'Todos.delete',
    ], 'rpc');

    expect($source)->toContain('export async function todosAdd(...args: unknown[]) {')
        ->and($source)->toContain('export async function todosDelete(...args: unknown[]) {')
        ->and($source)->toContain('"Todos.delete"');
});

test('no actions renders an empty module rather than broken syntax', function () {
    expect(ActionManifest::render([], 'rpc'))->not->toContain('export async function');
});

test('the ambient declaration is a script, not a module', function () {
    // With an import or export the file becomes a module and `declare function`
    // stops being global — the declaration would exist but reach nothing.
    $types = ActionManifest::renderTypes('rpc');

    $statements = array_filter(
        array_map('trim', explode("\n", $types)),
        fn (string $line) => str_starts_with($line, 'export') || str_starts_with($line, 'import'),
    );

    expect($types)->toContain('declare function rpc<T = unknown>(name: string, ...args: unknown[]): Promise<T>;')
        ->and($statements)->toBe([]);
});

test('the engine ships its own ambient types without redeclaring the host global', function () {
    // Two ambient declarations of the same function conflict. The engine owns
    // Metadata; the host owns the global, whose name it alone knows.
    $engineTypes = file_get_contents(__DIR__.'/../../resources/types.d.ts');

    expect($engineTypes)->toContain('interface Metadata')
        ->and($engineTypes)->toContain('type GenerateMetadata')
        ->and($engineTypes)->not->toContain('declare function rpc');
});
