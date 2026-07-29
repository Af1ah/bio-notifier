<?php

$resourcesDir = 'c:/Users/Aflah/projects/zkteco-local/app/Filament/Resources/';

// 1. Rename User resource
if (file_exists($resourcesDir . 'ZktecoUserResource.php')) {
    rename($resourcesDir . 'ZktecoUserResource.php', $resourcesDir . 'UserResource.php');
}
if (is_dir($resourcesDir . 'ZktecoUserResource')) {
    rename($resourcesDir . 'ZktecoUserResource', $resourcesDir . 'UserResource');
}

// Rename the Pages for UserResource
$userPagesDir = $resourcesDir . 'UserResource/Pages/';
$renames = [
    'CreateZktecoUser.php' => 'CreateUser.php',
    'EditZktecoUser.php' => 'EditUser.php',
    'ListZktecoUsers.php' => 'ListUsers.php',
    'ViewZktecoUser.php' => 'ViewUser.php',
];
foreach ($renames as $old => $new) {
    if (file_exists($userPagesDir . $old)) {
        rename($userPagesDir . $old, $userPagesDir . $new);
    }
}

// 2. Iterate over all files and replace namespaces and class names
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resourcesDir));
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $content = file_get_contents($file->getPathname());
        
        $replacements = [
            'Syofyanzuhad\FilamentZktecoAdms\Filament\Resources' => 'App\Filament\Resources',
            'Syofyanzuhad\FilamentZktecoAdms\Models\ZktecoUser' => 'App\Models\User',
            'Syofyanzuhad\FilamentZktecoAdms\Models\Device' => 'App\Models\Device',
            'Syofyanzuhad\FilamentZktecoAdms\Models\AttendanceLog' => 'App\Models\AttendanceLog',
            'Syofyanzuhad\FilamentZktecoAdms\Models\DeviceCommand' => 'App\Models\DeviceCommand',
            'ZktecoUserResource' => 'UserResource',
            'ZktecoUser' => 'User',
            'ZKTeco Users' => 'Users',
            'ZKTeco User' => 'User',
            'ZKTeco ADMS' => 'Attendance', // Changed navigation group
            'ZKTeco' => '',
            'zkteco-users' => 'users',
            'CreateZktecoUser' => 'CreateUser',
            'EditZktecoUser' => 'EditUser',
            'ListZktecoUsers' => 'ListUsers',
            'ViewZktecoUser' => 'ViewUser',
        ];
        
        $content = strtr($content, $replacements);
        file_put_contents($file->getPathname(), $content);
    }
}

echo "Filament resources updated.";
