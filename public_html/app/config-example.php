<?php
return [
    'blizzard' => [
        'client_id' => getenv('BLIZZARD_CLIENT_ID')?: 'your client id here',
        'client_secret' => getenv('BLIZZARD_CLIENT_SECRET')?: 'your client secret here',
        'region' => 'eu',
        'cache_path' => '/tmp/', // or __DIR__.'/cache/' if writable
    ],
    'display' => [
        'show_icons' => true,
        'icon_size' => 18,
        'show_wowhead_link' => true,
    ],
    'hardcoded_talents' => [
        // Blizzard API edge cases that don't return tooltips
        99849 => 'Grimoire of Sacrifice',
        99850 => 'Grimoire of Service',
        99851 => 'Grimoire of Supremacy',
    ]
];