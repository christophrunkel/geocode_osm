<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'OpenStreetMap Geocoding Task',
    'description' => 'This extension provides a task that generates tt_address geocoding information via OpenStreetMap instead of GoogleMaps',
    'category' => 'services',
    'author' => 'Christoph Runkel',
    'author_email' => 'dialog@christophrunkel.de',
    'state' => 'alpha',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-13.4.99',
            'tt_address' => '8.0.0-9.9.9',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
