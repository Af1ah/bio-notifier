<?php

return [
    'default_latitude' => (float) env('MAP_DEFAULT_LATITUDE', 20.5937),
    'default_longitude' => (float) env('MAP_DEFAULT_LONGITUDE', 78.9629),
    'tile_url' => env('MAP_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
];
