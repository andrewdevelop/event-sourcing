<?php 

namespace Core\EventSourcing\Contracts;


interface Repository
{
	public function load($uuid);

	public function save(AggregateRoot $model);

}