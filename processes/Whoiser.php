<?php
namespace packages\whoiser\processes;

use \Generator;
use \ReflectionFunction;
use \InvalidArgumentException;
use packages\base\{Date, DB, Log, Process};
use packages\whoiser\{Domain, WhoisClientAPI};
use packages\whoiser\nio\{Event, EventLoop, TCPSocket, Event\DataEvent, Event\CloseEvent};

class Whoiser extends Process {

	protected const MAX_CONCURRENT_REQUESTS = 5;

	protected ?bool $dryRun = false;
	protected ?bool $verbose = false;

	public function start(array $data) {
		$this->dryRun = $data["dry-run"] ?? false;
		$this->verbose = $data["verbose"] ?? false;
		if ($this->verbose) {
			Log::setLevel("debug");
		}
		$log = Log::getInstance();
		$log->info(Whoiser::class."@start");

		$generators = $data["generators"] ?? null;
		if (empty($generators)) {
			throw new InvalidArgumentException("you should pass 'generators' as array of Generator");
		}
		$generators = is_array($generators) ? $generators : array($generators);
		foreach ($generators as $key => $generator) {
			if (!($generator instanceof Generator)) {
				throw new InvalidArgumentException("the passed argument 'generators[{$key}]' is not instance of Generator!");
			}
		}

		$processor = function (array $domains) use (&$log) {
			$result = $this->getWhoisOfDomains($domains);

			if (count($domains) != count($result)) {
				$log->warn("something went wrong in get whois of one or more than one domain!");
				$diff = array_diff($domains, array_keys($result));
				$log->reply("result for:", $diff, "is not exists!");
			}

			foreach ($result as $domain => $data) {
				$this->createOrUpdateDomain($domain, $data);
			}
		};

		foreach ($generators as $generator) {
			$chunk = array();
			foreach ($generator as $domain) {
				$chunk[] = $domain;
				if (count($chunk) >= self::MAX_CONCURRENT_REQUESTS) {
					$processor($chunk);
					$chunk = array();
				}
			}
			if ($chunk) {
				$processor($chunk);
			}
		}

	}

	public function updater(array $data) {
		$this->dryRun = $data["dry-run"] ?? false;
		$this->verbose = $data["verbose"] ?? false;
		if ($this->verbose) {
			Log::setLevel("debug");
		}
		$log = Log::getInstance();
		$log->info(Whoiser::class."@updator");

		$processor = function (array $domains) use (&$log) {
			$rawDomains = array_column($domains, "domain");
			$result = $this->getWhoisOfDomains($rawDomains);

			if (count($domains) != count($result)) {
				$log->warn("something went wrong in get whois of one or more than one domain!");
				$diff = array_diff($rawDomains, array_keys($result));
				$log->reply("result for:", $diff, "is not exists!");
			}

			foreach ($domains as $model) {
				$data = $result[$model->domain] ?? null;
				if ($data) {
					$this->updateDomainModel($model, $data);
				}
			}
		};

		$offset = $count = 0;
		$limit = 255;
		$domains = [];
		do {
			$model = new Domain();
			$model->where("is_registered", "1", "!=");
			$model->where("expire_at", Date::time(), "<=");
			$model->orderBy("expire_at", "ASC");
			$domains = $model->get([$offset, $limit]);
			$count = count($domains);
			$offset += $limit;

			$chunk = array();
			for ($x = 0; $x < $count; $x++) {
				$chunk[] = $domains[$x];
				if (count($chunk) >= self::MAX_CONCURRENT_REQUESTS) {
					$processor($chunk);
					$chunk = array();
				}
				unset($domains[$x]);
			}
			if ($chunk) {
				$processor($chunk);
			}
		} while ($count == $limit);
	}

