<?php
namespace packages\whoiser\nio;

class Event {
	protected $name;
	protected ?array $data;
	public function __construct(string $name) {
		$this->name = $name;
	}

	public function getName(): string {
		return $this->name;
	}

	public function addData(string $name, $value) {
		$this->data[$name] = $value;
	}
	public function getData(string $name) {
		return $this->data[$name] ?? null;
	}
	public function removeData(string $name) {
		unset($this->data[$name]);
	}
}