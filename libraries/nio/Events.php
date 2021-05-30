<?php
namespace packages\whoiser\nio\Event;

use packages\whoiser\nio\Event;

class ErrorEvent extends Event {
	protected $errno;
	protected $text;
	public function __construct(int $errno){
		parent::__construct("error");
		$this->errno = $errno;
		$this->text = socket_strerror($errno);
	}
	public function getErrno(): int {
		return $this->errno;
	}
	public function getText(): string {
		return $this->text;
	}
}
class DataEvent extends Event {
	protected $buffer;
	public function __construct(string $buffer) {
		parent::__construct("data");
		$this->buffer = $buffer;
	}
	public function getBuffer(): string {
		return $this->buffer;
	}
}
class CloseEvent extends Event {
	protected $result;
	public function __construct(string $result) {
		parent::__construct("close");
		$this->result = $result;
	}
	public function getResult(): string {
		return $this->result;
	}
}
