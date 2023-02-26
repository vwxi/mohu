<?php

/* mohu bbs, by ten */

define('VERSION', '1.0.0');

define('DBFILENAME', 'mohu.db');
define('DBPOSTTABLE', 'posts');
define('DBBANSTABLE', 'bans');

define('BBSNAME', 'mohu BBS');
define('BBSTITLE', "ephie-tan's fan club");

define('PREVIEW', 10);
define('MAXPOSTS', 5);
define('MAXREPLIES', 3);

try {
	$db = new SQLite3(DBFILENAME);
} catch(Exception $e) {
	die("caught exception ".$e->getMessage());
}

$q = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='".DBPOSTTABLE."'");
if(!$q->fetchArray()) {
	$db->exec("CREATE TABLE ".DBPOSTTABLE." (
		id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
		parent INTEGER,
		time DATETIME DEFAULT CURRENT_TIMESTAMP,
		ip TEXT,
		name TEXT,
		email TEXT,
		subject TEXT,
		content TEXT,
		replies INTEGER DEFAULT 0,
		frozen INTEGER DEFAULT 0
		)");

	echo "made post table\n";
}

$q = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='".DBBANSTABLE."'");
if(!$q->fetchArray()) {
	$db->exec("CREATE TABLE ".DBBANSTABLE." (
		ip TEXT NOT NULL,
		why TEXT
		)");

	echo "made ban table\n";
}

$st = $db->prepare("SELECT * FROM ".DBBANSTABLE." WHERE ip=:ip");
$st->bindValue(":ip", $_SERVER['REMOTE_ADDR']);
$q = $st->execute();
if($res = $q->fetchArray()) {
	echo "you've been banned from ".BBSTITLE.". reason: ".$res["why"];
	die(0);
}

function say($m) {
	echo "<h3>".$m."</h3><a href=".basename(__FILE__).">let's go back</a>";
	die(0);
}

function subquotes($s) {
	$matches = array();
	preg_match_all("/&gt;&gt;[0-9]{1,}/", $s, $matches);
	foreach($matches[0] as $m) {
		$num = substr($m, 8);
		$nstr = "<a href='".basename(__FILE__)."?do=view&id=".$num."'>".$m."</a>";
		$s = str_replace($m, $nstr, $s);
	}

	return $s;
}

function removeget($url, $varname) {
    list($urlpart, $qspart) = array_pad(explode('?', $url), 2, '');
    parse_str($qspart, $qsvars);
    unset($qsvars[$varname]);
    $newqs = http_build_query($qsvars);
    return $urlpart . '?' . $newqs;
}

// get funcs

