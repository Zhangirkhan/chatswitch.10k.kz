<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Swagger / OpenAPI (HTTP Basic Auth)
    |--------------------------------------------------------------------------
    |
    | Доступ к /docs/api и /docs/api/openapi.yaml на поддомене тенанта.
    | Логин: DOCS_API_USERNAME (по умолчанию docs), пароль: DOCS_API_PASSWORD.
    |
    */

    'api_username' => env('DOCS_API_USERNAME', 'docs'),

    'api_password' => env('DOCS_API_PASSWORD'),

];
