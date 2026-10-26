<?php

namespace Tests\Feature;

use App\Casts\ProtectedArray;
use Tests\TestCase;

class ProtectedArrayCompatibilityTest extends TestCase
{
    public function test_legacy_double_encoded_arrays_remain_readable_and_can_be_protected(): void
    {
        foreach ([[], ['pin' => 'test-only', 'enabled' => true]] as $parameters) {
            $legacy = json_encode(json_encode($parameters));
            $decoded = ProtectedArray::decode($legacy);
            $this->assertSame($parameters, $decoded);
            $protected = ProtectedArray::encode($decoded);
            $this->assertStringContainsString('_encrypted_v1', $protected);
            $this->assertSame($parameters, ProtectedArray::decode($protected));
        }
    }

    public function test_scalar_parameters_are_still_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        ProtectedArray::decode(json_encode(json_encode(42)));
    }
}
