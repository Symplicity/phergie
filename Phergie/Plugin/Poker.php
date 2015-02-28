<?php
/**
 * Play some no limit hold-em, you know you want to.
 *
 * @category Poker
 * @package  Phergie_Plugin_Poker
 * @author   David Walker <dwalker@symplicity.com>
 * @license  GPLv2
 * @link     http://github.com/Symplicity/phergie
 * @link     http://symplicity.com
 */

define('GAME_STATE_ANNOUNCE', 1);		// A new game has begun, announce to all the game is underway.  This state allows people to join the game
define('GAME_STATE_BLINDS', 2);			// Game has commenced, no more joining, going to take blinds from people.
define('GAME_STATE_NEXT_ACTION', 3);	// Each player has 10 seconds (or fold) to check/call/raise/fold
define('GAME_STATE_BIDDING', 4);		// Each player has 10 seconds (or fold) to check/call/raise/fold
define('GAME_STATE_NEXT_CARD', 5);		// Distribute flop/turn/river
define('GAME_STATE_FINISH_DEALING', 6);	// Finish dealing cards
define('GAME_STATE_SCORE', 7);			// Of remaining players, determine who wins.
define('GAME_STATE_SHOW', 8);			// Allow players to show cards
define('GAME_STATE_END', 9);			// Clear game from memory, write player data

class Phergie_Plugin_Poker extends Phergie_Plugin_Abstract {
	private $games = array();

	private $allowed_channels = array(
		'#poker',
	);

	/*
	 * Init Plugin
	 */
	public function onLoad() {
		if (!extension_loaded('PDO') || !extension_loaded('pdo_sqlite')) {
			$this->fail('PDO and pdo_sqlite extensions must be installed');
		}

		$this->getPluginHandler()->getPlugin('Command');
	}


	protected function getGame($chan) {
		if (isset($this->games[$chan])) {
			return $this->games[$chan];
		}
		return null;
	}


	/*
	 * Game Commands
	 */
	public function onCommandPoker() {
		$chan = trim($this->getEvent()->getSource());

		if (!in_array($chan, $this->allowed_channels)) {
			return;
		}

		if (isset($this->games[$chan]) && is_object($this->games[$chan])) {
			return;
		}

		$this->games[$chan] = new Phergie_Plugin_Poker_Game($chan, $this);

		$this->doPrivmsg($chan, "A new game of Poker is starting, type `join` to participate in this game.");
	}

	public function onCommandJoin() {
		$chan = trim($this->getEvent()->getSource());
		$game = $this->getGame($chan);

		if (!is_object($game)) {
			return;
		}

		$nick = trim($this->getEvent()->getNick());
		$username = trim($this->getEvent()->getUsername());

		if ($game['state'] != GAME_STATE_ANNOUNCE) {
			$this->doPrivmsg($nick, 'You may not join a game in progress');
			return;
		}

		if (isset($game['players'][$nick])) {
			$this->doPrivmsg($nick, 'You have already joined this game');
			return;
		}

		if (count($game['players']) >= 10) {
			$this->doPrivmsg($nick, 'The game is at max capacity, try again next round');
			return;
		}

		try {
			$player = new Phergie_Plugin_Poker_Player(array(
				'nick' => $nick,
				'username' => $username,
				'load' => true,
			));
		} catch (Exception $e) {
			$this->doPrivmsg($nick, $e->getMessage());
			return;
		}

		if ($player['credits'] <= 0) {
			$this->doPrivmsg($nick, "You do not have any credits remaining");
			$player->unloadPlayer();
			return;
		}

		$player['hand'] = $game['deck']->getCards(2);

		$game['players'][$nick] = $player;
		$game['round_bidding'][$nick] = 0;

		$hand = $game->getCardsString($player['hand']);

		$this->doPrivmsg($nick, "Welcome to Poker $nick, you have {$player['credits']}Cr.  ");
		$this->doPrivmsg($nick, "Your hand for the current game is: $hand");
	}

