<?php
/**
 * Play some trivia, it's good for you!
 *
 * Trivia questions database were sourced from : http://www.irc-wiki.org/Category:Trivia_questions
 *
 * @category Trivia
 * @package  Phergie_Plugin_Trivia
 * @author   David Walker <dwalker@symplicity.com>
 * @license  GPLv2
 * @link     http://github.com/Symplicity/phergie
 * @link     http://symplicity.com
 */

/**
 * Instructions
 *
 * Enable the plugin in your config file.
 *
 * To build the questions db, run db.php in the Trivia folder.
 *
 * To start a game of trivia, all you need to to is type: trivia
 * Trivia will then ask questions, and await answers.  Answers are given by a line starting with '='
 *
 * Exmaple:
 * Question 1 : Animals
 * What has 4 legs and goes bark?
 * =dog
 * CORRECT
 *
 * At any point, an operator may stop a game of trivia with the command: endtrivia
 *
 * Voting allows players to judge, and remove questions from the DB if they are poor questions.
 */

class Phergie_Plugin_Trivia extends Phergie_Plugin_Abstract {
	const ROUND_NONE = 1;
	const ROUND_ANNOUNCE = 2;
	const ROUND_RUNNING = 3;
	const ROUND_VOTING = 4;
	const ROUND_ENDED = 5;

	private $trivia;
	private $db;
	private $rounds;
	private $question_time;
	private $round_delay;
	private $voting_period;
	private $vote_threashold;
	private $display_answer;

	private function joinQuoted($chr, $in) {
		$out = array();
		if (!is_array($in)) {
			$in = array($in);
		}
		foreach ($in as $k => $v) {
			if ($v != '' || $v === 0) {
				$out[$k] = "'$v'";
			}
		}
		return join($chr, $out);
	}

	public function onLoad() {
		$this->trivia = array();
		$this->db = new PDO('sqlite:' . __DIR__ . '/Trivia/trivia.db');

		$plugins = $this->getPluginHandler();
		$plugins->getPlugin('Command');
		$plugins->getPlugin('UserInfo');

		$this->rounds = $this->config['trivia.rounds'] ?: 10;
		$this->question_time = $this->config['trivia.question_time'] ?: 60;
		$this->round_delay = $this->config['trivia.round_delay'] ?: 5;
		$this->voting_period = $this->config['trivia.voting_period'] ?: 15;
		$this->vote_threashold = $this->config['trivia.vote_threashold'] ?: -5;
		$this->display_answer = $this->config['trivia.display_answer'] ?: false;
	}

	public function onCommandTrivia() {
		$nick = trim($this->getEvent()->getNick());
		$chan = trim($this->getEvent()->getSource());

		if (($this->event->isInChannel() && $this->plugins->userInfo->isOp($nick, $chan))) {
			if (empty($this->trivia[$chan])) {
				$this->trivia[$chan] = array(
					'chan' => $chan,
					'round_status' => ROUND_NONE,
					'announced' => null,
					'round_started' => null,
					'mid_round' => false,
					'round' => 1,
					'scores' => array(),
					'question' => array(),
					'seen_questions' => array(),
					'user_answers' => array(),
				);

				$this->doPrivmsg($chan, "4Welcome to Trivia!");
				$this->doPrivmsg($chan, "4May the odds never be in your favor!");
			}
		}
	}

	public function onCommandStoptrivia() {
		$this->onCommandEndtrivia();
	}

	public function onCommandEndtrivia() {
		$nick = trim($this->getEvent()->getNick());
		$chan = trim($this->getEvent()->getSource());

		if ($this->event->isInChannel() && $this->plugins->userInfo->isOp($nick, $chan)) {
			if (!empty($this->trivia[$chan])) {
				$this->doPrivmsg($chan, "4OP Stopped Trivia, probably because you are all sad losers!");
				unset($this->trivia[$chan]);
			}
		}
	}

	public function onPrivmsg() {
		$msg = $this->plugins->message->getMessage();

		if ($msg[0] == '=') {
			$answer = strtolower(substr($msg, 1));
			$answer = preg_replace('/\s+/', ' ', $answer);
			$this->onAnswer($answer);
		}
	}

	public function onAnswer($user_answer) {
		$chan = trim($this->getEvent()->getSource());
		$nick = trim($this->getEvent()->getNick());

		if (empty($this->trivia[$chan])) {
			return;
		}

		$game =& $this->trivia[$chan];

		if ($game['user_answers'][$nick] >= 3) {
			$this->doPrivmsg($nick, "4$nick, you can only answer 3 times per question.");
			return;
		}

		$game['user_answers'][$nick]++;

		if ($game['round_status'] == ROUND_RUNNING) {
			if ($regex = $game['question']['regex']) {
				if (preg_match('/' . $regex . '/i', $user_answer, $matches)) {
					$this->endRound($game, $nick);
				}
			} else {
				$answer = $game['question']['answer'];

				if (preg_match('/#([^#]+?)#/', $answer, $matches)) {
					$answer = $matches[1];
				}

				if (stripos($user_answer, $answer) !== false) {
					$this->endRound($game, $nick);
				}
			}
		}
	}

