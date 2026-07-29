<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$org = App\Models\Organisation::first();
$url = rtrim($org->ebio_url, '/') . '/webservice.asmx';
$username = $org->ebio_soap_username;
$password = $org->ebio_soap_password;

$xml = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <GetDeviceList xmlns="http://tempuri.org/">
      <UserName>'.$username.'</UserName>
      <Password>'.$password.'</Password>
      <Location></Location>
    </GetDeviceList>
  </soap:Body>
</soap:Envelope>';

$response = \Illuminate\Support\Facades\Http::withHeaders([
    'Content-Type' => 'text/xml; charset=utf-8',
    'SOAPAction' => '"http://tempuri.org/GetDeviceList"'
])->send('POST', $url, [
    'body' => $xml
]);

echo $response->body() . "\n";