	public function onCommandCheck() {
		$chan = trim($this->getEvent()->getSource());
		$game = $this->getGame($chan);

		if (!is_object($game)) {
			return;
		}

		$player = trim($this->getEvent()->getNick());

		if (!$game->toAct($player)) {
			return;
		}

		if (count($game['round_bidding'])) {
			$current_bid = max($game['round_bidding']);
			$player_bid = $game['round_bidding'][$player];
		} else {
			$current_bid = $player_bid = 0;
		}
		$to_call = $current_bid - $player_bid;

		if ($to_call > 0) {
			return;
		}

		if (empty($game['last_act'])) {
			$game['last_act'] = $player;
		}

		$this->doPrivmsg($chan, "$player has checked");
		$game->nextPlayerToAct();
	}

	public function onCommandCall() {
		$chan = trim($this->getEvent()->getSource());
		$game = $this->getGame($chan);

		if (!is_object($game)) {
			return;
		}

		$player = trim($this->getEvent()->getNick());

		if (!$game->toAct($player)) {
			return;
		}

		if (count($game['round_bidding'])) {
			$current_bid = max($game['round_bidding']);
			$player_bid = $game['round_bidding'][$player];
		} else {
			$current_bid = $player_bid = 0;
		}
		$to_call = $current_bid - $player_bid;

		if ($to_call == 0) {
			return;
		} elseif ($to_call > $game['players'][$player]['credits']) {
			$this->doPrivmsg($chan, "$player can not make this call, you must go allin");
			return;
		}

		if (empty($game['last_act'])) {
			$game['last_act'] = $player;
		}

		$game['players'][$player]['credits'] -= $to_call;
		$game['round_bidding'][$player] = (isset($game['round_bidding'][$player]) ? $game['round_bidding'][$player] : 0) + $to_call;

		$this->doPrivmsg($chan, "$player has called");
		$game->nextPlayerToAct();
	}

	public function onCommandRaise($raise) {
		$chan = trim($this->getEvent()->getSource());
		$game = $this->getGame($chan);

		if (!is_object($game)) {
			return;
		}

		$player = trim($this->getEvent()->getNick());

		if ($raise <= 0 || !is_numeric($raise)) {
			return;
		}

		if (!$game->toAct($player)) {
			return;
		}

		if ($raise % $game->blinds['Big'] !== 0) {
			$this->doPrivmsg($chan, "$player, you need to increase bidding by a multiple of the Big Blind: {$game->blinds['Big']}");
			return;
		}

		if ($raise < $game['last_raise']) {
			$this->doPrivmsg($chan, "$player, you must raise more than the previous raise of {$game['last_raise']}");
			return;
		}

		$current_bid = count($game['round_bidding']) ? max($game['round_bidding']) : 0;
		$new_bid = $raise + $current_bid;

		if ($new_bid >= $game['players'][$player]['credits']) {
			$this->doPrivmsg($chan, "$player you do not have enugh credits for this bid and will need to go allin");
			return;
		}

		$game['last_act'] = $player;
		$game['last_raise'] = $raise;

		$player_up = $new_bid - $game['round_bidding'][$player];

		$game['players'][$player]['credits'] -= $player_up;
		$game['round_bidding'][$player] = (isset($game['round_bidding'][$player]) ? $game['round_bidding'][$player] : 0) + $player_up;

		$this->doPrivmsg($chan, "$player has raised $raise to {$game['round_bidding'][$player]}");
		$game->nextPlayerToAct();
	}

	public function onCommandAllin() {
		$chan = trim($this->getEvent()->getSource());
		$game = $this->getGame($chan);

		if (!is_object($game)) {
			return;
		}

		$player = trim($this->getEvent()->getNick());

		if (!$game->toAct($player)) {
			return;
		}

		$max = $game['players'][$player]['credits'];
		$game['players'][$player]['credits'] -= $max;
		$game['round_bidding'][$player] = (isset($game['round_bidding'][$player]) ? $game['round_bidding'][$player] : 0) + $max;

		$game['inactive'][] = $player;
		$game['allin'][$player] = $game['round_bidding'][$player];

		$game['last_act'] = '';

		$this->doPrivmsg($chan, "$player has gone all-in");
		$game->nextPlayerToAct();
	}