	protected function startRound(&$game) {
		$game['round_status'] = ROUND_ANNOUNCE;
		$game['announced'] = time();
		unset($game['question']);

		$where = '';
		if (count($game['seen_questions'])) {
			$where = "where id not in (" . $this->joinQuoted(',', $game['seen_questions']) . ")";
		}

		$get = $this->db->query("SELECT * FROM trivia $where ORDER BY RANDOM() LIMIT 1");
		$game['question'] = $get->fetch(PDO::FETCH_ASSOC);
		$game['seen_questions'][] = $game['question']['id'];

		$this->doPrivmsg($game['chan'], "12Round: 10{$game['round']}");
		$vote = $this->db->query("SELECT sum(vote) as count FROM voting where trivia_id = '{$game['question']['id']}'");
		$vote = $vote->fetch(PDO::FETCH_ASSOC);
		$vote = $vote['count'] ?: "0";
		$this->doPrivmsg($game['chan'], "- 3Category: {$game['question']['category']} (Vote: $vote)");
	}

	protected function announceQuestion(&$game) {
		$game['round_status'] = ROUND_RUNNING;
		$game['round_started'] = time();
		$this->doPrivmsg($game['chan'], "- 3Question: {$game['question']['question']}");
	}

	protected function getScores(&$game) {
		$scores = array();

		if (count($game['scores'])) {
			arsort($game['scores']);
			foreach ($game['scores'] as $nick => $score) {
				$scores[] = '[ ' . $nick . ' - ' . $score . ' ]';
			}
		}

		return $scores;
	}

	protected function getLeaders(&$game) {
		$high_score = 0;
		$leaders = array();

		if (count($game['scores'])) {
			foreach ($game['scores'] as $nick => $score) {
				if ($high_score < $score) {
					$leaders = array();
					$leaders[] = $nick;
					$high_score = $score;
				} elseif ($score == $high_score) {
					$leaders[] = $nick;
				}
			}
		}

		return $leaders;
	}

	protected function endRound(&$game, $winner = null) {
		$game['round_status'] = ROUND_VOTING;
		$game['round_ended'] = time();
		$game['mid_round'] = false;
		$game['user_answers'] = array();
		usleep(700000);

		$this->doPrivmsg($game['chan'], "3Round {$game['round']} has ended.");
		$game['round']++;

		if (is_null($winner)) {
			$this->doPrivmsg($game['chan'], "4Nobody won this round!  You are all losers to me.");
			if ($this->display_answer) {
				$this->doPrivmsg($game['chan'], "7The answer was: {$game['question']['answer']}.");
			}
		} else {
			$this->doPrivmsg($game['chan'], "4$winner won this round!");
			$game['scores'][$winner]++;
		}

		$this->doPrivmsg($game['chan'], "7Current Scores:");

		$scores = $this->getScores($game);

		if (count($scores)) {
			$this->doPrivmsg($game['chan'], "7".join(',', $scores));
		} else {
			$this->doPrivmsg($game['chan'], "7Nobody has scored yet");
		}

		$this->doPrivmsg($game['chan'], "4Voting period on the question is open.");
	}

	public function doVote($vote = 0) {
		$nick = trim($this->getEvent()->getNick());
		$chan = trim($this->getEvent()->getSource());

		if (empty($this->trivia[$chan])) {
			return;
		}

		$game =& $this->trivia[$chan];

		if ($game['round_status'] == ROUND_VOTING) {
			$this->db->query("REPLACE INTO voting (trivia_id, nick, vote) VALUES ('{$game['question']['id']}', '$nick', $vote)");
			$vote = $this->db->query("SELECT sum(vote) as count FROM voting where trivia_id = '{$game['question']['id']}'");
			$vote = $vote->fetch(PDO::FETCH_ASSOC);
			if ($vote <= $this->vote_threashold) {
				$this->db->query("DELETE FROM voting WHERE id = '{$game['question']['id']}'");
			}
		}
	}

	public function onCommandUpvote() {
		$this->doVote(1);
	}

	public function onCommandDownvote() {
		$this->doVote(-1);
	}

	public function onTick() {
		foreach ($this->trivia as &$game) {
			switch ($game['round_status']) {
			case ROUND_NONE:
				$this->startRound($game);
				break;
			case ROUND_ANNOUNCE:
				if (($game['announced'] + $this->round_delay) < time()) {
					$this->announceQuestion($game);
				}
				break;
			case ROUND_RUNNING:
				if (!$game['mid_round'] && ($game['round_started'] + (($this->round_delay + $this->question_time)/2)) < time()) {
					$game['mid_round'] = true;
					$this->doPrivmsg($game['chan'], "13Nobody has answered the question, ".($this->question_time/2)." seconds remain.");
					$this->doPrivmsg($game['chan'], "- 3Question: {$game['question']['question']}");
				}

				if (($game['round_started'] + ($this->round_delay + $this->question_time)) < time()) {
					$this->endRound($game);
				}
				break;
			case ROUND_VOTING:
				if (($game['round_ended'] + $this->voting_period) < time()) {
					$game['round_status'] = ROUND_ENDED;
				}
				break;
			case ROUND_ENDED:
				if ($game['round'] > $this->rounds) {
					$leaders = $this->getLeaders($game);
					if ($num_leaders = count($leaders)) {
						$winner_txt = $num_leaders > 1 ? 'winners' : 'winner';
						$isare = $num_leaders > 1 ? 'are' : 'is';
						$this->doPrivmsg($game['chan'], "4Game Over!  Your $winner_txt $isare: " . join(', ', $leaders));
					} else {
						$this->doPrivmsg($game['chan'], "4Game Over!  Y'all are a sack of losers");
					}

					$scores = $this->getScores($game);
					if (count($scores)) {
						$this->doPrivmsg($game['chan'], "7Final Scores:");
						$this->doPrivmsg($game['chan'], "7".join(',', $scores));
					}

					unset($this->trivia[$game['chan']]);
				} else {
					if (($game['round_ended'] + $this->round_delay) < time()) {
						$game['round_status'] = ROUND_NONE;
					}
				}
				break;
			}
		}
	}
}
