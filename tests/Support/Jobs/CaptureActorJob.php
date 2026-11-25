<?php

declare(strict_types=1);

namespace Qiick\Access\Tests\Support\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Qiick\Access\Laravel\AccessContext;

final class CaptureActorJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** @var list<array{kind: ?string, uuid: ?string}> */
    public static array $captures = [];

    public function handle(): void
    {
        $context = AccessContext::current();
        self::$captures[] = [
            'kind' => $context?->kind->value,
            'uuid' => $context?->credentialUuid,
        ];
    }

    public static function reset(): void
    {
        self::$captures = [];
    }
}
