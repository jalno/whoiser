<?php
namespace packages\whoiser\nio;

class Event {
	protected $name;
	public function __construct(string $name) {
		$this->name = $name;
	}

	public function getName(): string {
		return $this->name;
	}

	public function addData(string $name, $value) {

	}
	public function getData(string $name) {
		
	}
	public function removeData(string $name) {

	}
}