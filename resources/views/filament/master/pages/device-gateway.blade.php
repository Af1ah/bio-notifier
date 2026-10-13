<x-filament-panels::page>
    <x-filament::section heading="Attendance service" description="Manage the shared server here. Company administrators manage their devices, attendance logs and device actions in their company account.">
        <p>Registered devices: {{ $bindingCount }}</p>
        <p class="mt-2">Open a company from Organisations and use Quick login to manage its devices.</p>
    </x-filament::section>
    <x-filament::section heading="Connection settings" description="Server configuration for administrators." collapsible collapsed>
    <form wire:submit="saveConnection" class="space-y-4">
        {{ $this->connectionForm }}
        <x-filament::button type="submit">Save connection</x-filament::button>
        <x-filament::button type="button" color="gray" wire:click="checkHealth">Check health</x-filament::button>
        @if($health)<p>Gateway status: {{ $health['status'] ?? 'unknown' }}</p>@endif
    </form>
    </x-filament::section>
    <x-filament::section heading="Registered devices" description="Companies register devices, view attendance and send device actions from their own Devices page." collapsible collapsed>
        <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th>Serial number</th><th>Company</th><th>Registration</th><th>Recovery</th></tr></thead><tbody>
        @foreach($bindings as $binding)<tr><td class="py-2">{{ $binding->serial }}</td><td>{{ $inspectionCompanies[$binding->organisation_id] ?? 'Company' }}</td><td>{{ $binding->synced_at ? 'Ready' : ($binding->last_error ? 'Needs attention' : 'Preparing') }}</td><td>@if(!$binding->synced_at)<x-filament::button size="sm" wire:click="retryBinding({{ $binding->id }})">Retry registration</x-filament::button>@endif</td></tr>@endforeach
        </tbody></table></div>
    </x-filament::section>
    <x-filament::section heading="Queue and delivery board" description="Advanced diagnostics and recovery for administrators." collapsible collapsed wire:poll.30s>
        <p class="mb-4">Acceptance, attendance application and device confirmation are separate stages. Parked work needs review before replay.</p>
        <div class="mb-4"><h3 class="font-semibold">Worker and scheduler health</h3>
        @foreach(['scheduler', 'device-ingest', 'device-commands', 'attendance-live', 'exports-notifications'] as $expectedRole)
            @if(!$runtimeStates->contains('role', $expectedRole))<p>{{ $expectedRole }}: no heartbeat reported</p>@endif
        @endforeach
        @foreach($runtimeStates as $runtime)<p>{{ $runtime->role }} {{ $runtime->organisation_id }}: {{ \Carbon\Carbon::parse($runtime->heartbeat_at)->diffForHumans() }} @if(\Carbon\Carbon::parse($runtime->heartbeat_at)->lt(now()->subMinutes(3))) · needs attention @endif</p>@endforeach
        </div>
        <div class="grid gap-4 md:grid-cols-3">
            <div><h3 class="font-semibold">Receipt application</h3>@foreach($receiptCounts as $state => $count)<p>{{ $state }}: {{ $count }}</p>@endforeach</div>
            <div><h3 class="font-semibold">Command submission</h3>@foreach($commandCounts as $state => $count)<p>{{ $state }}: {{ $count }}</p>@endforeach</div>
            <div><h3 class="font-semibold">Worker queues</h3>@foreach($queues as $queue)<p>{{ $queue->queue }}: {{ $queue->total }} · oldest {{ \Carbon\Carbon::createFromTimestamp($queue->oldest)->diffForHumans() }}</p>@endforeach</div>
        </div>
        @if(isset($health['events']))<div class="mt-4"><h3 class="font-semibold">Gateway delivery</h3>@foreach($health['events'] as $state => $details)<p>{{ $state }}: {{ $details['count'] }} · oldest {{ $details['oldest_at'] }}</p>@endforeach</div>@endif
        @if(isset($health['delivery_workers']))<p>Gateway delivery workers reporting: {{ $health['delivery_workers'] }}</p>@endif
        @foreach($gatewayEvents as $event)<p class="mt-2">{{ $event['serial'] }} · {{ $event['id'] }} · {{ $event['state'] }} · {{ $event['error_code'] }} @if(in_array($event['state'], ['parked', 'quarantined']))<x-filament::button size="sm" wire:click="replayGatewayEvent('{{ $event['id'] }}')" wire:confirm="Replay this event after fixing the cause? Unsupported or invalid payloads remain quarantined.">Replay event</x-filament::button>@endif</p>@endforeach
        <div class="mt-4 overflow-x-auto"><h3 class="font-semibold">Device confirmation</h3><table class="w-full text-left text-sm"><thead><tr><th>Serial</th><th>Action</th><th>State</th><th>Recovery</th></tr></thead><tbody>
        @foreach($gatewayCommands as $command)<tr><td class="py-2">{{ $command['serial'] }}</td><td>{{ $command['operation'] }}</td><td>{{ $command['state'] }}</td><td>@if($command['state'] === 'outcome_unknown')<x-filament::button size="sm" wire:click="resolveCommand('{{ $command['id'] }}')" wire:confirm="Confirm you have reviewed the device outcome. This releases the command without sending it again.">Resolve unknown</x-filament::button>@endif</td></tr>@endforeach
        </tbody></table></div>
        @foreach($submissions as $submission)<p class="mt-2">Submission {{ $submission->id }}: {{ $submission->error_code }} @if($submission->expires_at->isFuture())<x-filament::button size="sm" wire:click="retrySubmission('{{ $submission->id }}')" wire:confirm="Retry submission using the same command ID after resolving the failure?">Retry submission</x-filament::button>@endif</p>@endforeach
        <div class="mt-4 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th>Receipt</th><th>Type</th><th>State</th><th>Attempts</th><th>Error</th><th>Recovery</th></tr></thead><tbody>
        @foreach($receipts as $receipt)<tr><td class="py-2">{{ $receipt->id }}</td><td>{{ $receipt->kind }}</td><td>{{ $receipt->state }}</td><td>{{ $receipt->attempts }}</td><td>{{ $receipt->error_code }}</td><td>@if(in_array($receipt->state, ['parked','retrying']))<x-filament::button size="sm" wire:click="replayReceipt('{{ $receipt->id }}')" wire:confirm="Replay this receipt after resolving its failure?">Replay</x-filament::button>@endif</td></tr>@endforeach
        </tbody></table></div>
        <div class="mt-4 space-y-2">
            <h3 class="font-semibold">Parked attendance calculations</h3>
            <label for="inspection-company">Company</label>
            <select id="inspection-company" wire:model="inspectionOrganisation" class="rounded border p-2">
                <option value="">Select company</option>
                @foreach($inspectionCompanies as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
            <x-filament::button size="sm" wire:click="inspectCalculations">Inspect calculations</x-filament::button>
            @foreach($calculationEffects as $effect)<p>Receipt {{ $effect['receipt_id'] }} · {{ $effect['error_code'] }} · attempts {{ $effect['attempts'] }}
                <x-filament::button size="sm" wire:click="replayCalculation('{{ $inspectionOrganisation }}', {{ $effect['id'] }})" wire:confirm="Replay this calculation after resolving its failure?">Replay calculation</x-filament::button>
            </p>@endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
