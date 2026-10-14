<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Schema;

use Throwable;

final class PostgresForeignKey
{
    public function isViolation(Throwable $exception): bool
    {
        $current = $exception;

        while ($current !== null) {
            if ((string) $current->getCode() === '23503'
                || str_contains($current->getMessage(), 'SQLSTATE[23503]')
                || str_contains($current->getMessage(), 'violates foreign key constraint')) {
                return true;
            }

            $current = $current->getPrevious();
        }

        return false;
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $referencedColumns
     */
    public function notValidStatement(
        string $table,
        string $name,
        array $columns,
        string $referencedTable,
        array $referencedColumns,
        string $onDelete = '',
        string $onUpdate = '',
    ): ?string {
        if ($table === '' || $name === '' || $columns === [] || $referencedTable === '' || $referencedColumns === []) {
            return null;
        }

        $sql = sprintf(
            'alter table %s add constraint %s foreign key (%s) references %s (%s)',
            $this->quote($table),
            $this->quote($name),
            implode(', ', array_map($this->quote(...), $columns)),
            $this->quote($referencedTable),
            implode(', ', array_map($this->quote(...), $referencedColumns)),
        );

        $onDelete = $this->action($onDelete);
        $onUpdate = $this->action($onUpdate);

        if ($onDelete !== null) {
            $sql .= ' on delete '.$onDelete;
        }

        if ($onUpdate !== null) {
            $sql .= ' on update '.$onUpdate;
        }

        return $sql.' not valid';
    }

    private function action(string $action): ?string
    {
        $action = strtolower(str_replace('_', ' ', trim($action)));

        return match ($action) {
            'cascade', 'restrict', 'no action', 'set null', 'set default' => $action,
            default => null,
        };
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
