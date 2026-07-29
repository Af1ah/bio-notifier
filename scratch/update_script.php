<?php
$json = '{"breaks": [{"id": "brk_1", "is_active": true}]}';
$data = json_decode($json, true);
$breaks = $data['breaks'];

$is_assoc = false;
if (is_array($breaks)) {
    $is_assoc = array_keys($breaks) !== range(0, count($breaks) - 1);
}
var_dump($is_assoc);
