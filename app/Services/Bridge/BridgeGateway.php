<?php
namespace App\Services\Bridge;
use App\Models\Device;
use App\Models\SiteBridge;
use Illuminate\Support\Facades\Http;
class BridgeGateway {
    public function approvedTarget(Device $device): array {
        $tenant=tenant(); if (!$tenant) throw new \RuntimeException('Remote devices require an initialized tenant.');
        $bridge=SiteBridge::where('tenant_id',$tenant->getTenantKey())->whereNull('revoked_at')->find($device->site_bridge_id);
        if (!$bridge || !$bridge->public_key) throw new \RuntimeException('This device does not have an active paired bridge.');
        $ip=(string)$device->ip_address; $port=(int)($device->port?:80);
        if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4) || !str_starts_with($ip,'192.168.') || $port!==80 || $device->vendor!=='matrix' || ($device->protocol?:'http')!=='http')
            throw new \RuntimeException('Remote Matrix targets currently require an approved 192.168.x.x address on HTTP port 80.');
        if (Device::where('site_bridge_id',$bridge->id)->where('connection_mode','remote')->where('id','!=',$device->getKey()??0)->count()>=config('bridge.max_devices')) throw new \RuntimeException('Site device limit exceeded.');
        return ['bridge_id'=>$bridge->id,'tenant_id'=>(string)$tenant->getTenantKey(),'device_id'=>(string)$device->id,'ip'=>$ip,'port'=>$port];
    }
    public function endpoint(Device $device): string {
        $result=$this->call('endpoint',$this->approvedTarget($device));
        $port=(int)($result['port']??0);
        if ($port<1024 || $port>65535) throw new \RuntimeException('Invalid private bridge endpoint.');
        return 'http://127.0.0.1:'.$port;
    }
    public function webUrl(Device $device): string {
        $target=$this->approvedTarget($device);
        $target['web_host']=$this->webHost($device);
        $result=$this->call('web-session',$target);
        return 'https://'.$target['web_host'].'/launch/'.$result['token'];
    }
    public function webHost(Device $device): string {
        $tenant=tenant(); if (!$tenant || !$device->getKey()) throw new \RuntimeException('Save the device in its organisation first.');
        return (string) parse_url(config('bridge.public_url'), PHP_URL_HOST);
    }
    public function revoke(SiteBridge $bridge): void { $this->call('revoke',['bridge_id'=>$bridge->id]); }
    private function call(string $path,array $data): array {
        $key=config('bridge.gateway_key'); if (!is_string($key) || strlen($key)<32) throw new \RuntimeException('Bridge gateway is not configured.');
        $response=Http::withHeaders(['X-Bridge-Gateway-Key'=>$key])->connectTimeout(2)->timeout(5)->post(rtrim(config('bridge.gateway'),'/').'/'.$path,$data);
        if (!$response->successful()) throw new \RuntimeException('Remote bridge is unavailable or the device request was rejected.');
        return $response->json();
    }
}