function view($id, $preview = false) {
	global $db;
	$st = $db->prepare("SELECT * FROM ".DBPOSTTABLE." WHERE id=:id");
	$st->bindValue(':id', $id, SQLITE3_INTEGER);
	$q = $st->execute();

	if(!$q->fetchArray()) {
		say("post not found");
	}

	$q->reset();
	list($id_,$parent,$time,,$name,$email,$subject,$content,,$frozen) = $q->fetchArray();

	$rn = ($email !== '') ? "<a href='mailto:".$email."'>".$name."</a>" : $name;
	$content = nl2br(htmlentities($content, ENT_QUOTES, 'UTF-8'));
		
	echo "
<div class='postview'>
<p><a title='quote this post' href='";

	echo removeget(removeget($_SERVER["REQUEST_URI"],"quote"),"rquote").(($preview)?"?":"&")."quote=".$id_;
	echo "'><b>".$id_."</b></a>, ".$rn.
str_repeat("&nbsp;",2)."-".str_repeat("&nbsp;",2)."<b>".$subject."</b><br>
".$time."</p>
<p>".$content."</p>";

	echo "<div class='postreplies'>";

	if($preview)
		$st = $db->prepare("SELECT * FROM (SELECT * FROM ".DBPOSTTABLE." WHERE parent=:id ORDER BY time DESC LIMIT 3) ORDER BY id ASC");
	else
		$st = $db->prepare("SELECT * FROM ".DBPOSTTABLE." WHERE parent=:id ORDER BY time ASC");

	$st->bindValue(":id", $id, SQLITE3_INTEGER);
	$q = $st->execute();

	while($row = $q->fetchArray()) {
		list($id,,$time,,$name,$email,,$content,,,) = $row;

		$content = subquotes(nl2br(htmlentities($content, ENT_QUOTES, 'UTF-8')));

		$rn = ($email !== '') ? "<a href='mailto:".$email."'>".$name."</a>" : $name;

		echo "
<div class='replyview'>
<p><a title='quote this reply' href='";
		echo removeget(removeget($_SERVER["REQUEST_URI"],"quote"),"rquote")."&quote=".$id_."&rquote=".$id;
		echo "'><b>".$id."</b></a>, ".$rn."
<a title='open and reply' href='".basename(__FILE__)."?do=view&id=".$id."'>[reply]</a><br>".$time."</p>
<p>".$content."</p>
</div>";
	}
	
	echo "</div>";

	if($frozen === 0) {
		echo "
<br>
<form class='reply' id='reply-".$id_."' action='/mohu.php' method='post'>
reply to this post:<br><br>
<label for='name'>name: </label>
<input type='text' placeholder='anonymous' name='name' value='anonymous'>
<label for='email'>email: </label>
<input type='text' name='email'>
<input type='submit' value='post'><br>
<input type='hidden' name='do' value='reply'>
<input type='hidden' name='id' value='".(($parent !== null) ? $parent : $id_)."'>
<textarea form='reply-".$id_."' name='content' placeholder='blah blah...' cols='60' rows='8'>";
		if(isset($_GET['quote'])) {
			if($_GET['quote'] == $id_) {
				if(isset($_GET['rquote']))
					echo ">>".$_GET['rquote'];
				else
					echo ">>".$_GET['quote'];
			}
		} else if($parent !== null)
			echo ">>".$id_;

		echo "</textarea></form>";
	}
	else
		echo "<h3 class='frozen-post'>this post has been frozen</h3>";

	echo "</div><br>";
}

function preview_posts() {
	global $db;
	$q = $db->query("SELECT * FROM ".DBPOSTTABLE." WHERE parent IS NULL ORDER BY time DESC");
	if(!$q->fetchArray()) {
		echo "<h2><b>no posts to preview!</b></h2>";
		return;
	}

	$q->reset();
	echo "<hr><br><div id='listing'>";
	while($row = $q->fetchArray()) {
		echo "<a href='".basename(__FILE__)."?do=view&id=".
			$row["id"]."'>".$row["subject"]." (".$row["replies"].")</a> ";
	}
	echo "</div><br><hr><br>";

	$q = $db->query("SELECT * FROM ".DBPOSTTABLE." WHERE parent IS NULL ORDER BY time DESC LIMIT ".PREVIEW);
	while($row = $q->fetchArray()) {
		view($row['id'], true);
	}
}

function post_form() {
	echo "
<br>
<form id='newpost' action='/mohu.php' method='post'>
<label for='subject'>subject: </label>
<input type='text' name='subject' size='45'>
<input type='submit' value='post'><br>
<label for='name'>name: </label>
<input type='text' placeholder='anonymous' name='name' value='anonymous'>
<label for='email'>email: </label>
<input type='text' name='email'>
<input type='hidden' name='do' value='post'>
<textarea form='newpost' name='content' placeholder='blah blah...' cols='60' rows='8'></textarea>
</form>
";
}

// post funcs