	public function onCommandShow() {
		$chan = trim($this->getEvent()->getSource());
		$game = $this->getGame($chan);

		if (!is_object($game)) {
			return;
		}

		$player = trim($this->getEvent()->getNick());

		if ($game['state'] != GAME_STATE_SHOW) {
			return false;
		}

		$hand = $game['players'][$player]->getHand();
		$this->doPrivmsg($chan, "$player was holding: $hand");
	}

	public function onCommandFold() {
		$chan = trim($this->getEvent()->getSource());
		$game = $this->getGame($chan);

		if (!is_object($game)) {
			return;
		}

		$player = trim($this->getEvent()->getNick());

		if (!$game->toAct($player)) {
			return;
		}

		$game->foldPlayer($player);
	}

	public function onCommandCards() {
		$chan = trim($this->getEvent()->getSource());
		$game = $this->getGame($chan);
		$nick = trim($this->getEvent()->getNick());

		if (!is_object($game)) {
			return;
		}

		if (isset($game['players'][$nick])) {
			$hand = $game->getCardsString($game['players'][$nick]['hand']);

			if (is_array($hand)) {
				$this->doPrivmsg($nick, "Your hand for the current game is: $hand");
			}
		}
	}

	public function onCommandCredits() {
		$chan = trim($this->getEvent()->getSource());
		$nick = trim($this->getEvent()->getNick());
		$username = trim($this->getEvent()->getUsername());

		try {
			$player = new Phergie_Plugin_Poker_Player(array(
				'username' => $username,
				'load' => false,
				'create' => false,
			));
		} catch (Exception $e) {
			$this->doPrivmsg($nick, $e->getMessage());
			return;
		}

		//if ($player['last_issued'] < strtotime('-1 week')) 
		if ($player['credits'] < 50) {
			$player['credits'] += 250;
			$player['last_issued'] = time();
			$this->doPrivmsg($nick, "You have been issued 200 new credits");
			$player->writePlayerData();
		} else {
			$this->doPrivmsg($nick, "You have had credits issued within the past week");
		}
	}

	public function onCommandGift($gift_nick, $credits) {
		$chan = trim($this->getEvent()->getSource());
		$nick = trim($this->getEvent()->getNick());
		$username = trim($this->getEvent()->getUsername());

		if ($credits <= 0 || !is_numeric($credits)) {
			return;
		}

		if ($nick == $gift_nick) {
			$this->doPrivmsg($nick, "You can not gift yourself credits");
			return;
		}

		try {
			$player = new Phergie_Plugin_Poker_Player(array(
				'username' => $username,
				'load' => false,
			));
		} catch (Exception $e) {
			$this->doPrivmsg($nick, "You don't seem to have ever played in a game of Poker, so you have no credits");
			return;
		}

		try {
			$gift_player = new Phergie_Plugin_Poker_Player(array(
				'nick' => $gift_nick,
				'load' => false,
			));
		} catch (Exception $e) {
			$this->doPrivmsg($nick, "It doesn't seem your target has played in Poker, and thus has no account");
			return;
		}

		if ($player['credits'] - $credits < 0) {
			$this->doPrivmsg($nick, "You do not have the available credits to gift");
			return;
		}

		$player['credits'] -= $credits;
		$gift_player['credits'] += $credits;

		$player->writePlayerData();
		$gift_player->writePlayerData();

		$this->doPrivmsg($chan, "$nick has gifted {$credits}Cr to $gift_nick");
	}

	public function onCommandInfo($info_nick = null) {
		$chan = trim($this->getEvent()->getSource());
		$nick = trim($this->getEvent()->getNick());

		if (is_null($info_nick)) {
			$info_nick = $nick;
		}

		try {
			$player = new Phergie_Plugin_Poker_Player(array(
				'nick' => $info_nick,
				'load' => false,
			));
		} catch (Exception $e) {
			$this->doPrivmsg($nick, $e->getMessage());
			return;
		}

		$this->doPrivmsg($nick, sprintf("%10s|%5dcr|Games: %3d|Won: %3d", $player['nick'], $player['credits'], $player['games'], $player['wins']));
	}

