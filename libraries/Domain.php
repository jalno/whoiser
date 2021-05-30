<?php
namespace packages\whoiser;

use packages\base\{Date, db\DBObject};

class Domain extends DBObject {

	protected $dbTable = "whoiser_domains";
	protected $primaryKey = "id";
	protected $dbFields = array(
		"name" => array("type" => "text", "required" => true),
		"tld" => array("type" => "text", "required" => true),
		"domain" => array("type" => "text", "required" => true),
		"is_registered" => array("type" => "int", "required" => true),
		"statuses" => array("type" => "text"), // EPP status
		"registrar" => array("type" => "text"),
		"nservers" => array("type" => "text"),
		"create_at" => array("type" => "int"),
		"change_at" => array("type" => "int"),
		"expire_at" => array("type" => "int"),
		"whois_at" => array("type" => "int", "required" => true),
	);
	protected $jsonFields = ["statuses", "nservers"];

	public function preLoad(array $data): array {
		if (!isset($data["whois_at"])) {
			$data["whois_at"] = Date::time();
		}
		return $data;
	}
}
