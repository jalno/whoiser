<?php
namespace packages\whoiser\nio;

class EventLoop {
	private static $lastTimerID = 1;
	private $timers = [];
	private $streams = [];
	private $interruptRequest = false;
	public function stop() {
		$this->interruptRequest = true;
	}
	public function setTimeout(callable $callback, int $timeout): int {
		$this->timers[self::$lastTimerID] = array(
			"callback" => $callback,
			"lasthit" => time(),
			"timer" => $timeout,
			"once" => true,
		);
		return self::$lastTimerID++;
	}
	public function setInterval(callable $callback, int $timeout): int {
		$this->timers[self::$lastTimerID] = array(
			"callback" => $callback,
			"lasthit" => time(),
			"timer" => $timeout,
			"once" => false,
		);
		return self::$lastTimerID++;
	}
	public function clearTimer(int $timerID): void {
		unset($this->timers[$timerID]);
	}
	public function addStream(Stream $stream) {
		$this->streams[] = $stream;
	}
	protected function hitTimers(): void {
		foreach ($this->timers as $key => $timer) {
			if (time() < $timer["lasthit"] + $timer["timer"]) {
				continue;
			}
			$this->timers[$key]["lasthit"] = time();
			$timer["callback"]();
			if ($timer["once"]) {
				$this->clearTimer($key);
			}
		}
	}
	
	public function run() {
		// $this->interruptRequest = false;
		while(!$this->interruptRequest) {
			$this->hitTimers();
			$readable = [];
			$writable = [];

			foreach ($this->streams as $x => $stream) {
				$status = $stream->getStatus();
				if ($status & Stream::CLOSED) {
					array_splice($this->streams, $x, 1);
					continue;
				}
				if ($status & Stream::WAIT_FOR_READABLE) {
					$readable[] = $stream;
				}
				if ($status & Stream::WAIT_FOR_WRITABLE) {
					$writable[] = $stream;
				}
			}
			$except = null;
			if (empty($readable)) {
				$readable = null;
			}
			if (empty($writable)) {
				$writable = null;
			}

			if (empty($readable) and empty($writable) and empty($except)) {
				echo "EventLoop: Nothing To DO, break\n";
				$this->streams = [];
				break;
				usleep(700000);
				continue;
			}
			$getResources = function(Stream $stream) {
				$resource = $stream->getStream();
				// var_dump(socket_get_status($resource));
				return $resource;
			};

			$resourceReadable = $readable ? array_map($getResources, $readable) : null;
			$resourceWritable = $writable ? array_map($getResources, $writable) : null;
			$resourceExcept = $except ? array_map($getResources, $except) : null;

			$copyResourceReadable = $resourceReadable ?? [];
			$copyResourceWritable = $resourceWritable ?? [];
			$copyResourceExcept = $resourceExcept ?? [];

			$wait = socket_select($resourceReadable, $resourceWritable, $resourceExcept, 1);
			if ($wait === false) {
				echo "EventLoop: can not select socket\n";
				break;
			}
			if (!$wait) {
				continue;
			}
            if ($resourceReadable) {
                foreach ($resourceReadable as $resource) {
					$found = array_search($resource, $copyResourceReadable);
					if ($found === false) {
						continue;
					}
                	$readable[$found]->trigger(new Event("ready-data"));
                }
			}
            if ($resourceWritable) {
                foreach ($resourceWritable as $resource) {
					$found = array_search($resource, $copyResourceWritable);
					if ($found === false) {
						continue;
					}
                    $writable[$found]->trigger(new Event("wrote"));
                }
            }
		}
	}
}
