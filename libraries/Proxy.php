<?php
namespace packages\whoiser;

use packages\base\{DB, db\DBObject, Log, DB\Parenthesis};

class Proxy extends DBObject {

	public static function findOrCreate(string $ip, int $port, string $type, ?string $countryCode = null): self {
		$model = (new self)->where("ip", $ip)->where("port", $port)->getOne();
		if (!$model) {
			$model = new self();
			$model->ip = $ip;
			$model->port = $port;
			$model->type = $type;
			$model->country_code = $countryCode;
			$model->save();
		}
		return $model;
	}

	public static function cleanup(): void {
		$log = Log::getInstance();
		$log->info("cleanup bad proxies...");
		$result = DB::where("fail_count > success_count")->delete("whoiser_proxies");
		$log->reply("done, result:", $result);
	}

	public static function giveTheBest(): ?self {
		return (new self)
			->where("'success_count'", "fail_count", ">=")
			// ->orderBy("success_count", "DESC")
			->orderBy("fail_count", "ASC")
			->orderBy("RAND()")
			->getOne();
	}

	protected $dbTable = "whoiser_proxies";
	protected $primaryKey = "id";
	protected $dbFields = array(
		"ip" => array("type" => "text", "required" => true),
		"port" => array("type" => "int", "required" => true),
		"type" => array("type" => "text", "required" => true),
		"country_code" => array("type" => "text"),
		"success_count" => array("type" => "int", "required" => true),
		"fail_count" => array("type" => "int", "required" => true),
	);

	public function preLoad(array $data): array {
		$data["success_count"] = $data["success_count"] ?? 0;
		$data["fail_count"] = $data["fail_count"] ?? 0;
		return $data;
	}

	public function incrementFailCount(int $count = 1) {
		DB::where("id", $this->id)
			->update("whoiser_proxies", array(
				"fail_count" => DB::inc($count),
			));
	}
	public function incrementSuccessCount(int $count = 1) {
		DB::where("id", $this->id)
			->update("whoiser_proxies", array(
				"success_count" => DB::inc($count),
			));
	}
}
