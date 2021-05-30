<?php
namespace packages\whoiser\listeners;

use packages\cronjob\{task\Schedule, Task, events\Tasks};
use packages\whoiser\processes;

class Cronjob {
	public function tasks(Tasks $event): void {
		$event->addTask($this->updateProxies());
	}
	private function updateProxies(): Task {
		$task = new Task();
		$task->name = 'whoiser_proxies_update';
		$task->process = processes\Proxy::class.'@start';
		$task->parameters = array();
		$task->schedules = array(
			new Schedule(array(
				'minute' => 0,
			)),
			new Schedule(array(
				'minute' => 30,
			)),
		);
		return $task;
	}
}
