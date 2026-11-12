<?php

declare(strict_types=1);

namespace Kkkonrad\FacebookPixel\CustomerData;

use Kkkonrad\FacebookPixel\Model\Tracker;
use Magento\Customer\CustomerData\SectionSourceInterface;

/**
 * Hands the queued server-side events to the browser once, then forgets them.
 */
class Pixel implements SectionSourceInterface
{
    public function __construct(private readonly Tracker $tracker)
    {
    }

    public function getSectionData(): array
    {
        return ['events' => $this->tracker->popQueue()];
    }
}
