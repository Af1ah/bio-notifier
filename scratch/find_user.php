<?php
$tenants = \App\Models\Organisation::all();
foreach($tenants as $t) {
    tenancy()->initialize($t);
    echo "Tenant: " . $t->shortname . "\n";
    $user = \App\Models\User::where('email', 'secumaxindia@gmail.com')->first();
    if ($user) {
        print_r($user->toArray());
    }
}
