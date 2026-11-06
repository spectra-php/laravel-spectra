<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $dashboard->name }}</title>

    {!! \Spectra\Support\DashboardAssets::css() !!}
</head>
<body>
    <div id="spectra"></div>

    {!! \Spectra\Support\DashboardAssets::viewerJs($dashboard) !!}
</body>
</html>