	public function onCommandHelp() {
		$nick = trim($this->getEvent()->getNick());

		$cmds = array(
			'poker' => 'Start a new game of poker in this channel',
			'join' => 'Join a game of poker',
			'raise <credits>' => 'Raise the pot <credits> credits',
			'call' => 'Call to the current bid',
			'check' => 'Check when there is no current bid',
			'allin' => 'Raise all your credits',
			'fold' => 'Surrender your hand',
			'cards' => 'Private message your cards to you again',
			'show' => 'Show cards after a game ends due to folding',
			'gift <nick> <credits>' => 'Gift <credits> to <nick>',
			'credits' => 'Acquire new credits, can be issued once a week',
		);

		foreach ($cmds as $cmd => $info) {
			$this->doPrivmsg($nick, "$cmd: $info");
		}
	}


	/*
	 * Game Loop
	 */
	public function onTick() {
		foreach ($this->games as $chan => $game) {
			switch ($game['state']) {
				case GAME_STATE_ANNOUNCE:
					// Wait 10 seconds for people to join
					if ($game['last_act_time'] + 20 <= time()) {
						if (count($game['players']) <= 1) {
							$this->doPrivmsg($chan, "Get some friends.  You can't play with yourself.");
							$this->endGame($chan);
							return;
						} else {
							$game->announceGame();

							$game['state'] = GAME_STATE_BLINDS;
							$game['last_act_time'] = time();
						}
					}
					break;
				case GAME_STATE_BLINDS:
					// Wait 5 seconds after announcing to take blinds
					if ($game['last_act_time'] + 5 <= time()) {
						$game->takeBlinds();
						$game->nextPlayerToAct();

						$game['state'] = GAME_STATE_NEXT_ACTION;
						$game['last_act_time'] = time();
					}
					break;
				case GAME_STATE_NEXT_ACTION:
					$game['last_act_warn'] = false;
					if ($game['last_act_time'] + 3 <= time()) {
						$game->announceNextAction();

						$game['state'] = GAME_STATE_BIDDING;
						$game['last_act_time'] = time();
					}
					break;
				case GAME_STATE_BIDDING:
					if ($game['last_act_time'] + 30 <= time()) {
						if ($game['to_act']) {
							$game->foldPlayer($game['to_act']);
						}
					} elseif (!$game['last_act_warn'] && $game['last_act_time'] + 15 < time()) {
						$game['last_act_warn'] = true;
						$this->doPrivmsg($chan, "{$game['to_act']}: you must act in 15 seconds, or will be folded.");
					}
					break;
				case GAME_STATE_NEXT_CARD:
					$game->settleBidding();
					if (!$game->getNextGameCard()) {
						$game['state'] = GAME_STATE_SCORE;
					} else {
						$game['state'] = GAME_STATE_NEXT_ACTION;
					}
					$game['last_act_time'] = time();
					break;
				case GAME_STATE_FINISH_DEALING:
					if ($game['last_act_time'] + 3 <= time()) {
						if (!$game->getNextGameCard()) {
							$game['state'] = GAME_STATE_SCORE;
						}
						$game['last_act_time'] = time();
					}
					break;
				case GAME_STATE_SCORE:
					$game->scoreGame();
					$game['state'] = GAME_STATE_END;
					$game['last_act_time'] = time();
					break;
				case GAME_STATE_SHOW:
					if ($game['last_act_time'] + 20 <= time()) {
						$game['state'] = GAME_STATE_END;
					}
					break;
				case GAME_STATE_END:
					$this->endGame($chan);
					break;
			}
		}
	}

	public function endGame($chan) {
		$game = $this->getGame($chan);

		if (!is_object($game)) {
			return;
		}

		foreach ($game['players'] as $player) {
			$player['games'] += 1;
			$player->writePlayerData();
		}

		$this->doPrivmsg($chan, "Game Over. Don't be a fool, stay in school!");
		unset($this->games[$chan]);
	}
}
