<?php
namespace packages\whoiser\nio;

class TCPSocket extends Stream {
	const WAIT_FOR_CONNECT = 0x100 | Stream::WAIT_FOR_WRITABLE;
	public static function connect(string $host, int $port): TCPSocket {
		$stream = socket_create(AF_INET, SOCK_STREAM,  SOL_TCP);
		$socket = new TCPSocket($stream);
		$socket->host = $host;
		$socket->port = $port;
		if (socket_connect($stream, $host, $port) === false) {
			$socket->status |= self::WAIT_FOR_CONNECT;
		}
		return $socket;
	}
	protected $host;
	protected $port;
	public function __construct($stream) {
		parent::__construct();
		$this->setStream($stream);
		socket_set_nonblock($stream);

		$this->on("wrote", function() {
			if ($this->status & self::WAIT_FOR_CONNECT) {
				$this->status &= ~self::WAIT_FOR_CONNECT;
				$this->trigger(new Event("connect"));
			}
		});
	}


}