<?php
namespace packages\whoiser\nio;

use \Closure;

class EventEmitter {
	private $listeners = array(
		// 'event-name' => array(
		// 	array(
		// 		'callback' => "callable",
		// 		'once' => "bool",
		// 	),
		// ),
	);
	public function on(string $event, Closure $callback) {
		if (!isset($this->listeners[$event])) {
			$this->listeners[$event] = [];
		}
		$this->listeners[$event][] = array(
			'callback' => $callback,
			'once' => false,
		);
	}
	public function once(string $event, Closure $callback) {
		if (!isset($this->listeners[$event])) {
			$this->listeners[$event] = [];
		}
		$this->listeners[$event][] = array(
			'callback' => $callback,
			'once' => true,
		);
	}
	public function off(string $event, ?Closure $callback) {
		if (!isset($this->listeners[$event])) {
			return;
		}
		if (!$callback) {
			unset($this->listeners[$event]);
			return;
		}
        for ($x = 0, $l = count($this->listeners[$event]); $x < $l; $x++) {
			if ($this->listeners[$event][$x]['callback'] == $callback) {
				array_splice($this->listeners[$event], $x, 1);
				$l--;
			}
        }
	}
	public function trigger(Event $event) {
		$name = $event->getName();
		if (!isset($this->listeners[$name])) {
			return;
		}
		for ($x = 0, $l = count($this->listeners[$name]); $x < $l; $x++) {
			// call_user_func($this->listeners[$name][$x]['callback'], $event);
			$result = $this->listeners[$name][$x]['callback']->call($this, $event);
			if ($this->listeners[$name][$x]['once']) {
				array_splice($this->listeners[$name], $x, 1);
				$l--;
			}
			if ($result === false) {
				break;
			}
        }
	}
}
