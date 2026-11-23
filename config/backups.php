<?php

return [
    'google_client_id' => env('BACKUP_GOOGLE_CLIENT_ID'),
    'google_client_secret' => env('BACKUP_GOOGLE_CLIENT_SECRET'),
    'google_redirect_uri' => env('BACKUP_GOOGLE_REDIRECT_URI', rtrim(env('APP_URL', 'http://localhost'), '/').'/oauth/google-drive/callback'),
];
