<?php

return [
    'label' => 'Error :code',
    'actions' => [
        'login' => 'Return to the login page',
    ],
    'pages' => [
        401 => ['title' => 'Please sign in'],
        402 => ['title' => 'Payment required'],
        403 => ['title' => 'Access unavailable'],
        404 => ['title' => 'Page not found'],
        405 => ['title' => 'Action unavailable'],
        408 => ['title' => 'Request timed out'],
        410 => ['title' => 'Page no longer available'],
        413 => ['title' => 'File too large'],
        419 => ['title' => 'Your session has expired'],
        422 => ['title' => 'Unable to complete the request'],
        429 => ['title' => 'Please wait a moment'],
        500 => ['title' => 'Something went wrong'],
        502 => ['title' => 'Connection interrupted'],
        503 => ['title' => 'We will be back shortly'],
        504 => ['title' => 'The service took too long'],
        '4xx' => ['title' => 'Unable to open this page'],
        '5xx' => ['title' => 'Something went wrong'],
    ],
];
