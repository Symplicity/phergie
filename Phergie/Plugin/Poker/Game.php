<?php

class Phergie_Plugin_Poker_Game implements ArrayAccess {
	private $plugin;
	private $game_data;

	public $blinds = array(
		'Little' => '5',
		'Big' => '10',
	);

	private $game_cards = array(
		array(
			'The Flop',
			3,
		),
		array(
			'The Turn',
			1,
		),
		array(
			'The River',
			1,
		),
	);

	public function __construct($chan, Phergie_Plugin_Abstract $plugin) {
		$this->plugin = $plugin;
		$this->game_data = array(
			'chan' => $chan,
			'state' => GAME_STATE_ANNOUNCE,

			'deck' => new Phergie_Plugin_Poker_Deck(),
			'public_cards' => array(),

			'to_act' => '',
			'last_act' => '',
			'last_act_warn' => false,
			'last_act_time' => time(),
			'last_raise' => 0,

			'players' => array(),

			'inactive' => array(
				/**
				 * player
				 */
			),

			'folded' => array(
				/**
				 * player
				 */
			),

			'allin' => array(
				/**
				 * player
				 */
			),

			'round_bidding' => array(
				/**
				 * player => bid
				 */
			),

			'pot' => 1,
			'pots' => array(
				1 => array(
					'total' => 0,
					'players' => array(),
				),
			),

			'next_card' => 0,
		);
	}

	public function getCardsString($cards) {
		$string = '';
		foreach ($cards as $card) {
			$string .= '[' . $card->shortname . $card->suit_short . ']';
		}
		return $string;
	}

	/**
	 * Action Handlers
	 */
	protected function nextPlayer() {
		list($player, $data) = each($this['players']);

		if (current($this['players']) === false) {
			reset($this['players']);
		}

		if ($player == $this['to_act']) {
			return false;
		}

		if (in_array($player, $this['inactive'])) {
			return $this->nextPlayer();
		}

		return $player;
	}

	public function nextPlayerToAct() {
		$player = $this->nextPlayer();

		// There's only one active player, we can finsh the game
		if ($player === false) {
			$this['last_act'] = '';
			$this['state'] = GAME_STATE_FINISH_DEALING;
			$this->settleBidding();
			return;
		}

		if (!empty($this['last_act']) && ($this['last_act'] == $player)) {
			$this['last_act'] = '';
			$this['state'] = GAME_STATE_NEXT_CARD;
		} else {
			$this['state'] = GAME_STATE_NEXT_ACTION;
		}

		$this['last_act_time'] = time();
		$this['to_act'] = $player;
	}

	public function toAct($player = null) {
		if ($this['state'] != GAME_STATE_BIDDING) {
			return false;
		}

		if ($this['to_act'] == $player) {
			return true;
		}

		return false;
	}

	public function settleBidding() {
		asort($this['allin']);

		// The bid stored in the allin array is for sorting purposes only.
		// Since we deduct from the round_bidding, we need to pull from
		// round_bidding if there is more than one allin bid this round.
		foreach ($this['allin'] as $allin_player => $sorting_big) {
			$allin_bid = $this['round_bidding'][$allin_player];

			if ($allin_bid > 0) {
				$pot =& $this['pots'][$this['pot']++];

				foreach ($this['round_bidding'] as $player => &$bid) {
					var_dump("Allin: $player is in this round for $bid, subtracting $allin_bid");
					if ($bid > 0) {
						$bid -= $allin_bid;

						$pot['total'] = (isset($pot['total']) ? $pot['total'] : 0) + $allin_bid;
						$pot['players'][$player] = (isset($pot['players'][$player]) ? $pot['players'][$player] : 0) + $allin_bid;
					}
				}
			}

			unset($this['allin'][$allin_player]);
			unset($this['round_bidding'][$allin_player]);
		}

		if (count($this['round_bidding'])) {
			$pot =& $this['pots'][$this['pot']];
			foreach ($this['round_bidding'] as $player => $bid) {
				if ($bid > 0) {
					$pot['total'] = (isset($pot['total']) ? $pot['total'] : 0) + $bid;
					$pot['players'][$player] = (isset($pot['players'][$player]) ? $pot['players'][$player] : 0) + $bid;
				}
			}
		}

		$this['round_bidding'] = array();
	}

	public function takeBlinds() {
		$player = null;
		foreach ($this->blinds as $blind => $amount) {
			$player = $this->nextPlayer();

			$this['players'][$player]['credits'] -= $amount;
			$this['players'][$player]['round_bet'] = $amount;

			$this['round_bidding'][$player] = $amount;

			$this->plugin->doPrivmsg($this['chan'], "$blind Blind ({$amount}cr): $player");
		}
	}

	public function foldPlayer($player) {
		$this['inactive'][] = $player;
		$this['folded'][] = $player;

		$this->plugin->doPrivmsg($this['chan'], "$player has folded");

		if (count($winner = array_diff(array_keys($this['players']), $this['folded'])) == 1) {
			$winner = array_shift($winner);
			$this['players'][$winner]['score'] = 1;

			$this->settleBidding();
			$this->scoreGame(false);

			$this['state'] = GAME_STATE_SHOW;
			$this['last_act_time'] = time();
		} else {
			$this->nextPlayerToAct();
		}
	}

