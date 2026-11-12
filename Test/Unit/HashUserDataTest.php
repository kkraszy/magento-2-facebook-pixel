<?php

declare(strict_types=1);

namespace Kkkonrad\FacebookPixel\Test\Unit;

use Kkkonrad\FacebookPixel\Model\Tracker;
use PHPUnit\Framework\TestCase;

/**
 * Meta silently fails to match a badly normalised hash, so pin the rules.
 */
class HashUserDataTest extends TestCase
{
    public function testNormalisesBeforeHashing(): void
    {
        $h = Tracker::hashUserData(['em' => ' Test@Example.com ', 'ph' => '+48 600-100-200', 'zp' => '00 950', 'fn' => '']);

        $this->assertSame(hash('sha256', 'test@example.com'), $h['em']);
        $this->assertSame(hash('sha256', '48600100200'), $h['ph']);
        $this->assertSame(hash('sha256', '00950'), $h['zp']);
        $this->assertArrayNotHasKey('fn', $h);
    }
}
