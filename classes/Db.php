<?php
class Db {
	private static ?Db $instance = null;

	private ?PDO $pdo = null;

	function __construct() {
		ORM::configure(self::get_dsn());
		ORM::configure('username', Config::get(Config::DB_USER));
		ORM::configure('password', Config::get(Config::DB_PASS));
		ORM::configure('return_result_sets', true);
		if (Config::get(Config::DB_TYPE) == "mysql" && Config::get(Config::MYSQL_CHARSET)) {
			ORM::configure('driver_options', array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . Config::get(Config::MYSQL_CHARSET)));
		}
	}

	/**
	 * @param int $delta adjust generated timestamp by this value in seconds (either positive or negative)
	 * @return string
	 */
	static function NOW(int $delta = 0): string {
		return date("Y-m-d H:i:s", time() + $delta);
	}

	private function __clone() {
		//
	}

	public static function get_dsn(): string {
		$db_port = Config::get(Config::DB_PORT) ? ';port=' . Config::get(Config::DB_PORT) : '';
		$db_host = Config::get(Config::DB_HOST) ? ';host=' . Config::get(Config::DB_HOST) : '';
		if (Config::get(Config::DB_TYPE) == "mysql" && Config::get(Config::MYSQL_CHARSET)) {
			$db_charset = ';charset=' . Config::get(Config::MYSQL_CHARSET);
		} else {
			$db_charset = '';
		}

		return Config::get(Config::DB_TYPE) . ':dbname=' . Config::get(Config::DB_NAME) . $db_host . $db_port . $db_charset;
	}

	// this really shouldn't be used unless a separate PDO connection is needed
	// normal usage is Db::pdo()->prepare(...) etc
	public function pdo_connect() : PDO {

		try {
			$pdo = new PDO(self::get_dsn(),
				Config::get(Config::DB_USER),
				Config::get(Config::DB_PASS));
		} catch (Exception $e) {
			print "<pre>Exception while creating PDO object:" . $e->getMessage() . "</pre>";
			exit(101);
		}

		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		if (Config::get(Config::DB_TYPE) == "pgsql") {

			$pdo->query("set client_encoding = 'UTF-8'");
			$pdo->query("set datestyle = 'ISO, european'");
			$pdo->query("set TIME ZONE 0");
			$pdo->query("set cpu_tuple_cost = 0.5");

		} else if (Config::get(Config::DB_TYPE) == "mysql") {
			$pdo->query("SET time_zone = '+0:0'");

			if (Config::get(Config::MYSQL_CHARSET)) {
				$pdo->query("SET NAMES " . Config::get(Config::MYSQL_CHARSET));
			}
		}

		return $pdo;
	}

	public static function instance() : Db {
		if (self::$instance == null)
			self::$instance = new self();

		return self::$instance;
	}

	public static function pdo() : PDO {
		if (self::$instance == null)
			self::$instance = new self();

		if (empty(self::$instance->pdo)) {
			self::$instance->pdo = self::$instance->pdo_connect();
		}

		return self::$instance->pdo;
	}

	public static function sql_random_function(): string {
		if (Config::get(Config::DB_TYPE) == "mysql") {
			return "RAND()";
		}
		return "RANDOM()";
	}

	/**
	 * Helper to build a query part comparing a field against a past datetime (determined by "$now - $some_interval")
	 * 
	 * The example below could be read as "last_digest_sent is older than 1 day ago".
	 * ```php
	 * Db::past_comparison_qpart('last_digest_sent', '<', 1, 'day');
	 * ```
	 *
	 * @todo validate value of $unit and fail if invalid (or massage if practical)?
	 * @link https://www.postgresql.org/docs/current/datatype-datetime.html#DATATYPE-INTERVAL-INPUT
	 * @link https://dev.mysql.com/doc/refman/9.2/en/expressions.html#temporal-intervals
	 * @param string $field the table field being checked
	 * @param '<'|'>'|'<='|'>='|'=' $operator the comparison operator
	 * @param positive-int $quantity the amount of $unit
	 * @param 'year'|'month'|'week'|'day'|'hour'|'minute'|'second' $unit the unit of time for $quantity (see links for more info)
	 * @return string the query part string
	 */
	static function past_comparison_qpart(string $field, string $operator, int $quantity, string $unit): string {
		if (Config::get(Config::DB_TYPE) == 'pgsql')
			return "$field $operator NOW() - INTERVAL '$quantity $unit' ";
		return "$field $operator DATE_SUB(NOW(), INTERVAL $quantity $unit) ";
	}
}
