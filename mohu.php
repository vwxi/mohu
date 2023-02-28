<?php

/* mohu bbs, by ten */
/* MAKE SURE PHP CAN WRITE TO FILES!!! */

ini_set('log_errors', 1);
ini_set('error_log', '/tmp/mohu.log');

define('VERSION', '1.0.0');

define('DBFILENAME', 'mohu.db');
define('DBPOSTTABLE', 'posts');
define('DBBANSTABLE', 'bans');

define('BBSNAME', 'mohu BBS');
define('BBSTITLE', "very important posters' club");

define('PREVIEW', 10);
define('MAXPOSTS', 50);
define('MAXREPLIES', 50);

$base = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

session_start();
if(empty($_SESSION['token'])) {
	$_SESSION['token'] = bin2hex(random_bytes(32));
}

$token = $_SESSION['token'];

try {
	$db = new SQLite3(DBFILENAME);
} catch(Exception $e) {
	die("caught exception ".$e->getMessage());
}

$q = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='".DBPOSTTABLE."'");
if(!$q->fetchArray()) {
	$db->exec("CREATE TABLE IF NOT EXISTS ".DBPOSTTABLE." (
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
	echo "made post table. ";
}

$q = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='".DBBANSTABLE."'");
if(!$q->fetchArray()) {
	$db->exec("CREATE TABLE IF NOT EXISTS ".DBBANSTABLE." (
		ip TEXT NOT NULL,
		why TEXT
	)");
	echo "made bans table. ";
}

$st = $db->prepare("SELECT * FROM ".DBBANSTABLE." WHERE ip=:ip");
$st->bindValue(":ip", $_SERVER['REMOTE_ADDR']);
$q = $st->execute();
if($res = $q->fetchArray()) {
	echo "you've been banned from ".BBSTITLE.". reason: ".$res["why"];
	die(0);
}

function say($m) {
	global $base;
	
	echo "<h3>".$m."</h3><a href=".$base.">let's go back</a>";
	die(0);
}

function subquotes($s) {
	global $base;

	$matches = array();
	preg_match_all("/&gt;&gt;[0-9]{1,}/", $s, $matches);
	foreach($matches[0] as $m) {
		$num = substr($m, 8);
		$nstr = "<a href='".$base."?do=view&id=".$num."'>".$m."</a>";
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
	global $base;
	global $token;

	$st = $db->prepare("SELECT * FROM ".DBPOSTTABLE." WHERE id=:id");
	$st->bindValue(':id', $id, SQLITE3_INTEGER);
	$q = $st->execute();

	if(!$q->fetchArray()) {
		say("post not found");
	}

	$q->reset();
	list($id_,$parent,$time,,$name,$email,$subject,$content,,$frozen) = $q->fetchArray();

	$name = htmlentities($name, ENT_QUOTES, 'UTF-8');
	$subject = htmlentities($subject, ENT_QUOTES, 'UTF-8');
	$content = nl2br(htmlentities($content, ENT_QUOTES, 'UTF-8'));
	$email = htmlentities($email, ENT_QUOTES, 'UTF-8');

	$rn = ($email !== '') ? "<a href='mailto:".$email."'>".$name."</a>" : $name;

	if(!$preview) echo "<h4><a class='goback' href='./'>go back</a></h4>";
	echo "
<div class='postview'>
<p><a title='quote this post' href='";

	echo removeget(removeget($_SERVER["REQUEST_URI"],"quote"),"rquote").(($preview)?"?":"&")."quote=".$id_;
	echo "'><b>".$id_."</b></a>, ".$rn.
str_repeat("&nbsp;",2)."-".str_repeat("&nbsp;",2)."<b>".$subject."</b>".
((!isset($_GET['do'])) ? str_repeat("&nbsp;",2)."<a href='".$base."?do=view&id=".$id_."'>[see full post]</a>" : "").
"<br>".$time."</p><p>".$content."</p>";

	echo "<div class='postreplies'>";

	if($preview)
		$st = $db->prepare("SELECT * FROM (SELECT * FROM ".DBPOSTTABLE." WHERE parent=:id ORDER BY time DESC LIMIT 3) ORDER BY id ASC");
	else
		$st = $db->prepare("SELECT * FROM ".DBPOSTTABLE." WHERE parent=:id ORDER BY time ASC");

	$st->bindValue(":id", $id, SQLITE3_INTEGER);
	$q = $st->execute();

	while($row = $q->fetchArray()) {
		list($id,,$time,,$name,$email,,$content,,,) = $row;

		$name = htmlentities($name, ENT_QUOTES, 'UTF-8');
		$content = subquotes(nl2br(htmlentities($content, ENT_QUOTES, 'UTF-8')));
		$email = htmlentities($email, ENT_QUOTES, 'UTF-8');

		$rn = ($email !== '') ? "<a href='mailto:".$email."'>".$name."</a>" : $name;

		echo "
<div class='replyview'>
<p><a title='quote this reply' href='";
		echo removeget(removeget($_SERVER["REQUEST_URI"],"quote"),"rquote")."&quote=".$id_."&rquote=".$id;
		echo "'><b>".$id."</b></a>, ".$rn."
<a title='open and reply' href='".$base."?do=view&id=".$id."'>[reply]</a><br>".$time."</p>
<p>".$content."</p>
</div>";
	}
	
	echo "</div>";

	if($frozen === 0) {
		echo "
<br>
<form class='reply' id='reply-".$id_."' action='".$base."' method='post'>
reply to this post:<br><br>
<label for='name'>name: </label>
<input type='text' placeholder='anonymous' name='name' value='anonymous'>
<label for='email'>email: </label>
<input type='text' name='email'>
<input type='submit' value='post'><br>
<input type='hidden' name='do' value='reply'>
<input type='hidden' name='token' value='".$token."'>
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
	global $base;

	$q = $db->query("SELECT * FROM ".DBPOSTTABLE." WHERE parent IS NULL ORDER BY time DESC");
	if(!$q->fetchArray()) {
		echo "<h2><b>no posts to preview!</b></h2>";
		return;
	}

	$q->reset();
	echo "<hr><br><div id='listing'>";
	while($row = $q->fetchArray()) {
		$subject = htmlentities($row["subject"], ENT_QUOTES, 'UTF-8');
		echo "<a href='".$base."?do=view&id=".
			$row["id"]."'>".$subject." (".$row["replies"].")</a> ";
	}
	echo "</div><br><hr><br>";

	$q = $db->query("SELECT * FROM ".DBPOSTTABLE." WHERE parent IS NULL ORDER BY time DESC LIMIT ".PREVIEW);
	while($row = $q->fetchArray()) {
		view($row['id'], true);
	}
}

function post_form() {
	global $base;
	global $token;

	echo "
<br>
<form id='newpost' action='".$base."' method='post'>
<label for='subject'>subject: </label>
<input type='text' name='subject' size='45'>
<input type='submit' value='post'><br>
<label for='name'>name: </label>
<input type='text' placeholder='anonymous' name='name' value='anonymous'>
<label for='email'>email: </label>
<input type='text' name='email'>
<input type='hidden' name='do' value='post'>
<input type='hidden' name='token' value='".$token."'>
<textarea form='newpost' name='content' placeholder='blah blah...' cols='60' rows='8'></textarea>
</form>
";
}

// post funcs

function post() {
	global $db;
	global $base;

	if(!isset($_POST['subject']) || !isset($_POST['content']) ||
		empty($_POST['subject']) || ctype_space($_POST['subject']) ||
		empty($_POST['content']) || ctype_space($_POST['content'])) {
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
	global $base;

	if(!isset($_POST['id']) || !isset($_POST['name']) || !isset($_POST['content']) ||	
		empty($_POST['content']) || ctype_space($_POST['content'])) {
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

echo "<html>
<head>
<meta http-equiv='content-type' content='text/html;charset=shift_jis'>
<title>".BBSNAME."</title>
<style>
html {background-color: #eddad2;width: 45%;margin: 0 auto;font-family: 'MS Pgothic', IPAMonaPGothic, Monapo, Mona, serif;}
textarea {white-space: normal;}
#newpost, #listing { text-align: center; }
.postview, .replyview {padding-top: 5px;padding-bottom: 5px;padding-left: 15px;padding-right: 15px;background-color: #efefef;text-align: justify;}
.frozen-post { color: red; }
</style>
</head>
<body>
<h1>".BBSTITLE."</h1>
<hr>
";

if($_SERVER['REQUEST_METHOD'] === 'GET') {
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

	if(!empty($_POST['token'])) {
		if(!hash_equals($_SESSION['token'], $_POST['token']))
			say("csrf shit woot woot");
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
