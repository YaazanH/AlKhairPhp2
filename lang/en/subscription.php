<?php

return [
    'suspended' => [
        'title' => 'Service temporarily unavailable',
        'message' => ':organisation is currently unavailable. Please contact your organisation administrator for assistance.',
    ],
    'notice' => [
        'expiring' => [
            'title' => 'Subscription renewal needed',
            'message' => 'Your organisation subscription expires on :date. Contact the Platform administrator to arrange renewal.',
        ],
        'grace' => [
            'title' => 'Subscription is in its grace period',
            'message' => 'Your organisation subscription will be suspended on :date unless it is renewed. Contact the Platform administrator.',
        ],
    ],
];
