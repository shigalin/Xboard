<?php

namespace App\Database;

use Closure;
use Illuminate\Database\SQLiteConnection as BaseSQLiteConnection;
use RuntimeException;

class SQLiteConnection extends BaseSQLiteConnection
{
    /**
     * Prepare one write for a report batch and execute it through Laravel's
     * query lifecycle. A reconnect between transactions invalidates the handle.
     */
    public function prepareBatchWrite(string $sql): Closure
    {
        $this->reconnectIfMissingConnection();
        $pdo = $this->getPdo();
        $statement = $this->pretending() ? null
            : $this->runQueryCallback($sql, [], fn () => $pdo->prepare($sql));

        return function (array $bindings) use ($sql, &$pdo, &$statement): void {
            $transactionPdo = $this->getPdo();
            $this->run($sql, $bindings, function ($query, $bindings) use ($transactionPdo, &$pdo, &$statement) {
                if ($this->pretending()) {
                    return;
                }

                $current = $this->getPdo();
                // The counter write and the detail must commit on the same PDO.
                if ($current !== $transactionPdo || !$current?->inTransaction()) {
                    throw new RuntimeException('The traffic transaction changed connection.');
                }
                if ($current !== $pdo || $statement === null) {
                    $statement = $current->prepare($query);
                    $pdo = $current;
                }

                $this->bindValues($statement, $this->prepareBindings($bindings));
                try {
                    $statement->execute();
                    $this->recordsHaveBeenModified($statement->rowCount() > 0);
                } finally {
                    $statement->closeCursor();
                }
            });
        };
    }
}
