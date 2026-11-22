<?php

declare(strict_types=1);

namespace Qiick\Access\Tests\Support\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Qiick\Access\Contracts\TeamContext;

/**
 * Sets the Spatie team id during handle so a following job can prove isolation.
 */
final class SwitchTeamJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public string $teamId,
    ) {}

    public function handle(TeamContext $teamContext): void
    {
        $teamContext->switchTo($this->teamId);
    }
}
