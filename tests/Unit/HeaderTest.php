<?php

use RscKit\Header;

test('header constants have expected values', function () {
    expect(Header::X_RSC)->toBe('X-RSC')
        ->and(Header::X_RSC_VERSION)->toBe('X-RSC-Version')
        ->and(Header::X_RSC_LOCATION)->toBe('X-RSC-Location')
        ->and(Header::X_RSC_ACTION)->toBe('X-RSC-Action')
        ->and(Header::X_RSC_CONTENT_TYPE)->toBe('X-RSC-Content-Type')
        ->and(Header::X_RSC_INTERCEPT)->toBe('X-RSC-Intercept')
        ->and(Header::X_RSC_REFERER)->toBe('X-RSC-Referer');
});
