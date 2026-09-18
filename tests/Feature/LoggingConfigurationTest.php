<?php

namespace Tests\Feature;

use Tests\TestCase;

class LoggingConfigurationTest extends TestCase
{
    public function test_default_log_stack_uses_daily_rotation(): void
    {
        $this->assertSame('stack', config('logging.default'));
        $this->assertSame(['daily'], config('logging.channels.stack.channels'));
        $this->assertEquals(14, config('logging.channels.daily.max_files'));
    }
}
