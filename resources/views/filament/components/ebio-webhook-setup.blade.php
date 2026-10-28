@php
    $webhookUrl = filled($organisation->ebio_webhook_token)
        ? rtrim(config('app.url'), '/') . '/api/ebio/webhook/' . $organisation->ebio_webhook_token
        : null;
@endphp

@if ($webhookUrl)
    <div class="space-y-4" x-data="{ copied: false }">
        <div class="space-y-2">
            <label class="text-sm font-medium" for="ebio-webhook-setup-url">Webhook Post URL</label>
            <div class="flex flex-col gap-2 sm:flex-row">
                <div class="min-w-0 flex-1">
                    <x-filament::input.wrapper>
                        <x-filament::input id="ebio-webhook-setup-url" x-ref="webhookUrl" type="text" :value="$webhookUrl" readonly spellcheck="false" />
                    </x-filament::input.wrapper>
                </div>
                <x-filament::button type="button" color="gray" icon="heroicon-o-clipboard-document"
                    x-on:click="if (!navigator.clipboard) { $refs.webhookUrl.focus(); $refs.webhookUrl.select(); return; } navigator.clipboard.writeText($refs.webhookUrl.value).then(() => { copied = true; setTimeout(() => copied = false, 2000); }).catch(() => { $refs.webhookUrl.focus(); $refs.webhookUrl.select(); })">
                    <span aria-live="polite" x-text="copied ? 'Copied' : 'Copy URL'">Copy URL</span>
                </x-filament::button>
            </div>
        </div>
        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
            <div><dt class="text-gray-500 dark:text-gray-400">Webhook Response</dt><dd class="font-medium">Success</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Enable Encryption</dt><dd class="font-medium">{{ filled($organisation->ebio_aes_password) ? 'Yes' : 'No' }}</dd></div>
        </dl>
        <p class="text-sm text-gray-500 dark:text-gray-400">Enable Webhook, paste the URL and save. Keep the existing Last Sync Log Date.</p>
    </div>
@else
    <p class="text-sm text-gray-500 dark:text-gray-400">Webhook setup is not ready. Ask your administrator to generate a webhook credential.</p>
@endif
