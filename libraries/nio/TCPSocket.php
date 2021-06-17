<?php
namespace packages\whoiser\nio;

use packages\whoiser\nio\{Event\DataEvent};

class TCPSocket extends Stream {
	const WAIT_FOR_CONNECT = 0x100 | Stream::WAIT_FOR_WRITABLE;
	const WAIT_FOR_SOCKS5_AUTH = 0x300 | Stream::WAIT_FOR_WRITABLE;

	public static function connectSocks5WithNoAuth(string $host, int $port): TCPSocket {
		$socket = self::create($host, $port)->connect();
		$socket->type = "socks5";
		return $socket;
	}

	public static function create(string $host, int $port): TCPSocket {
		$stream = socket_create(AF_INET, SOCK_STREAM,  SOL_TCP);
		$socket = new TCPSocket($stream);
		$socket->host = $host;
		$socket->port = $port;
		return $socket;
	}

	protected ?string $host;
	protected ?int $port;
	protected ?string $type = null;
	protected ?array $auth = null;

	public function __construct($stream) {
		parent::__construct();
		$this->setStream($stream);
		// socket_set_option($this->stream, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 1, 'usec' => 0));
		// socket_set_option($this->stream, SOL_SOCKET, SO_SNDTIMEO, array('sec' => 1, 'usec' => 0));
		socket_set_nonblock($stream);

		$this->on("wrote", function() {
			if ($this->status & self::WAIT_FOR_CONNECT) {
				$this->status &= ~self::WAIT_FOR_CONNECT;

				if ($this->type == "socks" or $this->type == "socks5") {
					$this->status |= self::WAIT_FOR_SOCKS5_AUTH;

					$data = "\x05"; // VER: socks5
					$data .= "\x01"; // NAUTH: 1
					if (empty($this->auth)) {
						$data .= "\x00"; // AUTH: No authentication
					}
					$this->write($data);

				} else {
					$this->trigger(new Event("connect"));
				}
			}
		});
		$this->on("data", function (DataEvent $e) {
			$data = $e->getBuffer();
			if ($this->status & self::WAIT_FOR_SOCKS5_AUTH) {
				$this->status &= ~self::WAIT_FOR_SOCKS5_AUTH;
				if ($data[1] == "\x00") {
					$this->trigger(new Event("proxy-connect"));
					return false;
				}
			} elseif ($data and $data[1] == "\x00") {
				$this->trigger(new Event("connect"));
				return false;
			}
		});
	}

	public function connect(?string $host = null, ?int $port = null) {
		$host = $host ?: $this->host;
		$port = $port ?: $this->port;
		if ($this->type == "socks" or $this->type == "socks5") {
			$address = "\x03" . chr(strlen($host)) . $host;
			$request = "\x05\x01\x00" . $address . pack("n", $port);
			$this->write($request);
		} else {
			if (socket_connect($this->stream, $host, $port) === false) {
				$this->status |= self::WAIT_FOR_CONNECT;
			}
		}
		return $this;
	}
}