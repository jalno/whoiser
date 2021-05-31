<?php
namespace packages\whoiser;

use \Iterator;
use \InvalidArgumentException;
use packages\base\{Date, view\Error, DB, Log, Process};
use packages\whoiser\{Domain, Proxy, WhoisClientAPI};
use packages\whoiser\nio\{Event, EventLoop, TCPSocket, Event\DataEvent, Event\CloseEvent};

class WhoiserAPI {

	public static $tryCounter = array();

	/**
	 * get whois of domains based on iterator and save the result into whoiser_domains table
	 *
	 * @param Iterator $iterator that give string of domain name
	 * @param array<{"dry-run": bool, "verbose": bool, "max-concurrent-requests": int, "use-proxy": bool, "force-proxy": bool}> $options
	 */
	public static function creator(Iterator $iterator, ?array $options = null) {
		$maxConcurrentRequests = $options["max-concurrent-requests"] ?? 10;
		$maxConcurrentRequests = is_numeric($maxConcurrentRequests) ? $maxConcurrentRequests : 10;
		$dryRun = $options["dry-run"] ?? false;
		$verbose = $options["verbose"] ?? false;

		if ($verbose) {
			Log::setLevel("debug");
		}
		$log = Log::getInstance();

		/** @param string[] */
		$processor = function (array $domains) use (&$log, $options) {
			$dbDomains = array_column((new Domain)->where("domain", $domains, "IN")->get(null, "domain"), "domain");
			$domains = array_diff($domains, $dbDomains);
			if (empty($domains)) {
				return;
			}

			$result = self::getWhoisOfDomains($domains, $options);

			if (count($domains) != count($result)) {
				$log->warn("something went wrong in get whois of one or more than one domain!");
				$diff = array_values(array_diff($domains, array_keys($result)));
				$log->reply("result for:", $diff, "is not exists!");
			}

			foreach ($result as $domain => $whois) {
				self::createOrUpdateDomain($domain, $whois);
			}
		};

		$chunk = array();
		foreach ($iterator as $domain) {
			if (!is_string($domain)) {
				throw new InvalidArgumentException("the given value of iterator is not string! value: '{$domain}'");
			}
			$chunk[] = $domain;
			if ((new Domain)->where("domain", $domain)->has()) {
				continue;
			}
			if (count($chunk) >= $maxConcurrentRequests) {
				$processor($chunk);
				$chunk = array();
			}
		}
		if ($chunk) {
			$processor($chunk);
		}
	}

	/**
	 * get whois of domains based on iterator and save the result into whoiser_domains table
	 *
	 * @param Iterator $iterator that give Domain model
	 * @param array<{"dry-run": bool, "verbose": bool, "max-concurrent-requests": int, "use-proxy": bool, "force-proxy": bool}> $options
	 */
	public static function updater(Iterator $iterator, array $options) {
		$maxConcurrentRequests = $options["max-concurrent-requests"] ?? 10;
		$maxConcurrentRequests = is_numeric($maxConcurrentRequests) ? $maxConcurrentRequests : 10;
		$dryRun = $options["dry-run"] ?? false;
		$verbose = $options["verbose"] ?? false;

		if ($verbose) {
			Log::setLevel("debug");
		}
		$log = Log::getInstance();

		/** @param Domain[] */
		$processor = function (array $models) use (&$log, $options) {
			$rawDomains = array_column($models, "domain");
			$result = self::getWhoisOfDomains($rawDomains, $options);

			if (count($models) != count($result)) {
				$log->warn("something went wrong in get whois of one or more than one domain!");
				$diff = array_values(array_diff($rawDomains, array_keys($result)));
				$log->reply("result for:", $diff, "is not exists!");
			}

			foreach ($models as $model) {
				$whois = $result[$model->domain] ?? null;
				if ($whois) {
					self::updateDomainModel($model, $whois);
				}
			}
		};

		$chunk = array();
		foreach ($iterator as $model) {
			if (!($model instanceof Domain)) {
				throw new InvalidArgumentException("iterator is given wrong item! iterator should give '" . Domain::class . "' value!");
			}
			$chunk[] = $model;
			if (count($chunk) >= $maxConcurrentRequests) {
				$processor($chunk);
				$chunk = array();
			}
		}
		if ($chunk) {
			$processor($chunk);
		}
	}

	/**
	 * get whois of the given domains
	 * Note: the requests is run concurrently! so, you control the number of concurrent requests with count of given domains!
	 *
	 * @param string[] $domains
	 * @param array<{"use-proxy": bool, "force-proxy": bool}> $options
	 * @return array<array{"domain": string, is_registered": bool, "registrar": string, "statuses": string[], "create_at": int|null, "change_at": int|null, "expire_at": int|null}>
	 */
	protected static function getWhoisOfDomains(array $domains, ?array $options = null) {
		$log = Log::getInstance();
		$loop = new EventLoop();

		$result = array();
		foreach ($domains as $domain) {
			$result[$domain] = [];

			$socket = self::socketCreator($domain, $result[$domain], $options);

			$loop->addStream($socket);

			$loop->setTimeout(function () use (&$log, $socket, $domain) {
				$log->error("loop:setTimeout:reached! for domain: ({$domain}), remove socket");
				$socket->close();
			}, 3);
		}

		$loop->run();

		$unsuccessfullDomains = array();
		foreach ($domains as $domain) {
			if ($result[$domain]) {
				continue;
			}
			self::$tryCounter[$domain] = self::$tryCounter[$domain] ?? 1;
			if (self::$tryCounter[$domain] <= 5) {
				$unsuccessfullDomains[] = $domain;
			} else {
				unset(self::$tryCounter[$domain]);
			}
		}
		if ($unsuccessfullDomains) {
			$result = array_merge($result, self::getWhoisOfDomains($unsuccessfullDomains, $options));
		}
		
		return array_filter($result, fn($item) => !empty($item));
	}

