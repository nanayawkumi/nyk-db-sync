<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Database\Concerns;

use Nanayawkumi\NykDbSync\Database\DualWriteQueryBuilder;

trait BuildsDualWriteQueries
{
    /**
     * Get a query builder that also applies writes to the secondary connection.
     */
    public function query(): DualWriteQueryBuilder
    {
        return new DualWriteQueryBuilder(
            $this,
            $this->getQueryGrammar(),
            $this->getPostProcessor(),
        );
    }
}
