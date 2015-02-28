<?php

class Phergie_Plugin_Poker_Player implements ArrayAccess {
	// Statically Track -all- loaded players, not just this instance
	protected static $loaded;

	/**
	 * Local database for storage
	 *
	 * @var PDO
	 */
	protected static $database;

	/**
	 * This instances data
	 */
	protected $data;

	public function __construct(array $args = array()) {
		if (!isset(static::$database)) {
			static::$database = new PDO('sqlite:' . dirname(__FILE__) . '/player.db');
			$this->buildDatabase();
		}

		array_walk($args, function(&$in) {
			if (is_string($in)) {
				$in = strtolower($in);
			}
		});

		if (isset($args['username'])) {
			if (!isset($args['load'])) {
				$args['load'] = true;
			}

			if (isset(static::$loaded[$args['username']])) {
				throw new Exception("You are already playing a game on this server.");
			}
		}

		$this->loadPlayerData($args);
	}

	/**
	 * Build the database structure
	 *
	 * @return void
	 */
	protected function buildDatabase() {
		// Check to see if the functions table exists
		$checkstmt = static::$database->query("SELECT COUNT(*) FROM `sqlite_master` WHERE `name` = 'player'");
		$result = $checkstmt->fetch(PDO::FETCH_ASSOC);
		$table = $result['COUNT(*)'];

		if (!$table) {
			static::$database->exec('CREATE TABLE `player` (
				`nick` VARCHAR(255),
				`username` VARCHAR(255),
				`credits` INTEGER,
				`games` INTEGER,
				`wins` INTEGER,
				`last_issued` INTEGER
			)');
			static::$database->exec(
				'CREATE UNIQUE INDEX `username_idx` ON `player` (`username`)'
			);
			static::$database->exec(
				'CREATE UNIQUE INDEX `nick_idx` ON `player` (`nick`)'
			);
		}
	}

	private function loadPlayerData($args) {
		if (isset($args['username'])) {
			$stmt = static::$database->query("select * from `player` where `username` = '" . $args['username'] . "'");
		} elseif (isset($args['nick'])) {
			$stmt = static::$database->query("select * from `player` where `nick` = '" . $args['nick'] . "'");
		} else {
			throw new Exception("Need to define a username or nick to lookup");
		}

		$data = $stmt->fetch(PDO::FETCH_ASSOC);
		if ($data) {
			$this->data = $data;
		} else {
			if (isset($args['load']) && $args['load']) {
				if (empty($args['nick']) || empty($args['username'])) {
					throw new Exception("To create a new user a nick and username are required");
				}

				$this->data = array(
					'nick' => $args['nick'],
					'username' => $args['username'],
					'credits' => 250,
					'games' => 0,
					'wins' => 0,
					'last_issued' => time(),
				);
				$this->writePlayerData(false);
			} else {
				throw new Exception("Could not find the user");
			}
		}

		if (isset($args['username']) && $args['load']) {
			static::$loaded[$args['username']] = $this;
		}
	}

	public function writePlayerData($unload = true) {
		$stmt = static::$database->prepare('replace into `player` (`nick`, `username`, `credits`, `games`, `wins`, `last_issued`) VALUES (:nick, :username, :credits, :games, :wins, :last_issued)');

		$stmt->bindParam(':nick', $v=(string)$this['nick'], PDO::PARAM_STR);
		$stmt->bindParam(':username', $v=(string)$this['username'], PDO::PARAM_STR);
		$stmt->bindParam(':credits', $v=(int)$this['credits'], PDO::PARAM_INT);
		$stmt->bindParam(':games', $v=(int)$this['games'], PDO::PARAM_INT);
		$stmt->bindParam(':wins', $v=(int)$this['wins'], PDO::PARAM_INT);
		$stmt->bindParam(':last_issued', $v=(int)$this['last_issued'], PDO::PARAM_INT);

		$stmt->execute();
		if ($unload) {
			$this->unloadPlayer();
		}
	}

	public function unloadPlayer() {
		unset(static::$loaded[$this['username']]);
	}

	public function issueNewCredits() {
		if ($this->data['last_issued'] < strtotime('-1 week')) {
			$this->data['last_issued'] = time();
			$this->writePlayerData();
		}
	}

	public function getHand() {
		$hand = '';
		foreach ($this['hand'] as $card) {
			$hand .= '[' . $card->shortname . $card->suit_short . ']';
		}
		return $hand;
	}


	/**
	 * ArrayAccess
	 */
	public function offsetExists($offset) {
		return isset($this->data[$offset]);
	}

	public function offsetGet($offset) {
		if (isset($this->data[$offset])) {
			return $this->data[$offset];
		}
	}

	public function offsetSet($offset, $value) {
		$this->data[$offset] = $value;
	}

	public function offsetUnset($offset) {
		unset($this->data[$offset]);
	}
}
