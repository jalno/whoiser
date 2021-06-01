<?php
namespace packages\whoiser\events\Domain;

use packages\base\Event;
use packages\whoiser\Domain;

class Add extends Event {

	private $model;

	public function __construct(Domain $model) {
		$this->model = $model;
	}

	public function getDomain(): Domain {
		return $this->model;
	}
}
