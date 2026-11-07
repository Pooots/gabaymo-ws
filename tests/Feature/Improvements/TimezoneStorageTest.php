<?php

namespace Tests\Feature\Improvements;

use App\Models\Reminder;


class TimezoneStorageTest extends ImprovementTestCase
{
    public function test_utc_iso_datetimes_from_the_browser_keep_their_instant(): void
    {
        config(['app.timezone' => 'Asia/Manila']);
        date_default_timezone_set('Asia/Manila');

        $member = $this->member();

        // 08:00 in Manila, as the browser sends it via toISOString().
        $this->actingAs($member, 'web')
            ->postJson('/api/v1/reminders', ['title' => 'File leave appeal', 'due_at' => '2099-11-14T00:00:00.000Z'])
            ->assertCreated()
            ->assertJsonPath('data.due_at', '2099-11-14T08:00:00+08:00');

        $this->assertSame('2099-11-14 08:00:00', Reminder::query()->first()->getRawOriginal('due_at'));
    }
}
