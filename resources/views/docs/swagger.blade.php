<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.min.css" crossorigin="anonymous">
    <style>body { margin: 0; }</style>
</head>
<body>
<div id="swagger-ui"></div>
<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.min.js" crossorigin="anonymous"></script>
<script>
    window.onload = function () {
        window.ui = SwaggerUIBundle({
            url: @json($openApiUrl),
            dom_id: '#swagger-ui',
            deepLinking: true,
            persistAuthorization: true,
        });
    };
</script>
</body>
</html>
