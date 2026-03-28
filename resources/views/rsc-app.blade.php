<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @foreach (($cssLinks ?? []) as $cssLink)
        <link rel="stylesheet" href="{{ $cssLink }}" fetchpriority="high" data-rsc-css>
    @endforeach
    @rscHead
</head>

<body>
    <div id="rsc-root">{!! $body !!}</div>

    <script @rscNonce>
        window.__RSC_INITIAL__ = {!! $initialJson !!};
    </script>

    {!! $scripts !!}
</body>

</html>
