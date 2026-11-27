<?php

declare(strict_types=1);

namespace Qiick\Access\Events;

use Qiick\Access\Erasure\ErasureReport;

/**
 * Announces committed erasure counts so listeners can record completion without personal data.
 *
 * @api
 */
final readonly class SubjectErasedEvent
{
    public function __construct(public ErasureReport $report) {}
}
