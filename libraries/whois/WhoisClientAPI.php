<?php
namespace packages\whoiser;

use packages\whois\WhoisClient;

class WhoisClientAPI extends WhoisClient {

    /** @var boolean Deep whois? */
    public $deepWhois = true;

    public function getWhoisServerForDomain(string $query): ?string {
		$tldsForTest = $this->getTLDsForDomain($query);
		foreach ($tldsForTest as $tld) {
			if (isset($this->WHOIS_SPECIAL[$tld])) {
                $server = $this->WHOIS_SPECIAL[$tld];
                if ($server) {
                    $domain = substr($query, 0, -strlen($tld) - 1);
                    $server = str_replace('{domain}', $domain, $server);
                    return str_replace('{tld}', $tld, $server);
                }
			}
		}
		foreach ($tldsForTest as $tld) {
			// Determine the top level domain, and it's whois server using
			// DNS lookups on 'whois-servers.net'.
			// Assumes a valid DNS response indicates a recognised tld (!?)
			$server = $tld . '.whois-servers.net';

			/* if (gethostbyname($cname) == $cname) {
				continue;
			} */

			return $server;
		}
		return null;
    }
    
    public function prepareParserForDomain(string $query): void {
        $handler = "";
        foreach ($this->getTLDsForDomain($query) as $htld) {
            // special handler exists for the tld ?
            if (isset($this->DATA[$htld])) {
                $handler = $this->DATA[$htld];
                break;
            }

            // Regular handler exists for the tld ?
            if (class_exists(\packages\whois::class.'\\'.$htld.'_handler')) {
                $handler = $htld;
                break;
            }
        }

        // If there is a handler set it
        if ($handler) {
            $this->query['file'] = ($handler != "gtld" ? "whois.$handler.php" : "");
            $this->query['handler'] = $handler;
        }
    }

    private function getTLDsForDomain(string $query): array {
        $tldsForTest = array();
		$parts = explode('.', $query);
        $count = count($parts) - 1;
        for ($i = 0; $i < $count; $i++) {
            array_shift($parts);
            $tldsForTest[] = implode('.', $parts);
        }
        return $tldsForTest;
    }
}
