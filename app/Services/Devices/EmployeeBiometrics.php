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
            if ($this->commands->available($device, $operation)) {
                $options[$format] = $format === 'rfid' ? 'Card' : Device::VERIFICATION_FORMAT_OPTIONS[$format];
            }
        }

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
            $templates = array_filter(is_array($templates) ? $templates : [], fn ($t) => is_array($t) && filled($t['tmp'] ?? null));
            if ($templates === []) {
                $this->invalid('formats', 'No stored '.($format === 'finger' ? 'fingerprint' : 'face').' template. Register biometric data first.');
            }
            foreach ($templates as $slot => $template) {
                $content = trim((string) $template['tmp']);
                if (strlen($content) > 8192 || preg_match('/[\r\n\t]/', $content) || ! preg_match($format === 'finger' ? '/^[0-9]$/' : '/^[0-9]{1,4}$/', (string) $slot)) {
                    $this->invalid('formats', 'A stored template cannot be sent in the supported device format.');
                }
                $transfers[] = ['update_biodata', ['pin' => (string) $employee->pin, 'no' => (string) $slot, 'type' => $format === 'finger' ? '1' : '9', 'tmp' => $content]];
            }
        }
        $parameters = ['pin' => (string) $employee->pin, 'name' => (string) ($employee->name ?: $employee->pin), 'privilege' => (int) $employee->device_privilege === 14 ? '14' : '0'];
        if (in_array('rfid', $formats, true)) {
            $parameters['card'] = (string) ($employee->card_number ?? '');
        }
        $this->commands->request($device, 'upsert_user', $parameters);
        foreach ($transfers as [$operation, $parameters]) {
            $this->commands->request($device, $operation, $parameters);
        }

        return 1 + count($transfers);
    }

    public function registerFingerprint(User $employee, string $slot, string $template): void
    {
        $template = trim($template);
        if (! preg_match('/^[0-9]$/', $slot) || $template === '' || strlen($template) > 8192 || preg_match('/[\r\n\t]/', $template)) {
            $this->invalid('template_data', 'Enter a single raw template of up to 8192 characters and choose a valid finger slot.');
        }
        $templates = $employee->fingerprints ?? [];
        $templates[$slot] = ['tmp' => $template, 'size' => (string) strlen($template), 'valid' => '1'];
        $employee->update(['fingerprints' => $templates]);
    }

    public function registerPhoto(User $employee, string $path): void
    {
        $this->photo($path);
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
        if (! Storage::disk('local')->put($path, $contents, ['visibility' => 'private'])) {
            $this->invalid('camera_photo', 'The photo could not be saved. Please try again.');
        }
        try {
            $employee->update(['ai_face_photo_path' => $path]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    public function fetch(User $employee, Device $device): void
    {
        abort_unless($this->commands->available($device, 'query_biodata'), 403);
        $types = array_intersect(['finger', 'face', 'ai_face'], $device->verificationFormats());
        if ($types === []) {
            $this->invalid('device_id', 'Enable Fingerprint, Face template or AI Face in the source device settings first.');
        }
        foreach ($types as $type) {
            $this->commands->request($device, 'query_biodata', ['pin' => (string) $employee->pin, 'type' => $type]);
        }
    }

    private function photo(string $path): string
    {
        $disk = Storage::disk('local');
        if (! str_starts_with($path, 'biometric-photos/') || str_contains($path, '..') || ! $disk->exists($path)) {
            $this->invalid('ai_face_photo_path', 'Register an AI Face JPEG first.');
        }
        $image = @getimagesize($disk->path($path));
        if (! is_array($image) || ($image['mime'] ?? null) !== 'image/jpeg' || $disk->size($path) > 786432) {
            $this->invalid('ai_face_photo_path', 'Use a valid JPEG of 768 KB or smaller.');
        }

        return $disk->get($path);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages(['mountedActions.0.data.'.$field => $message]);
    }
}