	public function announceGame() {
		$this->plugin->doPrivmsg($this['chan'], 'Welcome to D2 Poker, Dii tibi Benedictiones.');
		$this->plugin->doPrivmsg($this['chan'], 'This round has the following players:');

		$keys = array_keys($this['players']);
		shuffle($keys);
		$new = array();
		foreach ($keys as $k) {
			$new[$k] = $this['players'][$k];
		}
		$this['players'] = $new;

		foreach ($this['players'] as $data) {
			$this->plugin->doPrivmsg($this['chan'], sprintf("%10s|%5dcr|Games: %3d|Won: %3d", $data['nick'], $data['credits'], $data['games'], $data['wins']));
		}

		reset($this['players']);
	}

	public function announceNextAction() {
		$to_act = $this['to_act'];
		$current_bid = count($this['round_bidding']) ? max($this['round_bidding']) : 0;
		$player_bid = isset($this['round_bidding'][$to_act]) ? $this['round_bidding'][$to_act] : 0;
		$to_call = $current_bid - $player_bid;

		$start = "{$current_bid}Cr is to {$to_act} (In: {$player_bid}Cr | Has: {$this['players'][$to_act]['credits']}): ";
		if ($to_call == 0) {
			$this->plugin->doPrivmsg($this['chan'], $start . "fold | check | raise | allin");
		} elseif ($to_call >= $this['players'][$to_act]['credits']) {
			$this->plugin->doPrivmsg($this['chan'], $start . "fold | allin");
		} else {
			$this->plugin->doPrivmsg($this['chan'], $start . "fold | call ({$to_call}) | raise | allin");
		}

		$this['last_act_time'] = time();
	}

	public function getNextGameCard() {
		if ($this['next_card'] > 2) {
			return false;
		}

		list($act, $num) = $this->game_cards[$this['next_card']++];
		$cards = $this['deck']->getCards($num, true);
		$this['public_cards'] = array_merge($this['public_cards'], $cards);
		$this['last_raise'] = 0;

		$active_players = array_diff(array_keys($this['players']), $this['inactive']);
		$this->plugin->doPrivmsg($this['chan'], "Active Players: " . join(', ', $active_players));

		foreach ($this['pots'] as $pot_num => $pot) {
			if (is_null($pot)) {
				continue;
			}
			$this->plugin->doPrivmsg($this['chan'], "Pot {$pot_num} has {$pot['total']}Cr");
		}

		$cards_str = $this->getCardsString($cards);
		$public_str = $this->getCardsString($this['public_cards']);

		$this->plugin->doPrivmsg($this['chan'], "On {$act} we have {$cards_str}");
		$this->plugin->doPrivmsg($this['chan'], "Public Cards are {$public_str}");

		// Need to reset player to act
		reset($this['players']);
		$this->nextPlayerToAct();

		return true;
	}

	public function scoreGame($evaluate = true) {
		$scores = array();

		foreach ($this['players'] as $player => &$data) {
			if (in_array($player, $this['folded'])) {
				$scores[$player] = -1;
				$data['score'] = -1;
				continue;
			}

			if ($evaluate) {
				$combined_hand = array_merge($data['hand'], $this['public_cards']);
				$scores[$player] = Phergie_Plugin_Poker_Evaluate::score($combined_hand);
				$data['score'] = $scores[$player];

				$hand = $this['players'][$player]->getHand();
				$this->plugin->doPrivmsg($this['chan'], "$player was holding: $hand");
			} else {
				$scores[$player] = $data['score'];
			}
		}

		$winners = array_keys($scores, max($scores));
		$winners_str = join(',', $winners);

		foreach ($winners as $winner) {
			$this['players'][$winner]['wins'] += 1;
		}

		$this->distributePots($winners);

		if ($evaluate) {
			if (($num_winners = count($winners)) > 1) {
				$this->plugin->doPrivmsg($this['chan'], "There was a tie with $num_winners hands of " . Phergie_Plugin_Poker_Evaluate::readable_hand($scores[$winners[0]]) . ".  By $winners_str");
			} else {
				$this->plugin->doPrivmsg($this['chan'], "Your winner is $winners_str with a hand of " . Phergie_Plugin_Poker_Evaluate::readable_hand($scores[$winners[0]]));
			}
		} else {
			$this->plugin->doPrivmsg($this['chan'], "You may `show` your hand now");
		}
	}

	public function distributePots($winners) {
		foreach ($this['pots'] as $pot_num => $pot) {
			if (is_null($pot)) {
				continue;
			}

			$pot_winners = array_intersect($winners, array_keys($pot['players']));
			$num_winners = count($pot_winners);

			if ($num_winners == 0) {
				$scores = array();
				foreach ($this['players'] as $player => $data) {
					if (array_key_exists($player, $pot['players'])) {
						$scores[$player] = $data['score'];
					}
				}

				$pot_winners = array_keys($scores, max($scores));
			}

			$each = $pot['total'] / count($pot_winners);
			$winners_str = join(', ', $pot_winners);

			if (count($pot_winners) > 1) {
				$phrase = "winners are";
			} else {
				$phrase = "winner is";
			}

			$this->plugin->doPrivmsg($this['chan'], "Pot {$pot_num} {$phrase} {$winners_str} earning {$each}Cr");
			foreach ($pot_winners as $winner) {
				$this['players'][$winner]['credits'] += $each;
			}
		}
	}


	/**
	 * ArrayAccess
	 */

	public function offsetExists($offset) {
		return isset($this->game_data[$offset]);
	}

	public function &offsetGet($offset) {
		if (isset($this->game_data[$offset])) {
			return $this->game_data[$offset];
		} else {
			var_dump('attempting to get ' . $offset);
		}
	}

	public function offsetSet($offset, $value) {
		$this->game_data[$offset] = $value;
	}

	public function offsetUnset($offset) {
		unset($this->game_data[$offset]);
	}
}
