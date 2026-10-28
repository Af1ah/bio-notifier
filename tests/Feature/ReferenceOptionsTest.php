<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Support\ReferenceOptions;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Stancl\Tenancy\Contracts\Tenant;
use Tests\TestCase;

class ReferenceOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('branches', function ($t) { $t->id(); $t->string('name'); });
        config(['cache.reference_enabled'=>true,'cache.stores.reference'=>['driver'=>'array','serialize'=>true]]);
        Cache::purge('reference');
        $this->tenant('A');
    }

    private function tenant(string $id): void
    {
        app()->instance(Tenant::class, (new Organisation)->forceFill(['id'=>$id]));
    }

    public function test_cached_reference_rows_are_scoped_and_expire(): void
    {
        DB::table('branches')->insert(['id'=>1,'name'=>'Original']);
        $service=app(ReferenceOptions::class);
        $this->assertSame('Original',$service->rows(Branch::class)[0]['name']);
        DB::table('branches')->where('id',1)->update(['name'=>'Updated']);
        $this->assertSame('Original',$service->rows(Branch::class)[0]['name']);
        $this->tenant('B');
        $this->assertSame('Updated',$service->rows(Branch::class)[0]['name']);
        $this->tenant('A');
        $this->travel(31)->seconds();
        $this->assertSame('Updated',$service->rows(Branch::class)[0]['name']);
    }

    public function test_redis_failure_falls_back_to_authoritative_database(): void
    {
        DB::table('branches')->insert(['id'=>1,'name'=>'Database value']);
        Cache::shouldReceive('store')->once()->with('reference')->andThrow(new RuntimeException('Redis unavailable'));
        $this->assertSame('Database value',app(ReferenceOptions::class)->rows(Branch::class)[0]['name']);
    }

    public function test_cache_fallback_does_not_hide_database_failure(): void
    {
        Schema::drop('branches');
        Cache::shouldReceive('store')->once()->with('reference')->andThrow(new RuntimeException('Redis unavailable'));
        $this->expectException(QueryException::class);
        app(ReferenceOptions::class)->rows(Branch::class);
    }

    public function test_sensitive_models_cannot_be_cached_by_reference_service(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(ReferenceOptions::class)->rows(User::class);
    }
}
