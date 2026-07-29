<div class="w-full overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <table class="w-full text-left text-sm divide-y divide-gray-200 dark:divide-white/5">
        <tbody class="divide-y divide-gray-200 dark:divide-white/5">
            @php
                $record = $getRecord();
            @endphp
            <tr>
                <th class="px-4 py-3 font-medium text-gray-500 dark:text-gray-400 w-1/3 bg-gray-50 dark:bg-white/5">eBioServer URL</th>
                <td class="px-4 py-3 text-gray-950 dark:text-white">{{ $record->ebio_url ?: '-' }}</td>
            </tr>
            <tr>
                <th class="px-4 py-3 font-medium text-gray-500 dark:text-gray-400 w-1/3 bg-gray-50 dark:bg-white/5">Webhook Post URL</th>
                <td class="px-4 py-3 text-gray-950 dark:text-white font-mono">
                    <div class="flex items-center gap-2">
                        <span>{{ url('/api/ebio/webhook/' . $record->id) }}</span>
                        <button type="button" onclick="navigator.clipboard.writeText('{{ url('/api/ebio/webhook/' . $record->id) }}')" class="text-gray-500 hover:text-gray-700" title="Copy URL">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                        </button>
                    </div>
                </td>
            </tr>
            <tr>
                <th class="px-4 py-3 font-medium text-gray-500 dark:text-gray-400 w-1/3 bg-gray-50 dark:bg-white/5" title="Matches the password in eBioServer Webhook settings">Webhook Encryption Password</th>
                <td class="px-4 py-3">
                    @if($record->ebio_aes_password)
                    <div x-data="{ show: false }" class="flex items-center gap-2">
                        <span x-text="show ? '{{ addslashes($record->ebio_aes_password) }}' : '********'" class="font-mono text-gray-950 dark:text-white"></span>
                        <button @click="show = !show" type="button" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                            <svg x-show="!show" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                            <svg x-show="show" x-cloak class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"></path></svg>
                        </button>
                    </div>
                    @else
                        -
                    @endif
                </td>
            </tr>
            <tr>
                <th class="px-4 py-3 font-medium text-gray-500 dark:text-gray-400 w-1/3 bg-gray-50 dark:bg-white/5">eBioServer Admin Username</th>
                <td class="px-4 py-3">
                    @if($record->ebio_soap_username)
                    <div x-data="{ show: false }" class="flex items-center gap-2">
                        <span x-text="show ? '{{ addslashes($record->ebio_soap_username) }}' : '********'" class="font-mono text-gray-950 dark:text-white"></span>
                        <button @click="show = !show" type="button" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                            <svg x-show="!show" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                            <svg x-show="show" x-cloak class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"></path></svg>
                        </button>
                    </div>
                    @else
                        -
                    @endif
                </td>
            </tr>
            <tr>
                <th class="px-4 py-3 font-medium text-gray-500 dark:text-gray-400 w-1/3 bg-gray-50 dark:bg-white/5">eBioServer Admin Password</th>
                <td class="px-4 py-3">
                    @if($record->ebio_soap_password)
                    <div x-data="{ show: false }" class="flex items-center gap-2">
                        <span x-text="show ? '{{ addslashes($record->ebio_soap_password) }}' : '********'" class="font-mono text-gray-950 dark:text-white"></span>
                        <button @click="show = !show" type="button" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                            <svg x-show="!show" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                            <svg x-show="show" x-cloak class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"></path></svg>
                        </button>
                    </div>
                    @else
                        -
                    @endif
                </td>
            </tr>
        </tbody>
    </table>
</div>
