<?php

namespace App\Services\Devices;

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class EmployeeBiometrics
{
    public function __construct(private DeviceCommandService $commands) {}

    public function formats(Device $device): array
    {
        $options = [];
        foreach ($device->verificationFormats() as $format) {
            $operation = match ($format) {
                'rfid' => 'upsert_user', 'ai_face' => 'update_biophoto', default => 'update_biodata',
            };
            if ($this->commands->available($device, $operation) && DeviceProfilePolicy::format($device, $format)) {
                $options[$format] = $format === 'rfid' ? 'Card' : Device::VERIFICATION_FORMAT_OPTIONS[$format];
            }
        }

        if ($device->supportsVerificationFormat('ai_face') && DeviceProfilePolicy::format($device, 'ai_face_template') && $this->commands->available($device, 'update_biodata')) { $options['ai_face_template'] = 'AI Face template'; }
        return $options;
    }

    public function push(User $employee, Device $device, array $formats): int
    {
        abort_unless($this->commands->available($device, 'upsert_user'), 403);
        $formats = array_values(array_unique($formats));
        if (array_diff($formats, array_keys($this->formats($device)))) {
            $this->invalid('formats', 'The selected device does not support one of these formats.');
        }
        // Validate all credentials before queuing any command.
        $transfers = [];
        foreach ($formats as $format) {
            if ($format === 'rfid') {
                continue;
            }
            if ($format === 'ai_face') {
                $photo = $this->photo((string) $employee->ai_face_photo_path);
                $transfers[] = ['update_biophoto', ['pin' => (string) $employee->pin, 'size' => (string) strlen($photo), 'content' => base64_encode($photo)]];
                continue;
            }
            $templates = $format === 'finger' ? $employee->fingerprints : $employee->face_templates;
            $templates = is_array($templates) ? $templates : [];
            if ($format !== 'finger') {
                $templates = array_filter($templates, fn ($t) => is_array($t) && (($t['bio_type'] ?? null) === '9') === ($format === 'ai_face_template'));
            }
            $templates = BiometricCredentials::latest($templates, $format === 'finger' ? '1' : '2');
            if ($templates === []) {
                $this->invalid('formats', 'No stored '.($format === 'finger' ? 'fingerprint' : 'face').' template. Register biometric data first.');
            }
            foreach ($templates as $template) {
                $slot = $template['slot'];
                if ((string) ($template['valid'] ?? '1') !== '1' || (string) ($template['duress'] ?? '0') !== '0') {
                    $this->invalid('formats', 'Invalid or duress credentials require a separate authorized transfer.');
                }
                if (($template['bio_type'] ?? null) === '9' && $format === 'face') {
                    $this->invalid('formats', 'An AI Face template cannot be sent as a conventional face template.');
                }
                if ($format === 'ai_face_template') {
                    $destination = $device->options['observed_metadata'] ?? [];
                    foreach (['major_ver', 'minor_ver', 'format'] as $key) {
                        if (! isset($template[$key], $destination[$key]) || (string) $template[$key] !== (string) $destination[$key]) {
                            $this->invalid('formats', 'The destination face algorithm is unknown or incompatible. Read its enrollment data before transferring this template.');
                        }
                    }
                }
                if ($format === 'finger' || $format === 'face') {
                    $key = $format === 'finger' ? 'fp_algorithm_version' : 'face_algorithm_version';
                    $version = $device->options['observed_metadata'][$key] ?? null;
                    if ($format === 'finger' && ! filled($version)) {
                        $version = '10';
                    }
                    $majorVer = $template['major_ver'] ?? ($format === 'finger' ? BiometricCredentials::inferMajorVersion('finger', (string) $template['tmp']) : null);
                    $sameSource = ($template['source_serial'] ?? null) === $device->serial_number;
                    if (! $sameSource && (! filled($version) || ! filled($majorVer) || (string) $version !== (string) $majorVer)) {
                        $this->invalid('formats', 'The credential algorithm is unknown or incompatible with this destination. Read its device details and source credentials first.');
                    }
                }
                $content = trim((string) $template['tmp']);
                if (strlen($content) > 8192 || preg_match('/[\r\n\t]/', $content) || ! preg_match($format === 'finger' ? '/^[0-9]$/' : '/^[0-9]{1,4}$/', (string) $slot)) {
                    $this->invalid('formats', 'A stored template cannot be sent in the supported device format.');
                }
                $parameters = ['pin' => (string) $employee->pin, 'no' => (string) $slot, 'type' => $template['bio_type'] ?? ($format === 'finger' ? '1' : '2'), 'tmp' => $content];
                foreach (['major_ver', 'minor_ver', 'index', 'format', 'valid', 'duress', 'representation', 'source_serial'] as $key) {
                    if (isset($template[$key])) {
                        $parameters[$key] = (string) $template[$key];
                    }
                }
                if ($format === 'finger' && ! isset($parameters['major_ver'])) {
                    $parameters['major_ver'] = $majorVer ?? '10';
                }
                $transfers[] = ['update_biodata', $parameters];
            }
        }
        $parameters = ['pin' => (string) $employee->pin, 'name' => (string) ($employee->name ?: $employee->pin), 'privilege' => (int) $employee->device_privilege === 14 ? '14' : '0'];
        if (in_array('rfid', $formats, true)) {
            $parameters['card'] = (string) ($employee->card_number ?? '');
        }
        $parent = $this->commands->request($device, 'upsert_user', $parameters);
        foreach ($transfers as [$operation, $parameters]) {
            $this->commands->request($device, $operation, $parameters, $parent->gateway_command_id);
        }

        return 1 + count($transfers);
    }

    public function registerFingerprint(User $employee, string $slot, string $template, ?string $algorithm = null): void
    {
        $template = trim($template);
        if (! preg_match('/^[0-9]$/', $slot) || $template === '' || strlen($template) > 8192 || preg_match('/[\r\n\t]/', $template)) {
            $this->invalid('template_data', 'Enter a single raw template of up to 8192 characters and choose a valid finger slot.');
        }
        $templates = $employee->fingerprints ?? [];
        if ($algorithm !== null && ! in_array($algorithm, ['9', '10'], true)) {
            $this->invalid('template_algorithm', 'Choose the fingerprint algorithm reported by the enrollment reader.');
        }
        $algorithm = $algorithm ?? BiometricCredentials::inferMajorVersion('finger', $template);
        $templates[$slot] = array_filter(['tmp' => $template, 'size' => (string) strlen($template), 'valid' => '1', 'major_ver' => $algorithm, 'representation' => 'legacy_fp', 'bio_type' => '1', 'slot' => $slot, 'index' => '0', 'observed_at' => now()->toIso8601String(), 'sha256' => hash('sha256', $template), 'byte_length' => strlen($template)], fn ($v) => $v !== null);
        $employee->update(['fingerprints' => $templates]);
    }

    public function registerPhoto(User $employee, string $path): void
    {
        $this->photo($path);
        PrivateBiometricPhoto::protect($path);
        $employee->update(['ai_face_photo_path' => $path]);
    }

    public function registerCameraPhoto(User $employee, string $data): void
    {
        $prefix = 'data:image/jpeg;base64,';
        if (! str_starts_with($data, $prefix) || strlen($data) > 1048600) {
            $this->invalid('camera_photo', 'Take a valid JPEG photo of 768 KB or smaller.');
        }
        $contents = base64_decode(substr($data, strlen($prefix)), true);
        $image = $contents !== false ? @getimagesizefromstring($contents) : false;
        if (! $contents || strlen($contents) > 786432 || ! is_array($image) || ($image['mime'] ?? null) !== 'image/jpeg') {
            $this->invalid('camera_photo', 'Take a valid JPEG photo of 768 KB or smaller.');
        }
        $path = 'biometric-photos/'.\Illuminate\Support\Str::uuid().'.jpg';
        PrivateBiometricPhoto::put($path, $contents);
        try {
            $employee->update(['ai_face_photo_path' => $path]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    public function refreshEbioPhoto(User $employee): bool
    {
        abort_unless(tenant() && DeviceIntegration::usesEbio(), 403);
        abort_unless(User::whereKey($employee->id)->where('pin', $employee->pin)->exists(), 403);
        $photo = app(\App\Services\EbioSoapService::class)->employeePhoto(tenant(), (string) $employee->pin);
        if ($photo === null) {
            return false;
        }
        $this->registerCameraPhoto($employee, 'data:image/jpeg;base64,'.base64_encode($photo));
        return true;
    }

    public function fetch(User $employee, Device $device): void
    {
        abort_unless($this->commands->available($device, 'query_biodata'), 403);
        $types = array_filter(array_intersect(['finger', 'face', 'ai_face'], $device->verificationFormats()), fn ($type) => DeviceProfilePolicy::format($device, $type));
        if ($types === []) {
            $this->invalid('device_id', 'Enable Fingerprint, Face template or AI Face in the source device settings first.');
        }
        foreach ($types as $type) {
            $this->commands->request($device, 'query_biodata', ['pin' => (string) $employee->pin, 'type' => $type]);
        }
    }

    public function encodedPhoto(User $employee): string
    {
        return base64_encode($this->photo((string) $employee->ai_face_photo_path));
    }

    private function photo(string $path): string
    {
        try { return PrivateBiometricPhoto::read($path); }
        catch (\RuntimeException) { $this->invalid('ai_face_photo_path', 'Register a valid AI Face JPEG first.'); }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages(['mountedActions.0.data.'.$field => $message]);
    }
}