	/**
	 * create socket for request whois of a domain
	 *
	 * @param string $domain
	 * @param array &$result that is the result of whois and may be empty!
	 * @param array<{"use-proxy": bool, "force-proxy": bool}> $options
	 * @return TCPSocket
	 */
	protected static function socketCreator(string $domain, array &$result, ?array $options = null): TCPSocket {
		$useProxy = $options["use-proxy"] ?? true;
		$forceProxy = $options["force-proxy"] ?? true;

		$log = Log::getInstance();
		$api = new WhoisClientAPI();

		$log->info("create socket for domain:", $domain);

		$log->info("domain: '{$domain}' whois server?");
		$whoisServer = $api->getWhoisServerForDomain($domain);
		$log->reply("'{$whoisServer}'");
		
		$proxy = null;
		if ($useProxy) {
			$proxy = Proxy::giveTheBest();
			$log->info("use proxy:", $proxy ? "#{$proxy->id} ({$proxy->ip}:{$proxy->port})" : "NO");
		}
		if ($forceProxy and !$proxy) {
			throw new Error("packages.whoiser.WhoiserAPI.getWhoisOfDomains.proxy.force_proxy.proxy_not_found");
		}

		$log->info("create socket for domain:", $domain);
		$socket = null;

		if ($proxy) {
			$socket = TCPSocket::connectSocks5WithNoAuth($proxy->ip, $proxy->port);

			$socket->on("proxy-connect", function () use (&$log, $domain, $socket, $whoisServer) {
				$log->info("socket:proxy-connect: (domain: {$domain}) connected! write on it...");
				$socket->connect($whoisServer, 43);
			});
		} else {
			$socket = TCPSocket::create($whoisServer, 43)->connect();
		}

		$socket->on("connect", function () use (&$log, $domain, $socket) {
			$log->info("socket:connect: (domain: {$domain}) connected! write on it...");
			$socket->write("{$domain}\r\n");
		});

		$socket->on("error", function(Event $event) use (&$log, $domain, $proxy) {
			$log->warn("socket:error: (domain: {$domain}) faced error! error code:", $event->getErrno(), "error msg:", $event->getText());
			if ($proxy) {
				$proxy->incrementFailCount();
			}
		});

		$socket->on("data", function (DataEvent $e) use (&$log, $domain) {
			$log->info("socket:data: (domain: {$domain}) got data!");
		});

		$socket->on("close", function (CloseEvent $e) use (&$log, $domain, &$result, &$api, $proxy) {
			$log->info("socket:close: (domain: {$domain}) closed!");

			$success = false;
			$parsedResult = null;

			$whoisResult = $e->getResult();
			if ($whoisResult) {
				$api->prepareParserForDomain($domain);
				$rawData = ["rawdata" => explode("\n", $whoisResult)];
				$parsedResult = $api->process($rawData, true);
				unset($parsedResult["rawdata"]);

				if (isset($parsedResult["regrinfo"]["domain"])) {
					$success = true;
				}
			}

			if (!$success) {
				$log->warn("socket:close: (domain: {$domain}) connection is closed and the result is empty! so, skip...");
				if ($proxy) {
					$proxy->incrementFailCount();
				}
				return;
			}
			if ($proxy) {
				$proxy->incrementSuccessCount();
			}

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

			$result = array(
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

		return $socket;
	}

	/**
	 * update a Domain model of a domain with given data or create new one if not exists
	 *
	 * @param string $domain that is a valid domain, like: mandi.com
	 * 	Note: the domain should be valid! cuz It's not being checked!
	 * @param array<array{"is_registered": bool, "registrar": string, "statuses": string[], "create_at": int|null, "change_at": int|null, "expire_at": int|null}> $data
	 */
	private static function createOrUpdateDomain(string $domain, array $data, ?array $options = null): ?Domain {
		$model = (new Domain)->where("domain", $domain)->getOne();
		if (!$model) {
			$model = new Domain();
			$model->domain = $domain;
			$parts = explode(".", $domain);
			$model->name = $parts[0];
			$model->tld = $parts[1];
		}
		self::updateDomainModel($model, $data, $options);
		return $model;
	}

	/**
	 * update a Domain model with given data
	 *
	 * @param Domain $model
	 * @param @param array<array{"is_registered": bool, "registrar": string, "statuses": string[], "create_at": int|null, "change_at": int|null, "expire_at": int|null}> $data $data
	 */
	private static function updateDomainModel(Domain $model, array $data, ?array $options = null) {
		$dryRun = $options["dry-run"] ?? false;
		$log = Log::getInstance();
		foreach (array("is_registered", "statuses", "registrar", "nservers", "create_at", "change_at", "expire_at") as $field) {
			if (array_key_exists($field, $data)) {
				$model->$field = $data[$field];
			}
		}
		$model->whois_at = Date::time();
		if ($dryRun) {
			$log->warn("run on dry-run mode!, nothing will change!");
		} else {
			$model->save();
		}
	}
}

