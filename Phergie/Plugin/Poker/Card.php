<?php

class Phergie_Plugin_Poker_Card {
	private $suits_short = array('♡', '♤', '♢', '♧');
	private $suits = array('hearts', 'spades', 'diamonds', 'clubs');
	private $shortnames = array('A', '2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K');
	private $values = array('14', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12', '13');

	public $suit_short = '';
	public $suit = '';
	public $shortname = '';
	public $value = -1;

	public function __construct($num) {
		$iSuit = floor($num / 13);
		$iName = $num % 13;

		$this->suit_short = $this->suits_short[$iSuit];
		$this->suit = $this->suits[$iSuit];
		$this->shortname = $this->shortnames[$iName];
		$this->value = $this->values[$iName];
	}

	public function __get($name) {
		return $this->name;
	}
	public function __toString() {
		return $this->suit_short . ' ' . $this->shortname;
	}
}
