<?php

/**
 * Writing the prerendered site out as files a static host can serve.
 *
 * The refusal is the part worth pinning. A partially prerendered route ships a
 * shell whose remainder is fetched at request time, so exporting one produces
 * a page that loads and then stays empty — a failure that looks like a working
 * deploy.
 */

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

function prerendered(array $files): string
{
    $dir = sys_get_temp_dir().'/rsc-export-'.uniqid();
    File::ensureDirectoryExists($dir);

    foreach ($files as $path => $contents) {
        File::ensureDirectoryExists(dirname($dir.'/'.$path));
        File::put($dir.'/'.$path, $contents);
    }

    Config::set('rsc.static_path', $dir);

    return $dir;
}

afterEach(function () {
    File::deleteDirectory(base_path('dist-test'));
});

test('each route becomes a directory with an index, so urls stay extensionless', function () {
    $source = prerendered([
        'index.html' => '<html>home</html>',
        'index.flight' => 'home payload',
        'docs/rsc.html' => '<html>rsc</html>',
        'docs/rsc.flight' => 'rsc payload',
    ]);

    $out = base_path('dist-test');
    $this->artisan('rsc:export', ['--out' => 'dist-test'])->assertSuccessful();

    expect(file_get_contents($out.'/index.html'))->toBe('<html>home</html>')
        ->and(file_get_contents($out.'/docs/rsc/index.html'))->toBe('<html>rsc</html>');

    File::deleteDirectory($source);
});

test('the payload is written beside the page it belongs to', function () {
    // A static host cannot tell a payload request from a page request, so the
    // payload needs an address rather than a header.
    $source = prerendered([
        'docs/rsc.html' => '<html>rsc</html>',
        'docs/rsc.flight' => 'rsc payload',
    ]);

    $this->artisan('rsc:export', ['--out' => 'dist-test'])->assertSuccessful();

    expect(file_get_contents(base_path('dist-test/docs/rsc/index.rsc')))->toBe('rsc payload');

    File::deleteDirectory($source);
});

test('it refuses routes that are rendered per request', function () {
    $source = prerendered([
        'docs/rsc.html' => '<html>rsc</html>',
        'docs/live.ppr.html' => '<html>shell</html>',
    ]);

    $this->artisan('rsc:export', ['--out' => 'dist-test'])
        ->expectsOutputToContain('/docs/live')
        ->assertFailed();

    expect(is_dir(base_path('dist-test')))->toBeFalse();

    File::deleteDirectory($source);
});

test('--force exports the rest and says what is broken', function () {
    $source = prerendered([
        'docs/rsc.html' => '<html>rsc</html>',
        'docs/live.ppr.html' => '<html>shell</html>',
    ]);

    $this->artisan('rsc:export', ['--out' => 'dist-test', '--force' => true])->assertSuccessful();

    expect(is_file(base_path('dist-test/docs/rsc/index.html')))->toBeTrue()
        // The shell is not a page: exporting it would ship something that
        // loads and never finishes.
        ->and(is_file(base_path('dist-test/docs/live/index.html')))->toBeFalse();

    File::deleteDirectory($source);
});

test('it says so when nothing has been prerendered', function () {
    Config::set('rsc.static_path', sys_get_temp_dir().'/rsc-export-missing-'.uniqid());

    $this->artisan('rsc:export', ['--out' => 'dist-test'])
        ->expectsOutputToContain('rsc:build')
        ->assertFailed();
});

test('the output directory defaults to the configured one', function () {
    // So `output => export` is the only thing an app has to declare, the way
    // next.config declares it rather than the build command carrying it.
    $source = prerendered(['docs/rsc.html' => '<html>rsc</html>']);

    Config::set('rsc.export_path', 'dist-test');

    $this->artisan('rsc:export')->assertSuccessful();

    expect(is_file(base_path('dist-test/docs/rsc/index.html')))->toBeTrue();

    File::deleteDirectory($source);
});
