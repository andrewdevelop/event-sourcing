<?php

namespace Core\EventSourcing\Contracts;

interface Transactional
{
    /**
     * Begin a transaction.
     * @return void
     */
    public function beginTransaction(): void;

    /**
     * Commit the current transaction.
     * @return void
     */
    public function commitTransaction(): void;

    /**
     * Rollback the current transaction.
     * @return void
     */
    public function rollbackTransaction(): void;
}