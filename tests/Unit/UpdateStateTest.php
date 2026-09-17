<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Updates\UpdateState;
use Tests\TestCase;

class UpdateStateTest extends TestCase
{
    public function test_transitions(): void
    {
        $this->assertTrue(UpdateState::allowed(UpdateState::CHECKING, UpdateState::BACKING_UP));
        $this->assertTrue(UpdateState::allowed(UpdateState::MIGRATING, UpdateState::ROLLING_BACK));
        $this->assertFalse(UpdateState::allowed(UpdateState::CHECKING, UpdateState::COMPLETED));
        $this->assertFalse(UpdateState::allowed(UpdateState::COMPLETED, UpdateState::CHECKING));
        $this->assertSame('Testing', UpdateState::label(UpdateState::HEALTH_CHECK));
        $this->assertSame('Rolled Back', UpdateState::label(UpdateState::ROLLED_BACK));
    }
}
