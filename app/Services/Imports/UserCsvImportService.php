<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Models\AttendanceDay;
use App\Models\Branch;
use App\Models\Department;
use App\Models\SalarySlip;
use App\Models\TaskGroup;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class UserCsvImportService
{
    public const MAX_USERS = 1000;

    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MONEY = ['basic_salary', 'housing_allowance', 'transport_allowance', 'other_allowance', 'fixed_deduction', 'overtime_hourly_rate'];

    public const USER_COLUMNS = ['pin', 'name', 'email', 'whatsapp_number', 'card_number', 'device_privilege', 'device_password', 'is_enabled', 'branch_id', 'department_id', 'group', 'task_group_ids'];

    public const PAYROLL_COLUMNS = ['payroll_enabled', 'pay_basis', 'currency', 'effective_from', ...self::MONEY];

    public function directory(): string
    {
        $this->authorize();

        return 'imports/users/'.hash('sha256', (string) tenant('id'));
    }

    private function authorize(): void
    {
        abort_unless(tenancy()->initialized && Auth::guard('web')->user() instanceof User, 403);
        // Reload from the active tenant, not an actor supplied by the browser.
        abort_unless((int) User::find(Auth::guard('web')->id())?->privilege === 14, 403);
    }

    private function content(string $upload): string
    {
        $directory = $this->directory();
        if (! str_starts_with($upload, $directory.'/') || basename($upload) !== substr($upload, strlen($directory) + 1)) {
            $this->fail('Invalid upload location.');
        }
        $disk = Storage::disk('local');
        $root = realpath($disk->path($directory));
        $path = realpath($disk->path($upload));
        if (! $root || ! $path || dirname($path) !== $root || ! is_file($path) || ! is_readable($path) || filesize($path) > self::MAX_BYTES) {
            $this->fail('Upload must be a readable CSV no larger than 5 MB.');
        }

        return file_get_contents($path);
    }

    /** @return array{created:int, updated:int, payroll:int, delimiter:string, fingerprint:string} */
    public function preview(string $upload, string $mode): array
    {
        $content = $this->content($upload);
        [$rows, $delimiter] = $this->parse($content);
        $users = $this->validateRows($rows, $mode);

        return [
            'created' => count(array_filter($rows, fn (UserImportRow $row) => ! $users->has($row->pin))),
            'updated' => count(array_filter($rows, fn (UserImportRow $row) => $users->has($row->pin))),
            'payroll' => count(array_filter($rows, fn (UserImportRow $row) => $row->payroll !== [])),
            'fingerprint' => hash('sha256', $mode."\0".$content),
            'delimiter' => match ($delimiter) {
                ';' => 'semicolon', "\t" => 'tab', default => 'comma'
            },
        ];
    }

    /** Re-read and validate on submit; the browser preview is never trusted.
     * @return array{created:int, updated:int}
     */
    public function import(string $upload, string $mode, ?string $expectedFingerprint = null): array
    {
        $content = $this->content($upload);
        if ($expectedFingerprint !== null && ! hash_equals($expectedFingerprint, hash('sha256', $mode."\0".$content))) {
            $this->fail('The file or import mode changed. Return to the upload step and validate again before confirming.');
        }
        [$rows] = $this->parse($content);
        usort($rows, fn (UserImportRow $a, UserImportRow $b) => strcmp($a->pin, $b->pin));
        try {
            return DB::transaction(function () use ($rows, $mode): array {
                $users = $this->validateRows($rows, $mode, true);
                $result = ['created' => 0, 'updated' => 0];
                foreach ($rows as $row) {
                    $user = $users->get($row->pin);
                    $attendanceChanged = false;
                    $payrollChanged = false;
                    if ($user) {
                        $user->update($row->attributes);
                        $attendanceChanged = $user->wasChanged(['branch_id', 'department_id', 'group']);
                        $payrollChanged = $user->wasChanged('is_enabled');
                        $result['updated']++;
                    } else {
                        $user = User::create(['pin' => $row->pin, 'privilege' => 0, 'is_enabled' => true, ...$row->attributes]);
                        $result['created']++;
                    }
                    if ($row->taskGroupIds !== null) {
                        $changes = $user->taskGroups()->sync($row->taskGroupIds);
                        $attendanceChanged = $attendanceChanged || $changes['attached'] !== [] || $changes['detached'] !== [];
                    }
                    if ($row->payroll !== []) {
                        $profile = $user->payrollProfile ?? $user->payrollProfile()->make();
                        $profile->fill($row->payroll);
                        if (! $profile->exists || $profile->isDirty()) {
                            $profile->save();
                            $payrollChanged = true;
                        }
                    }
                    if ($attendanceChanged) {
                        AttendanceDay::where('user_id', $user->id)->update(['stale_at' => now()]);
                    }
                    if ($attendanceChanged || $payrollChanged) {
                        foreach (SalarySlip::where('user_id', $user->id)->orderBy('id')->lockForUpdate()->get() as $slip) {
                            $slip->update(['calculation_snapshot' => array_merge($slip->calculation_snapshot ?? [], ['needs_recalculation' => true, 'recalculation_reason' => 'Employee assignments or payroll profile changed through employee import.'])]);
                        }
                    }
                }

                return $result;
            }, 3);
        } catch (QueryException $e) {
            $this->fail('Import could not be saved. No rows were imported. Check for conflicting records and retry.');
        }
    }

    /** @return array{0:list<UserImportRow>, 1:string} */
    private function parse(string $content): array
    {
        if (strlen($content) > self::MAX_BYTES || ! mb_check_encoding($content, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content)) {
            $this->fail('Use a UTF-8 CSV under 5 MB without binary/control characters.');
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $first = strtok($content, "\r\n") ?: '';
        $delimiter = ',';
        foreach ([';', "\t"] as $candidate) {
            if (count(str_getcsv($first, $candidate, '"', '')) > count(str_getcsv($first, $delimiter, '"', ''))) {
                $delimiter = $candidate;
            }
        }
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $content);
        rewind($stream);
        try {
            $header = fgetcsv($stream, 0, $delimiter, '"', '');
            if (! $header) {
                $this->fail('CSV is empty.');
            }
            $header = array_map(fn ($value) => strtolower(trim((string) $value)), $header);
            if (count($header) !== count(array_unique($header)) || array_diff($header, [...self::USER_COLUMNS, ...self::PAYROLL_COLUMNS]) || array_diff(['pin', 'name'], $header)) {
                $this->fail('Headers must be unique and supported, including pin and name. Download the current template. Login privilege/password and tenant fields cannot be imported.');
            }
            $rows = [];
            $line = 1;
            while (($values = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
                $line++;
                if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) {
                    continue;
                }
                if (count($rows) >= self::MAX_USERS) {
                    $this->fail('Upload at most 1,000 employees at once. No rows were imported.');
                }
                if (count($values) !== count($header)) {
                    $this->fail("Record {$line}: column count does not match the header.");
                }
                $data = array_filter(array_combine($header, array_map(fn ($value) => trim((string) $value), $values)), fn ($value) => $value !== '');
                foreach ($data as $field => $value) {
                    if (strlen($value) > 255 || preg_match('/^[=+@-]/', $value)) {
                        $this->fail("Record {$line}: {$field} is too long or starts with a spreadsheet formula marker. Use digits only for phone numbers.");
                    }
                }
                $validator = Validator::make($data, [
                    'pin' => ['required', 'string', 'regex:/^[a-zA-Z0-9_][a-zA-Z0-9_-]{0,31}$/'],
                    'name' => ['required', 'string', 'max:150'],
                    'email' => ['nullable', 'email:rfc', 'max:254'],
                    'whatsapp_number' => ['nullable', 'regex:/^[0-9]{7,15}$/'],
                    'device_password' => ['nullable', 'regex:/^[0-9]{1,8}$/'],
                    'device_privilege' => ['nullable', 'in:0,14'],
                    'is_enabled' => ['nullable', 'in:0,1,true,false,yes,no'],
                    'payroll_enabled' => ['nullable', 'in:0,1,true,false,yes,no'],
                    'pay_basis' => ['nullable', 'in:monthly,daily,hourly'],
                    'currency' => ['nullable', 'in:INR'],
                    'effective_from' => ['nullable', 'date_format:Y-m-d'],
                    'branch_id' => ['nullable', 'regex:/^[1-9][0-9]{0,9}$/'],
                    'department_id' => ['nullable', 'regex:/^[1-9][0-9]{0,9}$/'],
                    'task_group_ids' => ['nullable', 'regex:/^[1-9][0-9]{0,9}(\|[1-9][0-9]{0,9})*$/'],
                    ...array_fill_keys(self::MONEY, ['nullable', 'regex:/^(0|[1-9][0-9]{0,7})(\.[0-9]{1,2})?$/']),
                ]);
                if ($validator->fails()) {
                    $this->fail("Record {$line}: ".implode(' ', $validator->errors()->all()));
                }
                $attributes = array_intersect_key($data, array_flip(self::USER_COLUMNS));
                unset($attributes['pin'], $attributes['task_group_ids']);
                $payroll = array_intersect_key($data, array_flip(self::PAYROLL_COLUMNS));
                foreach (self::MONEY as $field) {
                    if (isset($payroll[$field])) {
                        [$whole, $fraction] = array_pad(explode('.', $payroll[$field], 2), 2, '');
                        $payroll[$field.'_minor'] = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
                        unset($payroll[$field]);
                    }
                }
                foreach (['branch_id', 'department_id', 'device_privilege'] as $field) {
                    if (isset($attributes[$field])) {
                        $attributes[$field] = (int) $attributes[$field];
                    }
                }
                if (isset($attributes['is_enabled'])) {
                    $attributes['is_enabled'] = in_array($attributes['is_enabled'], ['1', 'true', 'yes'], true);
                }
                if (isset($payroll['payroll_enabled'])) {
                    $payroll['payroll_enabled'] = in_array($payroll['payroll_enabled'], ['1', 'true', 'yes'], true);
                }
                if (isset($attributes['email'])) {
                    $attributes['email'] = strtolower($attributes['email']);
                }
                $rows[] = new UserImportRow($line, $data['pin'], $attributes, $payroll, isset($data['task_group_ids']) ? array_map('intval', explode('|', $data['task_group_ids'])) : null);
            }
            if ($rows === []) {
                $this->fail('CSV contains no employee records.');
            }

            return [$rows, $delimiter];
        } finally {
            fclose($stream);
        }
    }

    /** @param list<UserImportRow> $rows */
    private function validateRows(array $rows, string $mode, bool $lock = false): Collection
    {
        if (! in_array($mode, ['create', 'upsert'], true)) {
            $this->fail('Select a valid import mode.');
        }
        $users = User::with('payrollProfile')->whereIn('pin', array_map(fn ($row) => $row->pin, $rows))->orderBy('id');
        if ($lock) {
            $users->lockForUpdate();
        }
        $users = $users->get()->keyBy('pin');
        $requestedEmails = array_values(array_unique(array_filter(array_merge(
            array_map(fn (UserImportRow $row) => isset($row->attributes['email']) ? strtolower($row->attributes['email']) : null, $rows),
            $users->pluck('email')->filter()->map(fn (string $email) => strtolower($email))->all(),
        ))));
        $emails = User::whereIn(DB::raw('LOWER(email)'), $requestedEmails)->get(['id', 'email'])->keyBy(fn ($user) => strtolower($user->email));
        $branches = Branch::pluck('id')->all();
        $departments = Department::with('branches:id')->get()->keyBy('id');
        $groups = TaskGroup::pluck('id')->all();
        $seenPins = $seenEmails = [];
        foreach ($rows as $row) {
            $user = $users->get($row->pin);
            if (isset($seenPins[$row->pin]) || ($user && $mode === 'create')) {
                $this->fail("Record {$row->line}: duplicate/existing PIN. Choose update by PIN only when intended.");
            }
            $seenPins[$row->pin] = true;
            if ($user?->is_system_account || $user?->email === User::DEFAULT_LOGIN_EMAIL || ($row->attributes['email'] ?? null) === User::DEFAULT_LOGIN_EMAIL) {
                $this->fail("Record {$row->line}: the default login account cannot be imported or changed.");
            }
            if ((int) $user?->privilege === 14 && isset($row->attributes['email']) && $row->attributes['email'] !== strtolower((string) $user->email)) {
                $this->fail("Record {$row->line}: change an application admin's login email through the user editor, not CSV.");
            }
            $email = $row->attributes['email'] ?? $user?->email;
            if ($email && (isset($seenEmails[strtolower($email)]) || ($emails->has(strtolower($email)) && $emails->get(strtolower($email))->id !== $user?->id))) {
                $this->fail("Record {$row->line}: email belongs to another record.");
            }
            if ($email) {
                $seenEmails[strtolower($email)] = true;
            }
            $branch = $row->attributes['branch_id'] ?? $user?->branch_id;
            $department = $row->attributes['department_id'] ?? $user?->department_id;
            if (($branch && ! in_array($branch, $branches, true)) || ($department && ! $departments->has($department)) || ($row->taskGroupIds && array_diff($row->taskGroupIds, $groups))) {
                $this->fail("Record {$row->line}: branch, department or task group does not exist in this organisation.");
            }
            if ($branch && $department && ! $departments->get($department)->branches->contains('id', $branch)) {
                $this->fail("Record {$row->line}: department is not assigned to the selected branch.");
            }
            if ($row->payroll !== []) {
                $profile = array_merge($user?->payrollProfile?->getAttributes() ?? [], $row->payroll);
                foreach (['pay_basis', 'basic_salary_minor', 'effective_from'] as $required) {
                    if (! isset($profile[$required])) {
                        $this->fail("Record {$row->line}: a new payroll profile requires pay_basis, basic_salary and effective_from.");
                    }
                }
                if ($profile['pay_basis'] !== 'monthly' && array_sum(array_intersect_key($profile, array_flip(['housing_allowance_minor', 'transport_allowance_minor', 'other_allowance_minor', 'fixed_deduction_minor']))) > 0) {
                    $this->fail("Record {$row->line}: daily/hourly payroll cannot include monthly allowances or fixed monthly deductions. Set those fields to 0.");
                }
            }
        }

        return $users;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }

    public function template(bool $payroll): string
    {
        $header = $payroll ? [...self::USER_COLUMNS, ...self::PAYROLL_COLUMNS] : self::USER_COLUMNS;
        $sample = ['pin' => '1001', 'name' => 'Example Employee', 'is_enabled' => '1', 'payroll_enabled' => '0', 'pay_basis' => 'monthly', 'currency' => 'INR', 'effective_from' => today()->format('Y-m-d'), 'basic_salary' => '8000.00'];
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, $header, ',', '"', '');
        fputcsv($stream, array_map(fn ($field) => $sample[$field] ?? '', $header), ',', '"', '');
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $content;
    }
}
