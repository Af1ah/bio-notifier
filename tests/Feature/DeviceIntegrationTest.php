<?php

namespace Tests\Feature;

use App\Filament\Tenant\Resources\DeviceCommandResource;
use App\Filament\Tenant\Resources\DeviceCommandResource\Pages\ListDeviceCommands;
use App\Filament\Tenant\Resources\DeviceResource\Pages\ListDevices;
use App\Filament\Tenant\Resources\UserResource\Pages\ListUsers;
use App\Models\Organisation;
use App\Services\Devices\DeviceIntegration;
use App\Services\EbioSoapService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\TestCase;

class DeviceIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
        Event::fake([TenantCreated::class]);
    }

    protected function tearDown(): void
    {
        tenancy()->tenant = null;
        parent::tearDown();
    }

    public function test_master_toggle_defaults_to_gateway_and_hides_credentials_without_clearing_them(): void
    {
        $component = \Livewire\Livewire::test(DeviceIntegrationFormFixture::class)
            ->assertSet('data.device_integration', DeviceIntegration::GATEWAY)
            ->assertSee('Shared gateway')
            ->assertDontSee('eBioServer Credentials')
            ->set('data.device_integration', DeviceIntegration::EBIO)
            ->assertSee('eBioServer Credentials')
            ->set('data.name', 'Company')
            ->set('data.shortname', 'company-form')
            ->set('data.ebio_url', 'https://device.example.test')
            ->set('data.ebio_soap_password', 'test-password')
            ->set('data.device_integration', DeviceIntegration::GATEWAY)
            ->call('capture');

        $this->assertArrayNotHasKey('ebio_url', $component->get('submitted'));
        $this->assertArrayNotHasKey('ebio_soap_password', $component->get('submitted'));
        $this->assertSame('test-password', $component->get('data.ebio_soap_password'));
    }

    public function test_migration_preserves_existing_companies_and_defaults_new_rows_to_gateway(): void
    {
        $migration = require database_path('migrations/2026_10_03_140000_add_device_integration_to_organisations.php');
        $migration->down();
        DB::table('organisations')->insert(['id' => 'legacy', 'name' => 'Existing company']);
        $migration->up();
        $this->assertSame(DeviceIntegration::EBIO, Organisation::findOrFail('legacy')->device_integration);
        DB::table('organisations')->insert(['id' => 'new', 'name' => 'New company']);
        $this->assertSame(DeviceIntegration::GATEWAY, Organisation::findOrFail('new')->device_integration);
    }

    public function test_new_model_defaults_to_gateway_and_switching_preserves_credentials(): void
    {
        $organisation = Organisation::create(['name' => 'Payroll company', 'shortname' => 'payroll']);
        $this->assertSame(DeviceIntegration::GATEWAY, $organisation->fresh()->device_integration);
        $organisation->update([
            'device_integration' => DeviceIntegration::EBIO,
            'ebio_url' => 'https://device.example.test',
            'ebio_soap_username' => 'test-admin',
            'ebio_soap_password' => 'test-password',
        ]);
        $organisation->update(['device_integration' => DeviceIntegration::GATEWAY]);
        $organisation = $organisation->fresh();
        $this->assertSame('https://device.example.test', $organisation->ebio_url);
        $this->assertSame('test-password', $organisation->ebio_soap_password);
        $this->assertNotSame('test-password', DB::table('organisations')->where('id', $organisation->id)->value('ebio_soap_password'));
    }

    public function test_legacy_and_ebio_companies_keep_working_soap_commands(): void
    {
        foreach ([null, DeviceIntegration::EBIO] as $integration) {
            Http::fake(['*' => Http::response('<DeviceCommand_RebootResult>success</DeviceCommand_RebootResult>')]);
            $organisation = new Organisation([
                'device_integration' => $integration,
                'ebio_url' => 'https://device.example.test',
                'ebio_soap_username' => 'test-admin',
                'ebio_soap_password' => 'test-password',
            ]);
            $this->assertTrue((new EbioSoapService)->rebootDevice($organisation, 'DEVICE001'));
            Http::assertSentCount(1);
        }
    }

    public function test_gateway_rejects_soap_before_making_any_request(): void
    {
        Http::fake();
        try {
            (new EbioSoapService)->rebootDevice(new Organisation(['device_integration' => DeviceIntegration::GATEWAY]), 'DEVICE001');
            $this->fail('Gateway company was allowed to use the legacy transport.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('This device action is unavailable for this company.', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_stale_queue_payload_is_rejected_after_company_changes_integration(): void
    {
        Http::fake();
        $organisation = Organisation::create(['name' => 'Switch company', 'shortname' => 'switch', 'device_integration' => DeviceIntegration::EBIO]);
        Organisation::findOrFail($organisation->id)->update(['device_integration' => DeviceIntegration::GATEWAY]);
        try {
            (new EbioSoapService)->rebootDevice($organisation, 'DEVICE001');
            $this->fail('Stale queued command was allowed to use the legacy transport.');
        } catch (AuthorizationException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_company_choice_authorizes_the_matching_tenant_actions(): void
    {
        foreach ([DeviceIntegration::EBIO, DeviceIntegration::GATEWAY] as $integration) {
            $ebioAllowed = $integration === DeviceIntegration::EBIO;
            tenancy()->tenant = new Organisation(['device_integration' => $integration]);
            foreach ([ListDevices::class, ListUsers::class, ListDeviceCommands::class] as $pageClass) {
                $page = new $pageClass;
                $method = new \ReflectionMethod($pageClass, 'getHeaderActions');
                $actions = $method->invoke($page);
                foreach ($actions as $action) {
                    if (in_array($action->getName(), ['syncEbioDevices', 'syncEbioUsers', 'create']) && $pageClass !== ListUsers::class) {
                        $this->assertSame($ebioAllowed, $action->isAuthorized(), $pageClass.' '.$action->getName());
                    } elseif ($action->getName() === 'runCommand') {
                        $this->assertTrue($action->isAuthorized());
                    } elseif ($action->getName() === 'syncEbioUsers') {
                        $this->assertSame($ebioAllowed, $action->isAuthorized());
                    }
                }
            }
        }
        $this->assertFalse(DeviceCommandResource::canCreate());
    }
}

class DeviceIntegrationFormFixture extends \Livewire\Component implements \Filament\Schemas\Contracts\HasSchemas
{
    use \Filament\Schemas\Concerns\InteractsWithSchemas;

    public array $data = [];
    public array $submitted = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return \App\Filament\Master\Resources\Organisations\Schemas\OrganisationForm::configure($schema)->statePath('data');
    }

    public function capture(): void
    {
        $this->submitted = $this->form->getState();
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
