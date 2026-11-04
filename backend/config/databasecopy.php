<?php
// Example only. install.sh writes the real backend/config/database.php (git ignored) for each ISP.
class Database {
    private $host = "localhost";
    private $db_name = "demo";
    private $username = "demo";
    private $password = "demo";
    private $app_url = "https://demo.ispledger.net";
    private $subdomain = "demo";
    // Encrypts the OLT passwords at rest. 32 random bytes, base64, made by install.sh.
    private $secret_key = "CHANGE_ME";
    // Shared with the billing dashboard; the dashboard's API token is an HMAC of the subdomain with it.
    private $fleet_secret = "CHANGE_ME";
    public $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            return $this->conn;
        } catch(PDOException $e) {
            throw $e;
        }
    }

    public function getAppUrl() { return $this->app_url; }
    public function getSubdomain() { return $this->subdomain; }
    public function getSecretKey() { return $this->secret_key; }
    public function getFleetSecret() { return $this->fleet_secret; }
}
