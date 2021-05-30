<?php
namespace packages\whoiser\nio;

use packages\whoiser\nio\Event\{ErrorEvent, DataEvent, CloseEvent};

class PendingMessage {
	public $buffer;
	public $callback;
}

class Stream extends EventEmitter {
	// VALUES AFTER 0x100 ARE User-defined
	const WAIT_FOR_READABLE = 0x01;
	const WAIT_FOR_WRITABLE = 0x02;
	const CLOSED = 0x04;
	
	
	public static function fromResource($stream) {
		$obj = new self();
		$obj->setStream($stream);
		return $obj;
	}
	protected $stream;
	protected $status = self::WAIT_FOR_READABLE;
	protected $pending = [];
	protected $blockSize = 4096;
	protected $readData = "";
	protected function __construct() {
		$this->on("ready-data", function() {
			$this->read();
		});
		$this->on("wrote", function() {
			$this->wrote();
		});
	}
	public function getStream() {
		return $this->stream;
	}
	public function getStatus(): int {
		return $this->status;
	}
	public function close() {
		socket_close($this->stream);
		$this->status = self::CLOSED;
		$this->trigger(new CloseEvent($this->readData));
	}
	public function write(string $buffer, callable $callback = null) {
		$message = new PendingMessage();
		$message->buffer = $buffer;
		$message->callback = $callback;
		$this->pending[] = $message;
		if (count($this->pending) == 1) {
			$this->writeToStream();
		}
	}

	private function writeToStream() {
		if (empty($this->pending)) {
			return;
		}
		$lastMessage = $this->pending[0];
		$sent = socket_write($this->stream, $lastMessage->buffer);
		if ($sent === false) {
            if ($lastMessage->callback) {
                $callback = $lastMessage->callback;
                $callback(true);
            }
			array_shift($this->pending);
			$this->status &= ~self::WAIT_FOR_WRITABLE;
			$this->writeToStream();
			return;
		}
		$lastMessage->buffer = substr($lastMessage->buffer, $sent);
		if ($lastMessage->buffer) {
			$this->status |= self::WAIT_FOR_WRITABLE;
			return;
		}
		if ($lastMessage->callback) {
			$callback = $lastMessage->callback;
			$callback(false);
		}
		array_shift($this->pending);
		$this->status &= ~self::WAIT_FOR_WRITABLE;
		$this->writeToStream();
	}
	protected function read() {
		$buffer = socket_read($this->stream, $this->blockSize);
		if ($buffer === false) {
			$errno = socket_last_error($this->stream);
			$this->trigger(new ErrorEvent($errno));
			if ($errno == 107) {
				$this->close();
			}
			return;
		}
		if (empty($buffer)) {
			$this->close();
			return;
		}
		$this->readData .= $buffer;
		$this->trigger(new DataEvent($buffer));
	}
	protected function wrote() {
		if (empty($this->pending)) {
			return;
		}
		$message = $this->pending[0];
		if (empty($message->buffer)) {
			$this->status &= ~self::WAIT_FOR_WRITABLE;
			$message->callback();
			array_shift($this->pending);
		}
		$this->writeToStream();
	}
	protected function setStream($stream) {
		$this->stream = $stream;
		$this->blockSize = socket_get_option($this->stream,  SOL_SOCKET, SO_RCVBUF);
	}
}
