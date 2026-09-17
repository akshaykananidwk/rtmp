<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Security\Totp;
use Tests\TestCase;

class TotpTest extends TestCase
{
    public function test_rfc6238_vector(): void
    {
        // Secret "12345678901234567890" base32 = GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ ; T=59 → 287082 (SHA1, 6 digits)
        $totp = new Totp;
        $this->assertSame('287082', $totp->code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59));
        $this->assertSame('081804', $totp->code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 1111111109));
    }

    public function test_verify_accepts_current_and_rejects_garbage(): void
    {
        $totp = new Totp;
        $secret = $totp->generateSecret();
        $this->assertTrue($totp->verify($secret, $totp->code($secret)));
        $this->assertFalse($totp->verify($secret, '000000') && $totp->verify($secret, '111111') && $totp->verify($secret, '222222'));
        $this->assertFalse($totp->verify($secret, 'abcdef'));
    }
}
