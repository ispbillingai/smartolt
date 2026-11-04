<?php
/**
 * PHP Mikrotik Billing (https://freeispradius.com/)
 * by https://t.me/freeispradius
 */
use PEAR2\Net\RouterOS;

class Mikrotik
{
    public static function info($name, $db)
    {
        try {
            $stmt = $db->prepare("SELECT * FROM tbl_routers WHERE name = :name LIMIT 1");
            $stmt->execute([':name' => $name]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching router info for name '$name': " . $e->getMessage());
            return null;
        }
    }

    public static function removeNatRule($client, $comment)
    {
        try {
            // Create a query to find NAT rules with the given comment
            $request = new RouterOS\Request('/ip/firewall/nat/print');
            $request->setQuery(RouterOS\Query::where('comment', $comment));
            $response = $client->sendSync($request);

            // Check if any rules were found
            $rulesFound = false;
            foreach ($response as $resp) {
                if ($resp->getType() === RouterOS\Response::TYPE_DATA) {
                    $ruleId = $resp->getProperty('.id');
                    // Remove the NAT rule
                    $removeRequest = new RouterOS\Request('/ip/firewall/nat/remove');
                    $removeRequest->setArgument('.id', $ruleId);
                    $client->sendSync($removeRequest);
                    $rulesFound = true;
                }
            }

            return $rulesFound; // Returns true if at least one rule was removed
        } catch (Exception $e) {
            error_log("Failed to remove NAT rule with comment '$comment': " . $e->getMessage());
            return false;
        }
    }

    public static function getClient($ip, $user, $pass, $port = 8728)
    {
        global $_app_stage;
        if ($_app_stage === 'demo') {
            error_log("MikroTik API connection blocked in demo mode.");
            return null;
        }

        try {
            if (!class_exists('PEAR2\Net\RouterOS\Client') || !class_exists('PEAR2\Net\Transmitter\NetworkStream')) {
                throw new Exception("Required PEAR2 classes not found. Ensure the library is installed and autoloaded.");
            }

            $iport = explode(":", $ip);
            $host = $iport[0];
            $port = isset($iport[1]) ? $iport[1] : $port;

            $connectionTest = @fsockopen($host, $port, $errno, $errstr, 5);
            if (!$connectionTest) {
                error_log("Cannot reach MikroTik router at $host:$port - Error: $errstr ($errno)");
                return null;
            }
            fclose($connectionTest);

            $client = new RouterOS\Client($host, $user, $pass, $port, false, 14);
            return $client;
        } catch (Exception $e) {
            error_log("Connection failed to MikroTik router at $host:$port with user $user: " . $e->getMessage());
            return null;
        }
    }

    public static function addNatRule($client, $deviceIp, $port, $deviceName)
    {
        try {
            $request = new RouterOS\Request('/ip/firewall/nat/add');
            $request->setArgument('chain', 'dstnat');
            $request->setArgument('action', 'dst-nat');
            $request->setArgument('to-addresses', $deviceIp);
            $request->setArgument('to-ports', '80');
            $request->setArgument('protocol', 'tcp');
            $request->setArgument('in-interface', 'deviceaccess');
            $request->setArgument('dst-port', $port);
            $request->setArgument('comment', $deviceName);
            $request->setArgument('log', 'no');
            $request->setArgument('log-prefix', '');

            $response = $client->sendSync($request);
            if ($response->getType() === RouterOS\Response::TYPE_FINAL) {
                return true;
            } else {
                throw new Exception("Unexpected response from MikroTik API: " . $response->getType());
            }
        } catch (Exception $e) {
            error_log("Failed to add NAT rule for device $deviceName on port $port: " . $e->getMessage());
            return false;
        }
    }
}