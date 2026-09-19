<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class StatusBadgeTest extends TestCase
{
    public function test_workflow_and_inventory_statuses_use_meaningful_tones(): void
    {
        foreach ([
            'Pending Receipt' => 'pending',
            'Scheduled' => 'scheduled',
            'Lifted' => 'progress',
            'Low Stock' => 'partial',
            'Partially Received' => 'partial',
            'Garage Received With Direct' => 'success',
            'Recorded' => 'success',
            'Delivered' => 'success',
            'Unpaid' => 'danger',
            'Depleted' => 'danger',
        ] as $status => $tone) {
            $html = Blade::render('<x-admin.status-badge :status="$status" />', ['status' => $status]);

            $this->assertStringContainsString('status-tone-'.$tone, $html, $status);
            $this->assertStringContainsString('>'.$status.'</span>', $html, $status);
        }
    }
}
