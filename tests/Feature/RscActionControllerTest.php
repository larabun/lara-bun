<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use LaravelRsc\BunBridge;
use LaravelRsc\Header;
use LaravelRsc\RscRedirectException;

beforeEach(function () {
    $this->bridgeMock = Mockery::mock(BunBridge::class);
    $this->app->instance(BunBridge::class, $this->bridgeMock);
});

function defineLoginRoute(): void
{
    Route::get('/login', fn () => 'login page')->name('login');
    app('router')->getRoutes()->refreshNameLookups();
}

function postAction(mixed $test, string $actionId = 'myAction', string $body = ''): TestResponse
{
    return $test->post('/_rsc/action', [], [
        Header::X_RSC_ACTION => $actionId,
        Header::X_RSC_CONTENT_TYPE => 'text/plain',
        'Content-Type' => 'application/octet-stream',
    ]);
}

test('returns 401 with X-RSC-Redirect to login on AuthenticationException', function () {
    defineLoginRoute();

    $this->bridgeMock
        ->shouldReceive('rscAction')
        ->once()
        ->andReturnUsing(function () {
            return (function () {
                throw new AuthenticationException;
                yield; // @phpstan-ignore deadCode.unreachable
            })();
        });

    $response = postAction($this);

    $response->assertStatus(401)
        ->assertHeader('X-RSC-Redirect', route('login'));
});

test('propagates AuthorizationException to exception handler with 403 status', function () {
    $this->bridgeMock
        ->shouldReceive('rscAction')
        ->once()
        ->andReturnUsing(function () {
            return (function () {
                throw new AuthorizationException('You cannot do this.');
                yield; // @phpstan-ignore deadCode.unreachable
            })();
        });

    $response = postAction($this);

    $response->assertStatus(403);
});

test('propagates AuthorizationException with default message to exception handler', function () {
    $this->bridgeMock
        ->shouldReceive('rscAction')
        ->once()
        ->andReturnUsing(function () {
            return (function () {
                throw new AuthorizationException;
                yield; // @phpstan-ignore deadCode.unreachable
            })();
        });

    $response = postAction($this);

    $response->assertStatus(403);
});

test('returns redirect with X-RSC-Redirect on RscRedirectException', function () {
    $this->bridgeMock
        ->shouldReceive('rscAction')
        ->once()
        ->andReturnUsing(function () {
            return (function () {
                throw new RscRedirectException('/posts/123');
                yield; // @phpstan-ignore deadCode.unreachable
            })();
        });

    $response = postAction($this);

    $response->assertStatus(302)
        ->assertHeader('X-RSC-Redirect', '/posts/123');
});

test('respects custom status code on RscRedirectException', function () {
    $this->bridgeMock
        ->shouldReceive('rscAction')
        ->once()
        ->andReturnUsing(function () {
            return (function () {
                throw new RscRedirectException('/dashboard', 301);
                yield; // @phpstan-ignore deadCode.unreachable
            })();
        });

    $response = postAction($this);

    $response->assertStatus(301)
        ->assertHeader('X-RSC-Redirect', '/dashboard');
});

test('returns 422 JSON with validation errors on ValidationException', function () {
    $this->bridgeMock
        ->shouldReceive('rscAction')
        ->once()
        ->andReturnUsing(function () {
            return (function () {
                throw ValidationException::withMessages([
                    'title' => ['The title field is required.'],
                ]);
                yield; // @phpstan-ignore deadCode.unreachable
            })();
        });

    $response = postAction($this);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'The title field is required.',
            'errors' => [
                'title' => ['The title field is required.'],
            ],
        ]);
});

test('returns 400 when X-RSC-Action header is missing', function () {
    $response = $this->post('/_rsc/action');

    $response->assertStatus(400);
});

test('returns 419 when CSRF token is invalid', function () {
    $this->app['env'] = 'production';

    $response = $this->post('/_rsc/action', [], [
        Header::X_RSC_ACTION => 'myAction',
        Header::X_RSC_CONTENT_TYPE => 'text/plain',
        'Content-Type' => 'application/octet-stream',
        'X-CSRF-TOKEN' => 'invalid-token',
    ]);

    $response->assertStatus(419);
});

test('streams successful action response', function () {
    $this->bridgeMock
        ->shouldReceive('rscAction')
        ->once()
        ->andReturnUsing(function () {
            return (function () {
                yield 'chunk-1';
                yield 'chunk-2';
            })();
        });

    $response = postAction($this);

    $response->assertStatus(200)
        ->assertHeader('Content-Type', 'text/x-component; charset=utf-8');
});

// ─── File uploads ────────────────────────────────────────────────────────────

test('forwards a binary request body to the action unchanged', function () {
    // Multipart uploads arrive as raw bytes. The controller must hand them to
    // the bridge verbatim — BunBridge base64-encodes for the socket hop, so
    // anything mangled here is mangled by the time the action sees it.
    $binary = "\x00\x01\x02\xFF\xFEPNG\r\n\x1A\n".random_bytes(64);
    $seen = null;

    $this->bridgeMock
        ->shouldReceive('rscAction')
        ->once()
        ->andReturnUsing(function (string $actionId, string $body, string $contentType) use (&$seen) {
            $seen = $body;

            return (function () {
                yield '0:{"ok":true}';
            })();
        });

    $this->call('POST', '/_rsc/action', [], [], [], [
        'HTTP_'.str_replace('-', '_', strtoupper(Header::X_RSC_ACTION)) => 'uploadAvatar',
        'HTTP_'.str_replace('-', '_', strtoupper(Header::X_RSC_CONTENT_TYPE)) => 'multipart/form-data; boundary=xyz',
        'CONTENT_TYPE' => 'application/octet-stream',
    ], $binary);

    expect($seen)->toBe($binary);
});

test('passes the real content type through the opaque header', function () {
    // The browser sends an opaque Content-Type so PHP does not consume
    // php://input; the true type travels in X-RSC-Content-Type.
    $seen = null;

    $this->bridgeMock
        ->shouldReceive('rscAction')
        ->once()
        ->andReturnUsing(function (string $actionId, string $body, string $contentType) use (&$seen) {
            $seen = $contentType;

            return (function () {
                yield '0:{}';
            })();
        });

    $this->call('POST', '/_rsc/action', [], [], [], [
        'HTTP_'.str_replace('-', '_', strtoupper(Header::X_RSC_ACTION)) => 'uploadAvatar',
        'HTTP_'.str_replace('-', '_', strtoupper(Header::X_RSC_CONTENT_TYPE)) => 'multipart/form-data; boundary=xyz',
        'CONTENT_TYPE' => 'application/octet-stream',
    ], 'body');

    expect($seen)->toBe('multipart/form-data; boundary=xyz');
});
