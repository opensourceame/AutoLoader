<?php

namespace opensourceame\Autoloader;

class Entity
{
	public            $location;
	public            $status;
	public            $lastCheckTime;
	public            $lastCheckCount;

	/**
	 * Set the properties of the entity
	 *
	 * @param array $data
	 * @return \opensourceame\AutoLoader\Entity
	 */
	static function __set_state(array $data) {

		$entity = new Entity;

		foreach($data as $key => $val) {
			$entity->$key = $val;
		}

		return $entity;
	}
}
