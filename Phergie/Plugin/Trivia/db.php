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

$dbfile = './trivia.db';
if (file_exists($dbfile)) {
	unlink($dbfile);
}

$db = new PDO('sqlite:' . $dbfile);
$db->exec('DROP TABLE trivia');
$db->exec('CREATE TABLE trivia (id INTEGER PRIMARY KEY AUTOINCREMENT, category VARCHAR(255), question VARCHAR(255), answer VARCHAR(255), regex VARCHAR(255))');
$db->exec('CREATE TABLE voting (trivia_id INTEGER, nick VARCHAR(255), vote INTEGER, PRIMARY KEY (trivia_id, nick))');

$insert = $db->prepare('INSERT INTO trivia (id, category, question, answer, regex) VALUES (NULL, :category, :question, :answer, :regex)');

$question_files = array(
	'questions.txt',
);

$db->beginTransaction();

foreach ($question_files as $qf) {
	$fh = fopen($qf, 'r');

	$records = array();
	$counter = 0;
	do {
		$line = trim(fgets($fh));

		if ($line) {
			$records[$counter][] = $line;
		} else {
			$counter++;
		}
	} while (!feof($fh));

	foreach ($records as $record) {
		$row = array();

		foreach ($record as $r) {
			$a = explode(':', $r);
			$key = array_shift($a);
			$info = implode(':', $a);
			$row[strtolower(trim($key))] = trim($info);
		}

		$insert->execute(array($row['category'], $row['question'], $row['answer'], $row['regexp']));
	}

	fclose($fh);
}
$db->commit();
