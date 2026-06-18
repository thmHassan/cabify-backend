<?php

namespace Tests\Unit;

use App\Support\PlotDispatch;
use PHPUnit\Framework\TestCase;

class PlotDispatchTest extends TestCase
{
    public function test_active_offer_detects_broadcast_message(): void
    {
        $action = PlotDispatch::broadcastAction(3, 12, false, 30);

        $this->assertTrue(PlotDispatch::isActiveOffer($action));
        $this->assertTrue(PlotDispatch::isInProgressAction($action));
        $this->assertFalse(PlotDispatch::isExhaustedAction($action));
    }

    public function test_exhausted_action_is_not_in_progress(): void
    {
        $this->assertTrue(PlotDispatch::isExhaustedAction(PlotDispatch::EXHAUSTED_ACTION));
        $this->assertFalse(PlotDispatch::isInProgressAction(PlotDispatch::EXHAUSTED_ACTION));
    }

    public function test_accepted_action_is_not_in_progress(): void
    {
        $action = PlotDispatch::acceptedAction(42);

        $this->assertFalse(PlotDispatch::isInProgressAction($action));
        $this->assertFalse(PlotDispatch::isExhaustedAction($action));
    }

    public function test_started_action_uses_active_prefix(): void
    {
        $action = PlotDispatch::startedAction(7);

        $this->assertStringStartsWith(PlotDispatch::ACTIVE_PREFIX, $action);
        $this->assertTrue(PlotDispatch::isInProgressAction($action));
    }
}
