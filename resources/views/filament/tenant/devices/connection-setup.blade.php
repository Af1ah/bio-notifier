<div class="space-y-4">
    <p>On your device, open Cloud Communication or Server Settings and enter the following details.</p>
    @if($server)
        <dl class="grid grid-cols-2 gap-3 text-sm">
            <dt>Server address</dt><dd class="font-medium">{{ $server }}</dd>
            <dt>Port</dt><dd class="font-medium">{{ $port }}</dd>
            <dt>HTTPS</dt><dd class="font-medium">{{ $secure ? 'On' : 'Off' }}</dd>
            <dt>Serial number</dt><dd class="font-medium">{{ $serial }}</dd>
        </dl>
        <p>Save the settings on the device. Its status changes to Connected after it contacts the server.</p>
    @else
        <p>Your administrator needs to finish the server setup before this device can connect.</p>
    @endif
    @if(!$ready)<p>Registration is being prepared. If this continues, contact your administrator.</p>@endif
    <p>Attendance uploads appear in Attendance logs. Device users are listed separately from payroll employees.</p>
</div>
