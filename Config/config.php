<?php
return [
    'name'        => 'AWS',
    'description' => 'Handle AWS Callback',
    'version'     => '1.0',
    'author'      => 'Hachther LLC',

    'routes' => [
        'public' => [
            'mautic_mailer_transport_callback' => [
                'path'       => '/mailer/{transport}/callback',
                'controller' => 'MauticPlugin\MauticAWSBundle\Controller\PublicController::mailerCallbackAction',
                'method'     => ['GET', 'POST'],
            ],
        ]
    ]
];