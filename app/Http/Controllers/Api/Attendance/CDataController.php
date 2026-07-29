<?php

namespace App\Http\Controllers\Api\Attendance;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use App\Events\AttendanceReceived;
use App\Events\DeviceConnected;
use App\Events\UserSynced;
use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\User;
use App\Services\Attendance\AdmsRequestParser;
use App\Services\Attendance\AdmsResponseBuilder;

class CDataController extends Controller
{
    public function __construct(
        protected AdmsRequestParser $parser,
        protected AdmsResponseBuilder $responseBuilder
    ) {}

    public function __invoke(Request $request): Response
    {
        $serialNumber = $request->query('SN');
        $table = $request->query('table');
        $options = $request->query('options');

        if (! $serialNumber) {
            return $this->responseBuilder->error();
        }

        $device = $this->findOrCreateDevice($serialNumber, $request);

        if (! $device) {
            return $this->responseBuilder->error();
        }

        $device->markAsOnline();

        // GET request with options=all is device registration/config request
        if ($request->isMethod('GET') && $options === 'all') {
            return $this->handleOptionsRequest($device, $request);
        }

        // POST request with table parameter is data submission
        if ($request->isMethod('POST') && $table) {
            return $this->handleDataSubmission($device, $table, $request);
        }

        return $this->responseBuilder->ok();
    }

    protected function handleOptionsRequest(Device $device, Request $request): Response
    {
        $device->update([
            'push_version' => $request->query('pushver'),
            'device_type' => $request->query('DeviceType'),
            'firmware_version' => $request->query('FWVersion'),
        ]);

        if (config('zkteco-adms.events.dispatch_device_connected', true)) {
            event(new DeviceConnected($device));
        }

        return $this->responseBuilder->deviceOptions($device);
    }

    protected function handleDataSubmission(Device $device, string $table, Request $request): Response
    {
        $body = $request->getContent();
        $stamp = $request->query('Stamp');
        

        $tenantId = null;
        if (function_exists('tenant') && tenant('id')) {
            $tenantId = tenant('id');
        }

        $payload = \App\Models\AdmsPayload::create([
            'serial_number' => $device->serial_number,
            'tenant_id' => $tenantId,
            'table_name' => $table,
            'stamp' => $stamp,
            'payload' => $body,
            'status' => 'pending',
        ]);

        \App\Jobs\ProcessAdmsPayload::dispatch($payload);

        return $this->responseBuilder->ok();
    }

    protected function findOrCreateDevice(string $serialNumber, Request $request): ?Device
    {
        $modelClass = config('zkteco-adms.models.device', Device::class);

        $device = $modelClass::where('serial_number', $serialNumber)->first();

        if (! $device && config('zkteco-adms.device.auto_register', true)) {
            $device = $modelClass::create([
                'serial_number' => $serialNumber,
                'name' => "Device {$serialNumber}",
                'ip_address' => $request->ip(),
                'status' => 'online',
            ]);
        }

        return $device;
    }
}
