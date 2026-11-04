
<?php
class Database {
    private $host = "localhost";
    private $db_name = "acs";
    private $username = "acs";
    private $password = "acs";
    private $app_url = "http://acs.ispledger.cc";
    public $conn;

    public function getConnection() {
        $this->conn = null;

        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name,
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $this->conn;
        } catch(PDOException $e) {
            throw $e;
        }
    }

    public function getAppUrl() {
        return $this->app_url;
    }


}
