<?php
namespace packages\whoiser\processes;

use packages\base\{Cache, Date, db, Http, Process, Log, Json};
use packages\whoiser\{Proxy as ProxyModel};

class Proxy extends Process {

	protected ?bool $verbose = false;

	public function start(array $data) {
		$this->verbose = $data["verbose"] ?? false;
		if ($this->verbose) {
			Log::setLevel("debug");
		}
		$log = Log::getInstance();

		ProxyModel::cleanup();

		$log->info("get socks5 proxies...");
		$result = $this->requestForProxies("socks5");
		$log->reply("count:", count($result["list"]));


		$updateAt = $result["update_at"];
		$lastSyncAt = Cache::get("packages.whoiser.processes.proxy.last_sync_at") ?? 0;
		
		$log->info("check the given data is new? the response update_at:", $updateAt, "the last sync:", $lastSyncAt);

		if ($updateAt > $lastSyncAt) {
			$log->reply("the data is new, process it");
			foreach ($result["list"] as $proxy) {
				ProxyModel::findOrCreate($proxy["ip"], $proxy["port"], $proxy["type"], $proxy["country_code"]);
			}
			Cache::set("packages.whoiser.processes.proxy.last_sync_at", $updateAt, 0);
		} else {
			$log->reply("the data is not new, skip...");
		}
	}

	protected function requestForProxies(string $type): array {
		$type = strtolower($type);
		$log = Log::getInstance();
		$log->info("try get proxies...");
		$result = array(
			"update_at" => null,
			"list" => array(),
		);

		try {
			$response = (new Http\Client)->get("https://www.proxy-list.download/api/v0/get", array(
				"query" => array(
					"l" => "en",
					"t" => $type,
				),
				"headers" => array(
					"Referer" => "https://www.proxy-list.download/SOCKS5",
					"User-Agent" => "Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:80.0) Gecko/20100101 Firefox/80.0",
				),
				"connect_timeout" => 10,
				"timeout" => 10,
			));
			$body = $response->getBody();
			$decoded = Json\decode($body);

			if (preg_match("/content\=\"(.*)\"/", $decoded[0]["UPDATEDAV"], $matches)) {
				$result["update_at"] = strtotime($matches[1]);
			}
			$result["list"] = array_map(fn($item) => array(
				"ip" => $item["IP"],
				"port" => $item["PORT"],
				"type" => $type,
				"country_code" => $item["ISO"],
			), $decoded[0]["LISTA"]);

		} catch (HTTP\ServerException $e) {
			$log->reply()->error("server exception!");
		} catch (HTTP\ClientException $e) {
			$log->reply()->error("client exception!");
		} catch (JSON\JsonException $e) {
			$log->reply()->error("json exception!");
		}
		return $result;
	}
	
}