<?php

class Phergie_Plugin_Poker_Deck {
	private $deck = null;

	public function __construct() {
		$this->deck = array();

		foreach (range(0, 51) as $num) {
			array_push($this->deck, new Phergie_Plugin_Poker_Card($num));
		}

		$shuffles = mt_rand(6,8);
		for ($i = 0; $i < $shuffles; $i++) {
			$this->shuffleDeck();
		}
	}

	public function shuffleDeck() {
		mt_srand();
		for ($i = count($this->deck) - 1; $i > 0; $i--) {
			$j = mt_rand(0, $i);
			$tmp = $this->deck[$i];
			$this->deck[$i] = $this->deck[$j];
			$this->deck[$j] = $tmp;
		}
	}

	public function getCards($num, $burn = false) {
		if ($burn) {
			array_shift($this->deck);
		}
		return array_splice($this->deck, 0, $num);
	}
}