	/**
	 * get whois of the given domains
	 * Note: the requests is run concurrently! so, you control the number of concurrent requests with count of given domains!
	 *
	 * @param string[] $domains
	 * @return array<array{"domain": string, is_registered": bool, "registrar": string, "statuses": string[], "create_at": int|null, "change_at": int|null, "expire_at": int|null}>
	 */
	protected function getWhoisOfDomains(array $domains): array {
		$log = Log::getInstance();

		$loop = new EventLoop();

		$api = new WhoisClientAPI();
		$result = array();
		foreach ($domains as $domain) {
			$log->info("get whois server for domain: '{$domain}'");
			$whoisServer = $api->getWhoisServerForDomain($domain);
			$log->reply("done: '{$whoisServer}'");

			$log->info("create socket for domain:", $domain);
			$con = TCPSocket::connect($whoisServer, 43);

			$con->on("connect", function () use (&$log, $domain, $con) {
				$log->info("socket:connect: (domain: {$domain}) connected! write on it...");
				$con->write("{$domain}\r\n");
			});

			$con->on("error", function(Event $event) use (&$log, $domain) {
				$log->warn("socket:error: (domain: {$domain}) faced error! error code:", $event->getErrno(), "error msg:", $event->getText());
			});

			$con->on("data", function (DataEvent $e) use (&$log, $domain) {
				$log->info("socket:data: (domain: {$domain}) got data!");
			});

			$con->on("close", function (CloseEvent $e) use (&$log, $domain, &$result, &$api) {
				$log->info("socket:close: (domain: {$domain}) closed!");

				$whoisResult = $e->getResult();
				$log->reply("whois result:", $whoisResult);

				if (!$whoisResult) {
					$log->warn("socket:close: (domain: {$domain}) connection is closed and the result is empty! so, skip...");
					return;
				}
				$api->prepareParserForDomain($domain);
				$rawData = ["rawdata" => explode("\n", $whoisResult)];
				$parsedResult = $api->process($rawData, true);
	
				$statuses = array();
				if (isset($parsedResult["regrinfo"]["domain"]["status"]) and is_array($parsedResult["regrinfo"]["domain"]["status"])) {
					$statuses = array_map(
						fn(string $str) => trim(preg_replace("/\b(https?|ftp|file):\/\/[-A-Z0-9+&@#\/%?=~_|$!:,.;]*[A-Z0-9+&@#\/%=~_|$]/i", "", $str)),
						$parsedResult["regrinfo"]["domain"]["status"]
					);
				}

				$registered = $parsedResult["regrinfo"]["registered"] ?? "";

				$createAt = $parsedResult["regrinfo"]["domain"]["created"] ?? null;
				$changedAt = $parsedResult["regrinfo"]["domain"]["changed"] ?? null;
				$expireAt = $parsedResult["regrinfo"]["domain"]["expires"] ?? null;

				$result[$domain] = array(
					"domain" => $domain,
					"is_registered" => trim($registered) == "yes",
					"registrar" => $parsedResult["regyinfo"]["registrar"] ?? "",
					"statuses" => $statuses,
					"nservers" => $parsedResult["regrinfo"]["domain"]["nserver"] ?? [],
					"create_at" => $createAt ? Date\Gregorian::strtotime($createAt) : null,
					"create_at_human_readable" => $createAt,
					"change_at" => $changedAt ? Date\Gregorian::strtotime($changedAt) : null,
					"change_at_human_readable" => $changedAt,
					"expire_at" => $expireAt ? Date\Gregorian::strtotime($expireAt) : null,
					"expire_at_human_readable" => $expireAt,
				);
			});

			$loop->addStream($con);
		}

		$loop->run();

		return $result;
	}

	/**
	 * update a Domain model of a domain with given data or create new one if not exists
	 *
	 * @param string $domain that is a valid domain, like: mandi.com
	 * 	Note: the domain should be valid! cuz It's not being checked!
	 * @param array<array{"is_registered": bool, "registrar": string, "statuses": string[], "create_at": int|null, "change_at": int|null, "expire_at": int|null}> $data
	 */
	private function createOrUpdateDomain(string $domain, array $data): ?Domain {
		$model = (new Domain)->where("domain", $domain)->getOne();
		if (!$model) {
			$model = new Domain();
			$model->domain = $domain;
			$parts = explode(".", $domain);
			$model->name = $parts[0];
			$model->tld = $parts[1];
		}
		$this->updateDomainModel($model, $data);
		return $model;
	}

	/**
	 * update a Domain model with given data
	 *
	 * @param Domain $model
	 * @param @param array<array{"is_registered": bool, "registrar": string, "statuses": string[], "create_at": int|null, "change_at": int|null, "expire_at": int|null}> $data $data
	 */
	private function updateDomainModel(Domain $model, array $data) {
		$log = Log::getInstance();
		foreach (array("is_registered", "statuses", "registrar", "nservers", "create_at", "change_at", "expire_at") as $field) {
			if (array_key_exists($field, $data)) {
				$model->$field = $data[$field];
			}
		}
		$model->whois_at = Date::time();
		if ($this->dryRun) {
			$log->warn("run on dry-run mode!, nothing will change!");
		} else {
			$model->save();
		}
	}
}