function post() {
	global $db;

	if(!isset($_POST['subject']) || !isset($_POST['content'])) {
		say("your post did not go through");
	}

	$st = $db->prepare("INSERT INTO ".DBPOSTTABLE." 
		(ip,name,email,subject,content) VALUES (:ip,:name,:email,:subject,:content)");
	$st->bindValue(':ip', $_SERVER['REMOTE_ADDR']);
	$st->bindValue(':name', $_POST['name']);
	$st->bindValue(':email', $_POST['email']);
	$st->bindValue(':subject', $_POST['subject']);
	$st->bindValue(':content', $_POST['content']);
	$st->execute();

	$q = $db->query("SELECT * FROM ".DBPOSTTABLE." WHERE parent IS NULL");
	$count = 0;
	while($row = $q->fetchArray()) $count++;

	if($count > MAXPOSTS) { // prune oldest post
		$db->exec("DELETE FROM ".DBPOSTTABLE." WHERE id=(
			SELECT id FROM ".DBPOSTTABLE." ORDER BY time ASC LIMIT 1)");
	}

	say("congrats! your post went through");
}

function reply() {
	global $db;

	if(!isset($_POST['id']) || !isset($_POST['name']) || !isset($_POST['content'])) {
		say("your reply did not go through");
	}

	$st = $db->prepare("SELECT frozen FROM ".DBPOSTTABLE." WHERE id=:id");
	$st->bindValue(':id', $_POST['id'], SQLITE3_INTEGER);
	$q = $st->execute();

	if(!$q->fetchArray()) {
		say("something fucked up");
	}

	$q->reset();
	$row = $q->fetchArray();

	if($row["frozen"] === 1) {
		say("post is frozen");
	}

	$st = $db->prepare("INSERT INTO ".DBPOSTTABLE." 
		(parent,ip,name,email,subject,content) VALUES (:parent,:ip,:name,:email,:subject,:content)");
	$st->bindValue(':parent', $_POST['id'], SQLITE3_INTEGER);
	$st->bindValue(':ip', $_SERVER['REMOTE_ADDR']);
	$st->bindValue(':name', $_POST['name']);
	$st->bindValue(':email', $_POST['email']);
	$st->bindValue(':content', $_POST['content']);
	$st->execute();

	if($_POST['email'] !== 'sage') { // update timestamp on parent post if email isnt sage
		$st = $db->prepare("UPDATE ".DBPOSTTABLE. " 
			SET time=(datetime(CURRENT_TIMESTAMP,'localtime')) WHERE id=:id");
		$st->bindValue(':id', $_POST['id'], SQLITE3_INTEGER);
		$st->execute();
	}

	$st = $db->prepare("UPDATE ".DBPOSTTABLE. " SET replies=replies+1 WHERE id=:id");
	$st->bindValue(':id', $_POST['id'], SQLITE3_INTEGER);
	$st->execute();

	$st = $db->prepare("SELECT replies FROM ".DBPOSTTABLE." WHERE id=:id");
	$st->bindValue(':id', $_POST['id'], SQLITE3_INTEGER);
	$q = $st->execute();

	if(!$q->fetchArray()) {
		say("something fucked up");
	}

	$q->reset();
	$row = $q->fetchArray();

	if($row["replies"] > MAXREPLIES) {
		$st = $db->prepare("UPDATE ".DBPOSTTABLE. " SET frozen=1 WHERE id=:id");
		$st->bindValue(':id', $_POST['id'], SQLITE3_INTEGER);
		$st->execute();
		echo "froze post";
	}

	say("congrats! your reply went through");
}

if($_SERVER['REQUEST_METHOD'] === 'GET') {
	echo "<html>
<head>
<meta http-equiv='content-type' content='text/html;charset=shift_jis'>
<title>".BBSNAME."</title>
<link rel='stylesheet' href='style.css'>
</head>
<body>
<h1>".BBSTITLE."</h1>
<hr><br>
";

	if(isset($_GET['do'])) {
		switch($_GET['do']) {
		case 'view':
			view($_GET['id'], false);
			break;
		}
	} else {
		post_form();
		preview_posts();
	}
} else if($_SERVER['REQUEST_METHOD'] === 'POST') {
	if(!isset($_POST['do'])) {
		echo '<h3>what?</h3>';
	}

	switch($_POST['do']) {
	case 'post':
		post();
		break;
	case 'reply':
		reply();
		break;
	}
}

echo "
<hr>
<small>mohu ".VERSION."</small>
</body>
</html>";

?>
