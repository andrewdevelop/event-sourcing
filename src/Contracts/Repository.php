<?php

namespace Core\EventSourcing\Contracts;


interface Repository
{
    public function load(string $uuid, ?int $version = null): AggregateRoot;

    public function save(AggregateRoot $aggregate): AggregateRoot;
}