<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$org = App\Models\Organisation::first();
if (!$org) {
    die("No organisation found.\n");
}
$url = rtrim($org->ebio_url, '/') . '/webservice.asmx';
$username = $org->ebio_soap_username;
$password = $org->ebio_soap_password;
$empCode = '1010';

echo "Requesting URL: $url\n";
echo "Username: $username\n";

$xml = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <GetEmployeeDetails xmlns="http://tempuri.org/">
      <UserName>'.$username.'</UserName>
      <Password>'.$password.'</Password>
      <EmployeeCode>'.$empCode.'</EmployeeCode>
    </GetEmployeeDetails>
  </soap:Body>
</soap:Envelope>';

$response = \Illuminate\Support\Facades\Http::withHeaders([
    'Content-Type' => 'text/xml; charset=utf-8',
    'SOAPAction' => '"http://tempuri.org/GetEmployeeDetails"'
])->send('POST', $url, [
    'body' => $xml
]);

echo "Status: " . $response->status() . "\n";
echo "Response Body:\n" . $response->body() . "\n";
