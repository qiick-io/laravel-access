<?php

declare(strict_types=1);

namespace Qiick\Access\Tests\Support\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Qiick\Access\Contracts\TeamContext;

/**
 * Records the Spatie team id visible at the start of handle().
 */
final class CaptureTeamIdJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** @var list<string|int|null> */
    public static array $teamIds = [];

    public function handle(TeamContext $teamContext): void
    {
        self::$teamIds[] = $teamContext->currentId();
    }

    public static function reset(): void
    {
        self::$teamIds = [];
    }
}
