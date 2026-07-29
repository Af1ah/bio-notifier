<?php
$models = ['Device.php', 'AttendanceLog.php', 'DeviceCommand.php'];
foreach ($models as $model) {
    $path = "c:/Users/Aflah/projects/zkteco-local/app/Models/" . $model;
    $content = file_get_contents($path);
    $content = str_replace("namespace Syofyanzuhad\FilamentZktecoAdms\Models;", "namespace App\Models;", $content);
    
    // Replace config calls with hardcoded table names for the monolith
    $content = preg_replace('/return config\([^)]+\) \. \'([a-z_]+)\';/', "return '$1';", $content);
    
    file_put_contents($path, $content);
}
echo "Models updated.";
