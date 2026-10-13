<?php

return [
    'auto_start_worker' => env('CRAWLER_AUTO_START_WORKER', true),
    'user_agent' => 'LinkBuildingDiscover/1.0',
    'timeout' => 12,
    'max_redirects' => 5,
    'max_bytes' => 3 * 1024 * 1024,
    'max_links' => 10000,
    'delay_ms' => 500,
    // Keep disabled outside isolated test environments.
    'allow_private_networks' => false,
];
