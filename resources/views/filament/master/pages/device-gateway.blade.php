<x-filament-panels::page>
    <x-filament::section heading="System status" description="Platform health and recovery. Companies manage devices and employees in their own workspace.">
        <div class="flex flex-wrap gap-3">
            <x-filament::button wire:click="refreshStatus">Refresh status</x-filament::button>
        @foreach ($connections as $connection)
                <x-filament::button color="gray" wire:click="chooseConnection({{ $connection->id }})" :disabled="$connectionId === $connection->id">{{ $connection->name }}</x-filament::button>
        @endforeach
        @if ($connectionId)
                <x-filament::button color="gray" wire:click="checkHealth">Check gateway health</x-filament::button>
        @endif
        </div>
        @if (! $connectionId)<p class="mt-3">No enabled gateway connection. Configure it through the deployment command.</p>
        @endif
        @if ($health)<p class="mt-3">Gateway: {{ $health['status'] ?? 'unknown' }}</p>
        @endif
        <p class="mt-3 text-sm text-gray-500">Refreshed {{ \Carbon\Carbon::parse($summary['refreshed_at'])->diffForHumans() }}. Status updates when you refresh.</p>
        <div class="mt-4 grid gap-4 md:grid-cols-3">
            <div><h3 class="font-semibold">Attendance delivery</h3>
        @forelse ($summary['receipts'] ?? [] as $state => $count)<p>{{ str($state)->replace('_', ' ')->title() }}: {{ $count }}</p>
        @empty<p>No receipts</p>
        @endforelse</div>
            <div><h3 class="font-semibold">Command submission</h3>
        @forelse ($summary['commands'] ?? [] as $state => $count)<p>{{ str($state)->replace('_', ' ')->title() }}: {{ $count }}</p>
        @empty<p>No submissions</p>
        @endforelse</div>
            <div><h3 class="font-semibold">Worker queues</h3>
        @forelse ($summary['queues'] ?? [] as $queue)<p>{{ $queue['queue'] }}: {{ $queue['total'] }} · oldest {{ \Carbon\Carbon::createFromTimestamp($queue['oldest'])->diffForHumans() }}</p>
        @empty<p>No queued work</p>
        @endforelse</div>
        </div>
        <div class="mt-4"><h3 class="font-semibold">Worker and scheduler health</h3>
        @forelse ($summary['workers'] ?? [] as $worker)
                <p>{{ $worker['role'] }}: {{ \Carbon\Carbon::parse($worker['heartbeat_at'])->diffForHumans() }}
        @if (\Carbon\Carbon::parse($worker['heartbeat_at'])->lt(now()->subMinutes(3))) · needs attention
        @endif</p>
        @empty<p>No heartbeats reported</p>
        @endforelse
        </div>
    </x-filament::section>
    <x-filament::section heading="Recovery" description="Load a filtered set of receipts when needed. Review the failure before retrying accepted work.">
        <form wire:submit="loadDetails" class="flex flex-wrap items-end gap-3">
            <div><label for="receipt-state">Receipt state</label>
                <select id="receipt-state" wire:model="receiptState" class="rounded border p-2 dark:bg-gray-900">
        @foreach (['parked', 'retrying', 'pending', 'processing', 'applied'] as $state)<option value="{{ $state }}">{{ ucfirst($state) }}</option>
        @endforeach
                </select>
            </div>
            <x-filament::button type="submit" color="gray">Load recovery details</x-filament::button>
        </form>
        @error('receiptState')<p class="text-danger-600">{{ $message }}</p>
        @enderror
        @if ($detailsLoaded)
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm"><thead><tr><th>Receipt</th><th>Type</th><th>State</th><th>Attempts</th><th>Error</th><th>Action</th></tr></thead><tbody>
        @forelse ($receipts as $receipt)
                    <tr><td class="py-2">{{ $receipt->id }}</td><td>{{ $receipt->kind }}</td><td>{{ $receipt->state }}</td><td>{{ $receipt->attempts }}</td><td>{{ $receipt->error_code }}</td><td>
        @if (in_array($receipt->state, ['parked', 'retrying']))<x-filament::button size="sm" wire:click="replayReceipt('{{ $receipt->id }}')" wire:confirm="Retry this receipt after resolving its failure?">Retry</x-filament::button>
        @endif</td></tr>
        @empty<tr><td colspan="6" class="py-3">No matching receipts</td></tr>
        @endforelse
                </tbody></table>
                <div class="mt-3 flex gap-3">
        @if ($receipts->currentPage() > 1)<x-filament::button size="sm" color="gray" wire:click="changeReceiptPage({{ $receipts->currentPage() - 1 }})">Previous</x-filament::button>
        @endif
                    <span>Page {{ $receipts->currentPage() }}</span>
        @if ($receipts->hasMorePages())<x-filament::button size="sm" color="gray" wire:click="changeReceiptPage({{ $receipts->currentPage() + 1 }})">Next</x-filament::button>
        @endif
                </div>
            </div>
        @foreach ($gatewayEvents as $event)<p class="mt-3">{{ $event['serial'] }} · {{ $event['id'] }} · {{ $event['state'] }} · {{ $event['error_code'] }}
        @if (in_array($event['state'], ['parked', 'quarantined']))<x-filament::button size="sm" wire:click="replayGatewayEvent('{{ $event['id'] }}')" wire:confirm="Replay this event after fixing its cause?">Replay event</x-filament::button>
        @endif</p>
        @endforeach
        @foreach ($gatewayCommands as $command)
        @if ($command['state'] === 'outcome_unknown')<p class="mt-3">{{ $command['serial'] }} · {{ $command['operation'] }} · needs review <x-filament::button size="sm" wire:click="resolveCommand('{{ $command['id'] }}')" wire:confirm="Have you reviewed the device outcome? This releases the queue without resending the command.">Resolve unknown</x-filament::button></p>
        @endif
        @endforeach
        @foreach ($submissions as $submission)<p class="mt-3">Submission {{ $submission->id }}: {{ $submission->error_code }}
        @if ($submission->expires_at->isFuture())<x-filament::button size="sm" wire:click="retrySubmission('{{ $submission->id }}')" wire:confirm="Retry submission with the same command ID after resolving its failure?">Retry submission</x-filament::button>
        @endif</p>
        @endforeach
            <div class="mt-4 space-y-2">
                <h3 class="font-semibold">Failed attendance calculations</h3>
                <label for="inspection-company">Company</label>
                <select id="inspection-company" wire:model="inspectionOrganisation" class="rounded border p-2 dark:bg-gray-900">
                    <option value="">Select company</option>
        @foreach ($inspectionCompanies as $id => $name)<option value="{{ $id }}">{{ $name }}</option>
        @endforeach
                </select>
                <x-filament::button size="sm" wire:click="inspectCalculations">Inspect calculations</x-filament::button>
        @foreach ($calculationEffects as $effect)<p>Receipt {{ $effect['receipt_id'] }} · {{ $effect['error_code'] }} · attempts {{ $effect['attempts'] }} <x-filament::button size="sm" wire:click="replayCalculation('{{ $inspectionOrganisation }}', {{ $effect['id'] }})" wire:confirm="Retry this calculation after resolving its failure?">Retry calculation</x-filament::button></p>
        @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
