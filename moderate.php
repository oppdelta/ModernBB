<?php

/**
 * Copyright (C) 2013-2014 ModernBB Group
 * Based on code by FluxBB copyright (C) 2008-2012 FluxBB
 * Based on code by Rickard Andersson copyright (C) 2002-2008 PunBB
 * License under GPLv3
 */

define('FORUM_ROOT', dirname(__FILE__).'/');
require FORUM_ROOT.'include/common.php';

// This particular function doesn't require forum-based moderator access. It can be used
// by all moderators and admins
if (isset($_GET['get_host']))
{
	if (!$luna_user['is_admmod'])
		message($lang['No permission'], false, '403 Forbidden');

	// Is get_host an IP address or a post ID?
	if (@preg_match('%^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$%', $_GET['get_host']) || @preg_match('%^((([0-9A-Fa-f]{1,4}:){7}[0-9A-Fa-f]{1,4})|(([0-9A-Fa-f]{1,4}:){6}:[0-9A-Fa-f]{1,4})|(([0-9A-Fa-f]{1,4}:){5}:([0-9A-Fa-f]{1,4}:)?[0-9A-Fa-f]{1,4})|(([0-9A-Fa-f]{1,4}:){4}:([0-9A-Fa-f]{1,4}:){0,2}[0-9A-Fa-f]{1,4})|(([0-9A-Fa-f]{1,4}:){3}:([0-9A-Fa-f]{1,4}:){0,3}[0-9A-Fa-f]{1,4})|(([0-9A-Fa-f]{1,4}:){2}:([0-9A-Fa-f]{1,4}:){0,4}[0-9A-Fa-f]{1,4})|(([0-9A-Fa-f]{1,4}:){6}((\b((25[0-5])|(1\d{2})|(2[0-4]\d)|(\d{1,2}))\b)\.){3}(\b((25[0-5])|(1\d{2})|(2[0-4]\d)|(\d{1,2}))\b))|(([0-9A-Fa-f]{1,4}:){0,5}:((\b((25[0-5])|(1\d{2})|(2[0-4]\d)|(\d{1,2}))\b)\.){3}(\b((25[0-5])|(1\d{2})|(2[0-4]\d)|(\d{1,2}))\b))|(::([0-9A-Fa-f]{1,4}:){0,5}((\b((25[0-5])|(1\d{2})|(2[0-4]\d)|(\d{1,2}))\b)\.){3}(\b((25[0-5])|(1\d{2})|(2[0-4]\d)|(\d{1,2}))\b))|([0-9A-Fa-f]{1,4}::([0-9A-Fa-f]{1,4}:){0,5}[0-9A-Fa-f]{1,4})|(::([0-9A-Fa-f]{1,4}:){0,6}[0-9A-Fa-f]{1,4})|(([0-9A-Fa-f]{1,4}:){1,7}:))$%', $_GET['get_host']))
		$ip = $_GET['get_host'];
	else
	{
		$get_host = intval($_GET['get_host']);
		if ($get_host < 1)
			message($lang['Bad request'], false, '404 Not Found');

		$result = $db->query('SELECT poster_ip FROM '.$db->prefix.'posts WHERE id='.$get_host) or error('Unable to fetch post IP address', __FILE__, __LINE__, $db->error());
		if (!$db->num_rows($result))
			message($lang['Bad request'], false, '404 Not Found');

		$ip = $db->result($result);
	}
	
	message(sprintf($lang['Host info 1'], $ip).'<br />'.sprintf($lang['Host info 2'], @gethostbyaddr($ip)).'<br /><br /><a class="btn btn-primary" href="backstage/users.php?show_users='.$ip.'">'.$lang['Show more users'].'</a>');
}


// All other functions require moderator/admin access
$fid = isset($_GET['fid']) ? intval($_GET['fid']) : 0;
if ($fid < 1)
	message($lang['Bad request'], false, '404 Not Found');

$result = $db->query('SELECT moderators FROM '.$db->prefix.'forums WHERE id='.$fid) or error('Unable to fetch forum info', __FILE__, __LINE__, $db->error());

$moderators = $db->result($result);
$mods_array = ($moderators != '') ? unserialize($moderators) : array();

if ($luna_user['g_id'] != FORUM_ADMIN && ($luna_user['g_moderator'] == '0' || !array_key_exists($luna_user['username'], $mods_array)))
	message($lang['No permission'], false, '403 Forbidden');

// Get topic/forum tracking data
if (!$luna_user['is_guest'])
	$tracked_topics = get_tracked_topics();
	
// All other topic moderation features require a topic ID in GET
if (isset($_GET['tid']))
{
	$tid = intval($_GET['tid']);
	if ($tid < 1)
		message($lang['Bad request'], false, '404 Not Found');

	// Fetch some info about the topic
	$result = $db->query('SELECT t.subject, t.num_replies, t.first_post_id, f.id AS forum_id, forum_name FROM '.$db->prefix.'topics AS t INNER JOIN '.$db->prefix.'forums AS f ON f.id=t.forum_id LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id=f.id AND fp.group_id='.$luna_user['g_id'].') WHERE (fp.read_forum IS NULL OR fp.read_forum=1) AND f.id='.$fid.' AND t.id='.$tid.' AND t.moved_to IS NULL') or error('Unable to fetch topic info', __FILE__, __LINE__, $db->error());
	if (!$db->num_rows($result))
		message($lang['Bad request'], false, '404 Not Found');

	$cur_topic = $db->fetch_assoc($result);

	// Delete one or more posts
	if (isset($_POST['delete_posts']) || isset($_POST['delete_posts_comply']))
	{
		$posts = isset($_POST['posts']) ? $_POST['posts'] : array();
		if (empty($posts))
			message($lang['No posts selected']);

		if (isset($_POST['delete_posts_comply']))
		{
			confirm_referrer('moderate.php');

			if (@preg_match('%[^0-9,]%', $posts))
				message($lang['Bad request'], false, '404 Not Found');

			// Verify that the post IDs are valid
			$admins_sql = ($luna_user['g_id'] != FORUM_ADMIN) ? ' AND poster_id NOT IN('.implode(',', get_admin_ids()).')' : '';
			$result = $db->query('SELECT 1 FROM '.$db->prefix.'posts WHERE id IN('.$posts.') AND topic_id='.$tid.$admins_sql) or error('Unable to check posts', __FILE__, __LINE__, $db->error());

			if ($db->num_rows($result) != substr_count($posts, ',') + 1)
				message($lang['Bad request'], false, '404 Not Found');

			// Delete the posts
			$db->query('DELETE FROM '.$db->prefix.'posts WHERE id IN('.$posts.')') or error('Unable to delete posts', __FILE__, __LINE__, $db->error());

			require FORUM_ROOT.'include/search_idx.php';
			strip_search_index($posts);

			// Get last_post, last_post_id, and last_poster for the topic after deletion
			$result = $db->query('SELECT id, poster, posted FROM '.$db->prefix.'posts WHERE topic_id='.$tid.' ORDER BY id DESC LIMIT 1') or error('Unable to fetch post info', __FILE__, __LINE__, $db->error());
			$last_post = $db->fetch_assoc($result);

			// How many posts did we just delete?
			$num_posts_deleted = substr_count($posts, ',') + 1;

			// Update the topic
			$db->query('UPDATE '.$db->prefix.'topics SET last_post='.$last_post['posted'].', last_post_id='.$last_post['id'].', last_poster=\''.$db->escape($last_post['poster']).'\', num_replies=num_replies-'.$num_posts_deleted.' WHERE id='.$tid) or error('Unable to update topic', __FILE__, __LINE__, $db->error());

			update_forum($fid);

			redirect('viewtopic.php?id='.$tid, $lang['Delete posts redirect']);
		}

		$page_title = array(luna_htmlspecialchars($luna_config['o_board_title']), $lang['Moderate']);
		define('FORUM_ACTIVE_PAGE', 'index');
		require FORUM_ROOT.'header.php';

?>
<h2><?php echo $lang['Moderate'] ?></h2>
<form method="post" action="moderate.php?fid=<?php echo $fid ?>&amp;tid=<?php echo $tid ?>">
    <div class="panel panel-danger">
        <div class="panel-heading">
            <h3 class="panel-title"><?php echo $lang['Delete posts'] ?></h3>
        </div>
        <div class="panel-body">
            <fieldset>
                <input type="hidden" name="posts" value="<?php echo implode(',', array_map('intval', array_keys($posts))) ?>" />
                <p><?php echo $lang['Delete posts comply'] ?></p>
            </fieldset>
        </div>
        <div class="panel-footer">
            <input class="btn btn-primary" type="submit" name="delete_posts_comply" value="<?php echo $lang['Delete'] ?>" /> <a class="btn btn-link" href="javascript:history.go(-1)"><?php echo $lang['Go back'] ?></a>
        </div>
    </div>
</form>
<?php

		require FORUM_ROOT.'footer.php';
	}
	else if (isset($_POST['split_posts']) || isset($_POST['split_posts_comply']))
	{
		$posts = isset($_POST['posts']) ? $_POST['posts'] : array();
		if (empty($posts))
			message($lang['No posts selected']);

		if (isset($_POST['split_posts_comply']))
		{
			confirm_referrer('moderate.php');

			if (@preg_match('%[^0-9,]%', $posts))
				message($lang['Bad request'], false, '404 Not Found');

			$move_to_forum = isset($_POST['move_to_forum']) ? intval($_POST['move_to_forum']) : 0;
			if ($move_to_forum < 1)
				message($lang['Bad request'], false, '404 Not Found');

			// How many posts did we just split off?
			$num_posts_splitted = substr_count($posts, ',') + 1;

			// Verify that the post IDs are valid
			$result = $db->query('SELECT 1 FROM '.$db->prefix.'posts WHERE id IN('.$posts.') AND topic_id='.$tid) or error('Unable to check posts', __FILE__, __LINE__, $db->error());
			if ($db->num_rows($result) != $num_posts_splitted)
				message($lang['Bad request'], false, '404 Not Found');

			// Verify that the move to forum ID is valid
			$result = $db->query('SELECT 1 FROM '.$db->prefix.'forums AS f LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.group_id='.$luna_user['g_id'].' AND fp.forum_id='.$move_to_forum.') WHERE f.redirect_url IS NULL AND (fp.post_topics IS NULL OR fp.post_topics=1)') or error('Unable to fetch forum permissions', __FILE__, __LINE__, $db->error());
			if (!$db->num_rows($result))
				message($lang['Bad request'], false, '404 Not Found');

			// Check subject
			$new_subject = isset($_POST['new_subject']) ? luna_trim($_POST['new_subject']) : '';

			if ($new_subject == '')
				message($lang['No subject']);
			else if (luna_strlen($new_subject) > 70)
				message($lang['Too long subject']);

			// Get data from the new first post
			$result = $db->query('SELECT p.id, p.poster, p.posted FROM '.$db->prefix.'posts AS p WHERE id IN('.$posts.') ORDER BY p.id ASC LIMIT 1') or error('Unable to get first post', __FILE__, __LINE__, $db->error());
			$first_post_data = $db->fetch_assoc($result);

			// Create the new topic
			$db->query('INSERT INTO '.$db->prefix.'topics (poster, subject, posted, first_post_id, forum_id) VALUES (\''.$db->escape($first_post_data['poster']).'\', \''.$db->escape($new_subject).'\', '.$first_post_data['posted'].', '.$first_post_data['id'].', '.$move_to_forum.')') or error('Unable to create new topic', __FILE__, __LINE__, $db->error());
			$new_tid = $db->insert_id();

			// Move the posts to the new topic
			$db->query('UPDATE '.$db->prefix.'posts SET topic_id='.$new_tid.' WHERE id IN('.$posts.')') or error('Unable to move posts into new topic', __FILE__, __LINE__, $db->error());

			// Apply every subscription to both topics
			$db->query('INSERT INTO '.$db->prefix.'topic_subscriptions (user_id, topic_id) SELECT user_id, '.$new_tid.' FROM '.$db->prefix.'topic_subscriptions WHERE topic_id='.$tid) or error('Unable to copy existing subscriptions', __FILE__, __LINE__, $db->error());

			// Get last_post, last_post_id, and last_poster from the topic and update it
			$result = $db->query('SELECT id, poster, posted FROM '.$db->prefix.'posts WHERE topic_id='.$tid.' ORDER BY id DESC LIMIT 1') or error('Unable to fetch post info', __FILE__, __LINE__, $db->error());
			$last_post_data = $db->fetch_assoc($result);
			$db->query('UPDATE '.$db->prefix.'topics SET last_post='.$last_post_data['posted'].', last_post_id='.$last_post_data['id'].', last_poster=\''.$db->escape($last_post_data['poster']).'\', num_replies=num_replies-'.$num_posts_splitted.' WHERE id='.$tid) or error('Unable to update topic', __FILE__, __LINE__, $db->error());

			// Get last_post, last_post_id, and last_poster from the new topic and update it
			$result = $db->query('SELECT id, poster, posted FROM '.$db->prefix.'posts WHERE topic_id='.$new_tid.' ORDER BY id DESC LIMIT 1') or error('Unable to fetch post info', __FILE__, __LINE__, $db->error());
			$last_post_data = $db->fetch_assoc($result);
			$db->query('UPDATE '.$db->prefix.'topics SET last_post='.$last_post_data['posted'].', last_post_id='.$last_post_data['id'].', last_poster=\''.$db->escape($last_post_data['poster']).'\', num_replies='.($num_posts_splitted-1).' WHERE id='.$new_tid) or error('Unable to update topic', __FILE__, __LINE__, $db->error());

			update_forum($fid);
			update_forum($move_to_forum);

			redirect('viewtopic.php?id='.$new_tid, $lang['Split posts redirect']);
		}

		$result = $db->query('SELECT c.id AS cid, c.cat_name, f.id AS fid, f.forum_name FROM '.$db->prefix.'categories AS c INNER JOIN '.$db->prefix.'forums AS f ON c.id=f.cat_id LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id=f.id AND fp.group_id='.$luna_user['g_id'].') WHERE (fp.post_topics IS NULL OR fp.post_topics=1) AND f.redirect_url IS NULL ORDER BY c.disp_position, c.id, f.disp_position') or error('Unable to fetch category/forum list', __FILE__, __LINE__, $db->error());

		$page_title = array(luna_htmlspecialchars($luna_config['o_board_title']), $lang['Moderate']);
		$focus_element = array('subject','new_subject');
		define('FORUM_ACTIVE_PAGE', 'index');
		require FORUM_ROOT.'header.php';

?>
<form id="subject" class="form-horizontal" method="post" action="moderate.php?fid=<?php echo $fid ?>&amp;tid=<?php echo $tid ?>">
<h2><?php echo $lang['Moderate'] ?></h2>
    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title"><?php echo $lang['Split posts'] ?></h3>
        </div>
        <div class="panel-body">
            <fieldset>
                <input type="hidden" class="form-control" name="posts" value="<?php echo implode(',', array_map('intval', array_keys($posts))) ?>" />
                <div class="form-group">
                    <label class="col-sm-2 control-label"><?php echo $lang['Move to'] ?></label>
                    <div class="col-sm-10">
                        <select class="form-control" name="move_to_forum">
<?php

	$cur_category = 0;
	while ($cur_forum = $db->fetch_assoc($result))
	{
		if ($cur_forum['cid'] != $cur_category) // A new category since last iteration?
		{
			if ($cur_category)
				echo "\t\t\t\t\t\t\t".'</optgroup>'."\n";

			echo "\t\t\t\t\t\t\t".'<optgroup label="'.luna_htmlspecialchars($cur_forum['cat_name']).'">'."\n";
			$cur_category = $cur_forum['cid'];
		}

		echo "\t\t\t\t\t\t\t\t".'<option value="'.$cur_forum['fid'].'"'.($fid == $cur_forum['fid'] ? ' selected="selected"' : '').'>'.luna_htmlspecialchars($cur_forum['forum_name']).'</option>'."\n";
	}

?>
							</optgroup>
						</select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-2 control-label"><?php echo $lang['New subject'] ?></label>
                    <div class="col-sm-10">
                        <input class="form-control" type="text" name="new_subject" maxlength="70" />
                    </div>
                </div>
            </fieldset>
        </div>
        <div class="panel-footer">
			<input type="submit" class="btn btn-primary" name="split_posts_comply" value="<?php echo $lang['Split'] ?>" /><a class="btn btn-link" href="javascript:history.go(-1)"><?php echo $lang['Go back'] ?></a>
        </div>
    </div>
</form>
<?php

		require FORUM_ROOT.'footer.php';
	}


	// Show the moderate posts view

	// Used to disable the Move and Delete buttons if there are no replies to this topic
	$button_status = ($cur_topic['num_replies'] == 0) ? ' disabled="disabled"' : '';

	if (isset($_GET['action']) && $_GET['action'] == 'all')
		$luna_user['disp_posts'] = $cur_topic['num_replies'] + 1;

	// Determine the post offset (based on $_GET['p'])
	$num_pages = ceil(($cur_topic['num_replies'] + 1) / $luna_user['disp_posts']);

	$p = (!isset($_GET['p']) || $_GET['p'] <= 1 || $_GET['p'] > $num_pages) ? 1 : intval($_GET['p']);
	$start_from = $luna_user['disp_posts'] * ($p - 1);

	// Generate paging links
	$paging_links = paginate($num_pages, $p, 'moderate.php?fid='.$fid.'&amp;tid='.$tid);


	if ($luna_config['o_censoring'] == '1')
		$cur_topic['subject'] = censor_words($cur_topic['subject']);


	$page_title = array(luna_htmlspecialchars($luna_config['o_board_title']), luna_htmlspecialchars($cur_topic['forum_name']), luna_htmlspecialchars($cur_topic['subject']));
	define('FORUM_ACTIVE_PAGE', 'index');
	require FORUM_ROOT.'header.php';

?>
<ul class="breadcrumb">
	<li><a href="index.php"><?php echo $lang['Index'] ?></a></li>
	<li><a href="viewforum.php?id=<?php echo $fid ?>"><?php echo luna_htmlspecialchars($cur_topic['forum_name']) ?></a></li>
	<li><a href="viewtopic.php?id=<?php echo $tid ?>"><?php echo luna_htmlspecialchars($cur_topic['subject']) ?></a></li>
	<li class="active"><?php echo $lang['Moderate'] ?></li>
</ul>
<div class="pagepost">
    <ul class="pagination">
        <?php echo $paging_links ?>
    </ul>
</div>

<form method="post" action="moderate.php?fid=<?php echo $fid ?>&amp;tid=<?php echo $tid ?>">
<?php

	require FORUM_ROOT.'include/parser.php';

	$post_count = 0; // Keep track of post numbers

	// Retrieve a list of post IDs, LIMIT is (really) expensive so we only fetch the IDs here then later fetch the remaining data
	$result = $db->query('SELECT id FROM '.$db->prefix.'posts WHERE topic_id='.$tid.' ORDER BY id LIMIT '.$start_from.','.$luna_user['disp_posts']) or error('Unable to fetch post IDs', __FILE__, __LINE__, $db->error());

	$post_ids = array();
	for ($i = 0;$cur_post_id = $db->result($result, $i);$i++)
		$post_ids[] = $cur_post_id;

	// Retrieve the posts (and their respective poster)
	$result = $db->query('SELECT u.title, u.num_posts, g.g_id, g.g_user_title, p.id, p.poster, p.poster_id, p.message, p.hide_smilies, p.posted, p.edited, p.edited_by, o.user_id AS is_online FROM '.$db->prefix.'posts AS p INNER JOIN '.$db->prefix.'users AS u ON u.id=p.poster_id INNER JOIN '.$db->prefix.'groups AS g ON g.g_id=u.group_id LEFT JOIN '.$db->prefix.'online AS o ON (o.user_id=u.id AND o.user_id!=1 AND o.idle=0) WHERE p.id IN ('.implode(',', $post_ids).') ORDER BY p.id', true) or error('Unable to fetch post info', __FILE__, __LINE__, $db->error());

	while ($cur_post = $db->fetch_assoc($result))
	{
		$post_count++;

		// If the poster is a registered user
		if ($cur_post['poster_id'] > 1)
		{
			if ($luna_user['g_view_users'] == '1')
				$poster = '<a href="profile.php?id='.$cur_post['poster_id'].'">'.luna_htmlspecialchars($cur_post['poster']).'</a>';
			else
				$poster = luna_htmlspecialchars($cur_post['poster']);

			// get_title() requires that an element 'username' be present in the array
			$cur_post['username'] = $cur_post['poster'];
			$user_title = get_title($cur_post);

			if ($luna_config['o_censoring'] == '1')
				$user_title = censor_words($user_title);
		}
		// If the poster is a guest (or a user that has been deleted)
		else
		{
			$poster = luna_htmlspecialchars($cur_post['poster']);
			$user_title = $lang['Guest'];
		}

		// Format the online indicator, those are ment as CSS classes
		$is_online = ($cur_post['is_online'] == $cur_post['poster_id']) ? 'is-online' : 'is-offline';

		// Perform the main parsing of the message (BBCode, smilies, censor words etc)
		$cur_post['message'] = parse_message($cur_post['message'], $cur_post['hide_smilies']);

?>
<div id="p<?php echo $cur_post['id'] ?>" class="blockpost<?php if($cur_post['id'] == $cur_topic['first_post_id']) echo ' firstpost' ?><?php echo ($post_count % 2 == 0) ? ' roweven' : ' rowodd' ?><?php if ($post_count == 1) echo ' blockpost1' ?>">
	<table class="table postview">
        <tr>
            <td class="col-lg-2 user-data">
                <dd class="usertitle <?php echo $is_online; ?>"><strong><?php echo $poster ?></strong></dd><?php echo $user_title ?>
            </td>
            <td class="col-lg-10 post-content">
                <span class="time-nr pull-right">#<?php echo ($start_from + $post_count) ?> &middot; <a href="viewtopic.php?pid=<?php echo $cur_post['id'].'#p'.$cur_post['id'] ?>"><?php echo format_time($cur_post['posted']) ?></a></span>
                <div class="postmsg">
                    <?php echo $cur_post['message']."\n" ?>
                    <?php if ($cur_post['edited'] != '') echo "\t\t\t\t\t\t".'<p class="postedit"><em>'.$lang['Last edit'].' '.luna_htmlspecialchars($cur_post['edited_by']).' ('.format_time($cur_post['edited']).')</em></p>'."\n"; ?>
                </div>
            </td>
        </tr>
        <?php if (!$luna_user['is_guest']) { ?>
        <tr>
            <td colspan="2" class="postfooter" style="padding-bottom: 0;">
                <?php echo ($cur_post['id'] != $cur_topic['first_post_id']) ? '<div class="checkbox pull-right" style="margin-top: 0;"><label><input type="checkbox" name="posts['.$cur_post['id'].']" value="1" /> '.$lang['Select'].'</label></div>' : '<p>'.$lang['Cannot select first'].'</p>' ?>
            </td>
        </tr>
        <?php } ?>
	</table>
</div>

<?php

	}

?>
<span class="pull-right btn-group"><input type="submit" class="btn btn-primary" name="split_posts" value="<?php echo $lang['Split'] ?>"<?php echo $button_status ?> /><input type="submit" class="btn btn-primary" name="delete_posts" value="<?php echo $lang['Delete'] ?>"<?php echo $button_status ?> /></span>

<div class="pagepost">
    <ul class="pagination">
        <?php echo $paging_links ?>
    </ul>
</div>
<ul class="breadcrumb">
	<li><a href="index.php"><?php echo $lang['Index'] ?></a></li>
	<li><a href="viewforum.php?id=<?php echo $fid ?>"><?php echo luna_htmlspecialchars($cur_topic['forum_name']) ?></a></li>
	<li><a href="viewtopic.php?id=<?php echo $tid ?>"><?php echo luna_htmlspecialchars($cur_topic['subject']) ?></a></li>
	<li class="active"><?php echo $lang['Moderate'] ?></li>
</ul>
</form>
<?php

	require FORUM_ROOT.'footer.php';
}


// Move one or more topics
if (isset($_REQUEST['move_topics']) || isset($_POST['move_topics_to']))
{
	if (isset($_POST['move_topics_to']))
	{
		confirm_referrer('moderate.php');

		if (@preg_match('%[^0-9,]%', $_POST['topics']))
			message($lang['Bad request'], false, '404 Not Found');

		$topics = explode(',', $_POST['topics']);
		$move_to_forum = isset($_POST['move_to_forum']) ? intval($_POST['move_to_forum']) : 0;
		if (empty($topics) || $move_to_forum < 1)
			message($lang['Bad request'], false, '404 Not Found');

		// Verify that the topic IDs are valid
		$result = $db->query('SELECT 1 FROM '.$db->prefix.'topics WHERE id IN('.implode(',',$topics).') AND forum_id='.$fid) or error('Unable to check topics', __FILE__, __LINE__, $db->error());

		if ($db->num_rows($result) != count($topics))
			message($lang['Bad request'], false, '404 Not Found');

		// Verify that the move to forum ID is valid
		$result = $db->query('SELECT 1 FROM '.$db->prefix.'forums AS f LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.group_id='.$luna_user['g_id'].' AND fp.forum_id='.$move_to_forum.') WHERE f.redirect_url IS NULL AND (fp.post_topics IS NULL OR fp.post_topics=1)') or error('Unable to fetch forum permissions', __FILE__, __LINE__, $db->error());
		if (!$db->num_rows($result))
			message($lang['Bad request'], false, '404 Not Found');

		// Delete any redirect topics if there are any (only if we moved/copied the topic back to where it was once moved from)
		$db->query('DELETE FROM '.$db->prefix.'topics WHERE forum_id='.$move_to_forum.' AND moved_to IN('.implode(',',$topics).')') or error('Unable to delete redirect topics', __FILE__, __LINE__, $db->error());

		// Move the topic(s)
		$db->query('UPDATE '.$db->prefix.'topics SET forum_id='.$move_to_forum.' WHERE id IN('.implode(',',$topics).')') or error('Unable to move topics', __FILE__, __LINE__, $db->error());

		// Should we create redirect topics?
		if (isset($_POST['with_redirect']))
		{
			foreach ($topics as $cur_topic)
			{
				// Fetch info for the redirect topic
				$result = $db->query('SELECT poster, subject, posted, last_post FROM '.$db->prefix.'topics WHERE id='.$cur_topic) or error('Unable to fetch topic info', __FILE__, __LINE__, $db->error());
				$moved_to = $db->fetch_assoc($result);

				// Create the redirect topic
				$db->query('INSERT INTO '.$db->prefix.'topics (poster, subject, posted, last_post, moved_to, forum_id) VALUES(\''.$db->escape($moved_to['poster']).'\', \''.$db->escape($moved_to['subject']).'\', '.$moved_to['posted'].', '.$moved_to['last_post'].', '.$cur_topic.', '.$fid.')') or error('Unable to create redirect topic', __FILE__, __LINE__, $db->error());
			}
		}

		update_forum($fid); // Update the forum FROM which the topic was moved
		update_forum($move_to_forum); // Update the forum TO which the topic was moved

		$redirect_msg = (count($topics) > 1) ? $lang['Move topics redirect'] : $lang['Move topic redirect'];
		redirect('viewforum.php?id='.$move_to_forum, $redirect_msg);
	}

	if (isset($_POST['move_topics']))
	{
		$topics = isset($_POST['topics']) ? $_POST['topics'] : array();
		if (empty($topics))
			message($lang['No topics selected']);

		$topics = implode(',', array_map('intval', array_keys($topics)));
		$action = 'multi';
	}
	else
	{
		$topics = intval($_GET['move_topics']);
		if ($topics < 1)
			message($lang['Bad request'], false, '404 Not Found');

		$action = 'single';
	}

	$result = $db->query('SELECT c.id AS cid, c.cat_name, f.id AS fid, f.forum_name FROM '.$db->prefix.'categories AS c INNER JOIN '.$db->prefix.'forums AS f ON c.id=f.cat_id LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id=f.id AND fp.group_id='.$luna_user['g_id'].') WHERE (fp.post_topics IS NULL OR fp.post_topics=1) AND f.redirect_url IS NULL ORDER BY c.disp_position, c.id, f.disp_position') or error('Unable to fetch category/forum list', __FILE__, __LINE__, $db->error());
	if ($db->num_rows($result) < 2)
		message($lang['Nowhere to move']);

	$page_title = array(luna_htmlspecialchars($luna_config['o_board_title']), $lang['Moderate']);
	define('FORUM_ACTIVE_PAGE', 'index');
	require FORUM_ROOT.'header.php';

?>
<h2><?php echo $lang['Moderate'] ?></h2>
<form class="form-horizontal" method="post" action="moderate.php?fid=<?php echo $fid ?>">
    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title"><?php echo ($action == 'single') ? $lang['Move topic'] : $lang['Move topics'] ?></h3>
        </div>
        <div class="panel-body">
			<input type="hidden" name="topics" value="<?php echo $topics ?>" />
            <fieldset>
                <div class="form-group">
                    <label class="col-sm-2 control-label"><?php echo $lang['Move to'] ?></label>
                    <div class="col-sm-10">
                        <select class="form-control" name="move_to_forum">
<?php

	$cur_category = 0;
	while ($cur_forum = $db->fetch_assoc($result))
	{
		if ($cur_forum['cid'] != $cur_category) // A new category since last iteration?
		{
			if ($cur_category)
				echo "\t\t\t\t\t\t\t".'</optgroup>'."\n";

			echo "\t\t\t\t\t\t\t".'<optgroup label="'.luna_htmlspecialchars($cur_forum['cat_name']).'">'."\n";
			$cur_category = $cur_forum['cid'];
		}

		if ($cur_forum['fid'] != $fid)
			echo "\t\t\t\t\t\t\t\t".'<option value="'.$cur_forum['fid'].'">'.luna_htmlspecialchars($cur_forum['forum_name']).'</option>'."\n";
	}

?>
                            </optgroup>
                        </select>
                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="with_redirect" value="1"<?php if ($action == 'single') echo ' checked="checked"' ?> />
                                <?php echo $lang['Leave redirect'] ?>
                            </label>
                        </div>
                    </div>
                </div>
            </fieldset>
        </div>
        <div class="panel-footer">
            <input type="submit" class="btn btn-primary" name="move_topics_to" value="<?php echo $lang['Move'] ?>" /><a class="btn btn-link" href="javascript:history.go(-1)"><?php echo $lang['Go back'] ?></a>
        </div>
    </div>
</form>
<?php

	require FORUM_ROOT.'footer.php';
}

// Merge two or more topics
else if (isset($_POST['merge_topics']) || isset($_POST['merge_topics_comply']))
{
	if (isset($_POST['merge_topics_comply']))
	{
		confirm_referrer('moderate.php');

		if (@preg_match('%[^0-9,]%', $_POST['topics']))
			message($lang['Bad request'], false, '404 Not Found');

		$topics = explode(',', $_POST['topics']);
		if (count($topics) < 2)
			message($lang['Not enough topics selected']);

		// Verify that the topic IDs are valid (redirect links will point to the merged topic after the merge)
		$result = $db->query('SELECT id FROM '.$db->prefix.'topics WHERE id IN('.implode(',', $topics).') AND forum_id='.$fid.' ORDER BY id ASC') or error('Unable to check topics', __FILE__, __LINE__, $db->error());
		if ($db->num_rows($result) != count($topics))
			message($lang['Bad request'], false, '404 Not Found');

		// The topic that we are merging into is the one with the smallest ID
		$merge_to_tid = $db->result($result);

		// Make any redirect topics point to our new, merged topic
		$query = 'UPDATE '.$db->prefix.'topics SET moved_to='.$merge_to_tid.' WHERE moved_to IN('.implode(',', $topics).')';

		// Should we create redirect topics?
		if (isset($_POST['with_redirect']))
			$query .= ' OR (id IN('.implode(',', $topics).') AND id != '.$merge_to_tid.')';

		$db->query($query) or error('Unable to make redirection topics', __FILE__, __LINE__, $db->error());

		// Merge the posts into the topic
		$db->query('UPDATE '.$db->prefix.'posts SET topic_id='.$merge_to_tid.' WHERE topic_id IN('.implode(',', $topics).')') or error('Unable to merge the posts into the topic', __FILE__, __LINE__, $db->error());

		// Update any subscriptions
		$result = $db->query('SELECT DISTINCT user_id FROM '.$db->prefix.'topic_subscriptions WHERE topic_id IN ('.implode(',', $topics).')') or error('Unable to fetch subscriptions of merged topics', __FILE__, __LINE__, $db->error());

		$subscribed_users = array();
		while ($row = $db->fetch_row($result))
			$subscribed_users[] = $row[0];


		$db->query('DELETE FROM '.$db->prefix.'topic_subscriptions WHERE topic_id IN ('.implode(',', $topics).')') or error('Unable to delete subscriptions of merged topics', __FILE__, __LINE__, $db->error());

		foreach ($subscribed_users as $cur_user_id)
			$db->query('INSERT INTO '.$db->prefix.'topic_subscriptions (topic_id, user_id) VALUES ('.$merge_to_tid.', '.$cur_user_id.')') or error('Unable to re-enter subscriptions for merge topic', __FILE__, __LINE__, $db->error());

		// Without redirection the old topics are removed
		if (!isset($_POST['with_redirect']))
			$db->query('DELETE FROM '.$db->prefix.'topics WHERE id IN('.implode(',', $topics).') AND id != '.$merge_to_tid) or error('Unable to delete old topics', __FILE__, __LINE__, $db->error());

		// Count number of replies in the topic
		$result = $db->query('SELECT COUNT(id) FROM '.$db->prefix.'posts WHERE topic_id='.$merge_to_tid) or error('Unable to fetch post count for topic', __FILE__, __LINE__, $db->error());
		$num_replies = $db->result($result, 0) - 1;

		// Get last_post, last_post_id and last_poster
		$result = $db->query('SELECT posted, id, poster FROM '.$db->prefix.'posts WHERE topic_id='.$merge_to_tid.' ORDER BY id DESC LIMIT 1') or error('Unable to get last post info', __FILE__, __LINE__, $db->error());
		list($last_post, $last_post_id, $last_poster) = $db->fetch_row($result);

		// Update topic
		$db->query('UPDATE '.$db->prefix.'topics SET num_replies='.$num_replies.', last_post='.$last_post.', last_post_id='.$last_post_id.', last_poster=\''.$db->escape($last_poster).'\' WHERE id='.$merge_to_tid) or error('Unable to update topic', __FILE__, __LINE__, $db->error());

		// Update the forum FROM which the topic was moved and redirect
		update_forum($fid);
		redirect('viewforum.php?id='.$fid, $lang['Merge topics redirect']);
	}

	$topics = isset($_POST['topics']) ? $_POST['topics'] : array();
	if (count($topics) < 2)
		message($lang['Not enough topics selected']);

	$page_title = array(luna_htmlspecialchars($luna_config['o_board_title']), $lang['Moderate']);
	define('FORUM_ACTIVE_PAGE', 'index');
	require FORUM_ROOT.'header.php';

?>
<h2><?php echo $lang['Moderate'] ?></h2>
<form method="post" action="moderate.php?fid=<?php echo $fid ?>">
    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title"><?php echo $lang['Merge topics'] ?></h3>
        </div>
        <div class="panel-body">
			<input type="hidden" name="topics" value="<?php echo implode(',', array_map('intval', array_keys($topics))) ?>" />
            <fieldset>
                <div class="checkbox">
                    <label>
                        <input type="checkbox" name="with_redirect" value="1" />
                        <?php echo $lang['Leave redirect'] ?>
                    </label>
                </div>
            </fieldset>
        </div>
        <div class="panel-footer">
			<input type="submit" class="btn btn-primary" name="merge_topics_comply" value="<?php echo $lang['Merge'] ?>" /><a class="btn btn-link" href="javascript:history.go(-1)"><?php echo $lang['Go back'] ?></a>
        </div>
    </div>
</form>
<?php

	require FORUM_ROOT.'footer.php';
}

// Delete one or more topics
else if (isset($_POST['delete_topics']) || isset($_POST['delete_topics_comply']))
{
	$topics = isset($_POST['topics']) ? $_POST['topics'] : array();
	if (empty($topics))
		message($lang['No topics selected']);

	if (isset($_POST['delete_topics_comply']))
	{
		confirm_referrer('moderate.php');

		if (@preg_match('%[^0-9,]%', $topics))
			message($lang['Bad request'], false, '404 Not Found');

		require FORUM_ROOT.'include/search_idx.php';

		// Verify that the topic IDs are valid
		$result = $db->query('SELECT 1 FROM '.$db->prefix.'topics WHERE id IN('.$topics.') AND forum_id='.$fid) or error('Unable to check topics', __FILE__, __LINE__, $db->error());

		if ($db->num_rows($result) != substr_count($topics, ',') + 1)
			message($lang['Bad request'], false, '404 Not Found');
			
		// Verify that the posts are not by admins  
		if ($luna_user['g_id'] != FORUM_ADMIN)  
		{  
			$result = $db->query('SELECT 1 FROM '.$db->prefix.'posts WHERE topic_id IN('.$topics.') AND poster_id IN('.implode(',', get_admin_ids()).')') or error('Unable to check posts', __FILE__, __LINE__, $db->error());
			if ($db->num_rows($result))  
				message($lang['No permission'], false, '403 Forbidden'); 
		}  


		// Delete the topics and any redirect topics
		$db->query('DELETE FROM '.$db->prefix.'topics WHERE id IN('.$topics.') OR moved_to IN('.$topics.')') or error('Unable to delete topic', __FILE__, __LINE__, $db->error());

		// Delete any subscriptions
		$db->query('DELETE FROM '.$db->prefix.'topic_subscriptions WHERE topic_id IN('.$topics.')') or error('Unable to delete subscriptions', __FILE__, __LINE__, $db->error());

		// Create a list of the post IDs in this topic and then strip the search index
		$result = $db->query('SELECT id FROM '.$db->prefix.'posts WHERE topic_id IN('.$topics.')') or error('Unable to fetch posts', __FILE__, __LINE__, $db->error());

		$post_ids = '';
		while ($row = $db->fetch_row($result))
			$post_ids .= ($post_ids != '') ? ','.$row[0] : $row[0];

		// We have to check that we actually have a list of post IDs since we could be deleting just a redirect topic
		if ($post_ids != '')
			strip_search_index($post_ids);

		// Delete posts
		$db->query('DELETE FROM '.$db->prefix.'posts WHERE topic_id IN('.$topics.')') or error('Unable to delete posts', __FILE__, __LINE__, $db->error());

		update_forum($fid);

		redirect('viewforum.php?id='.$fid, $lang['Delete topics redirect']);
	}


	$page_title = array(luna_htmlspecialchars($luna_config['o_board_title']), $lang['Moderate']);
	define('FORUM_ACTIVE_PAGE', 'index');
	require FORUM_ROOT.'header.php';

?>
<form method="post" action="moderate.php?fid=<?php echo $fid ?>">
    <h2><?php echo $lang['Moderate'] ?></h2>
    <div class="panel panel-danger">
        <div class="panel-heading">
            <h3 class="panel-title"><?php echo $lang['Delete topics'] ?></h3>
        </div>
        <div class="panel-body">
			<input type="hidden" name="topics" value="<?php echo implode(',', array_map('intval', array_keys($topics))) ?>" />
            <fieldset>
                <p><?php echo $lang['Delete topics comply'] ?></p>
            </fieldset>
        </div>
        <div class="panel-footer">
			<input type="submit" class="btn btn-danger" name="delete_topics_comply" value="<?php echo $lang['Delete'] ?>" /><a class="btn btn-link" href="javascript:history.go(-1)"><?php echo $lang['Go back'] ?></a>
        </div>
    </div>
</form>
<?php

	require FORUM_ROOT.'footer.php';
}


// Open or close one or more topics
else if (isset($_REQUEST['open']) || isset($_REQUEST['close']))
{
	$action = (isset($_REQUEST['open'])) ? 0 : 1;

	// There could be an array of topic IDs in $_POST
	if (isset($_POST['open']) || isset($_POST['close']))
	{
		confirm_referrer('moderate.php');

		$topics = isset($_POST['topics']) ? @array_map('intval', @array_keys($_POST['topics'])) : array();
		if (empty($topics))
			message($lang['No topics selected']);

		$db->query('UPDATE '.$db->prefix.'topics SET closed='.$action.' WHERE id IN('.implode(',', $topics).') AND forum_id='.$fid) or error('Unable to close topics', __FILE__, __LINE__, $db->error());

		$redirect_msg = ($action) ? $lang['Close topics redirect'] : $lang['Open topics redirect'];
		redirect('moderate.php?fid='.$fid, $redirect_msg);
	}
	// Or just one in $_GET
	else
	{
		confirm_referrer('viewtopic.php');

		$topic_id = ($action) ? intval($_GET['close']) : intval($_GET['open']);
		if ($topic_id < 1)
			message($lang['Bad request'], false, '404 Not Found');

		$db->query('UPDATE '.$db->prefix.'topics SET closed='.$action.' WHERE id='.$topic_id.' AND forum_id='.$fid) or error('Unable to close topic', __FILE__, __LINE__, $db->error());

		$redirect_msg = ($action) ? $lang['Close topic redirect'] : $lang['Open topic redirect'];
		redirect('viewtopic.php?id='.$topic_id, $redirect_msg);
	}
}


// Stick a topic
else if (isset($_GET['stick']))
{
	confirm_referrer('viewtopic.php');

	$stick = intval($_GET['stick']);
	if ($stick < 1)
		message($lang['Bad request'], false, '404 Not Found');

	$db->query('UPDATE '.$db->prefix.'topics SET sticky=\'1\' WHERE id='.$stick.' AND forum_id='.$fid) or error('Unable to stick topic', __FILE__, __LINE__, $db->error());

	redirect('viewtopic.php?id='.$stick, $lang['Stick topic redirect']);
}


// Unstick a topic
else if (isset($_GET['unstick']))
{
	confirm_referrer('viewtopic.php');

	$unstick = intval($_GET['unstick']);
	if ($unstick < 1)
		message($lang['Bad request'], false, '404 Not Found');

	$db->query('UPDATE '.$db->prefix.'topics SET sticky=\'0\' WHERE id='.$unstick.' AND forum_id='.$fid) or error('Unable to unstick topic', __FILE__, __LINE__, $db->error());

	redirect('viewtopic.php?id='.$unstick, $lang['Unstick topic redirect']);
}


// No specific forum moderation action was specified in the query string, so we'll display the moderator forum

// Fetch some info about the forum
$result = $db->query('SELECT f.forum_name, f.redirect_url, f.num_topics, f.sort_by FROM '.$db->prefix.'forums AS f LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id=f.id AND fp.group_id='.$luna_user['g_id'].') WHERE (fp.read_forum IS NULL OR fp.read_forum=1) AND f.id='.$fid) or error('Unable to fetch forum info', __FILE__, __LINE__, $db->error());
if (!$db->num_rows($result))
	message($lang['Bad request'], false, '404 Not Found');

$cur_forum = $db->fetch_assoc($result);

// Is this a redirect forum? In that case, abort!
if ($cur_forum['redirect_url'] != '')
	message($lang['Bad request'], false, '404 Not Found');

switch ($cur_forum['sort_by'])
{
	case 0:
		$sort_by = 'last_post DESC';
		break;
	case 1:
		$sort_by = 'posted DESC';
		break;
	case 2:
		$sort_by = 'subject ASC';
		break;
	default:
		$sort_by = 'last_post DESC';
		break;
}

// Determine the topic offset (based on $_GET['p'])
$num_pages = ceil($cur_forum['num_topics'] / $luna_user['disp_topics']);

$p = (!isset($_GET['p']) || $_GET['p'] <= 1 || $_GET['p'] > $num_pages) ? 1 : intval($_GET['p']);
$start_from = $luna_user['disp_topics'] * ($p - 1);

// Generate paging links
$paging_links = paginate($num_pages, $p, 'moderate.php?fid='.$fid);

$page_title = array(luna_htmlspecialchars($luna_config['o_board_title']), luna_htmlspecialchars($cur_forum['forum_name']));
define('FORUM_ACTIVE_PAGE', 'index');
require FORUM_ROOT.'header.php';

?>
<ul class="breadcrumb">
    <li><a href="index.php"><?php echo $lang['Index'] ?></a></li>
    <li><a href="viewforum.php?id=<?php echo $fid ?>"><?php echo luna_htmlspecialchars($cur_forum['forum_name']) ?></a></li>
    <li class="active"><?php echo $lang['Moderate'] ?></li>
</ul>
<div class="pagepost">
    <ul class="pagination">
        <?php echo $paging_links ?>
    </ul>
</div>

<form method="post" action="moderate.php?fid=<?php echo $fid ?>">
    
<div class="forum-box">
    <div class="row forum-header">
        <div class="col-xs-6"><?php echo $lang['Topic'] ?></div>
        <div class="col-xs-1 hidden-xs"><p class="text-center"><?php echo $lang['Replies forum'] ?></p></div>
        <?php if ($luna_config['o_topic_views'] == '1'): ?>
            <div class="col-xs-1 hidden-xs"><p class="text-center"><?php echo $lang['Views'] ?></p></div>
        <?php endif; ?>
        <div class="col-xs-3 hidden-xs"><?php echo $lang['Last post'] ?></div>
		<div class="col-xs-1"><p class="text-center"><?php echo $lang['Select'] ?></p></div>
    </div>
<?php


// Retrieve a list of topic IDs, LIMIT is (really) expensive so we only fetch the IDs here then later fetch the remaining data
$result = $db->query('SELECT id FROM '.$db->prefix.'topics WHERE forum_id='.$fid.' ORDER BY sticky DESC, '.$sort_by.', id DESC LIMIT '.$start_from.', '.$luna_user['disp_topics']) or error('Unable to fetch topic IDs', __FILE__, __LINE__, $db->error());

// If there are topics in this forum
if ($db->num_rows($result))
{
	$topic_ids = array();
	for ($i = 0;$cur_topic_id = $db->result($result, $i);$i++)
		$topic_ids[] = $cur_topic_id;

	// Select topics
	$result = $db->query('SELECT id, poster, subject, posted, last_post, last_post_id, last_poster, num_views, num_replies, closed, sticky, moved_to FROM '.$db->prefix.'topics WHERE id IN('.implode(',', $topic_ids).') ORDER BY sticky DESC, '.$sort_by.', id DESC') or error('Unable to fetch topic list for forum', __FILE__, __LINE__, $db->error());

	$button_status = '';
	$topic_count = 0;
	while ($cur_topic = $db->fetch_assoc($result))
	{

		++$topic_count;
		$status_text = array();
		$item_status = ($topic_count % 2 == 0) ? 'roweven' : 'rowodd';
		$icon_type = 'icon';

		if (is_null($cur_topic['moved_to']))
		{
			$last_post = '<a href="viewtopic.php?pid='.$cur_topic['last_post_id'].'#p'.$cur_topic['last_post_id'].'">'.format_time($cur_topic['last_post']).'</a> <span class="byuser">'.$lang['by'].' '.luna_htmlspecialchars($cur_topic['last_poster']).'</span>';
			$ghost_topic = false;
		}
		else
		{
			$last_post = '- - -';
			$ghost_topic = true;
		}

		if ($luna_config['o_censoring'] == '1')
			$cur_topic['subject'] = censor_words($cur_topic['subject']);

		if ($cur_topic['sticky'] == '1')
		{
			$item_status .= ' isticky';
			$status_text[] = '<span class="label label-success">'.$lang['Sticky'].'</span>';
		}

		if ($cur_topic['moved_to'] != 0)
		{
			$subject = '<a href="viewtopic.php?id='.$cur_topic['moved_to'].'">'.luna_htmlspecialchars($cur_topic['subject']).'</a> <span class="byuser">'.$lang['by'].' '.luna_htmlspecialchars($cur_topic['poster']).'</span>';
			$status_text[] = '<span class="label label-info">'.$lang['Moved'].'</span>';
			$item_status .= ' imoved';
		}
		else if ($cur_topic['closed'] == '0')
			$subject = '<a href="viewtopic.php?id='.$cur_topic['id'].'">'.luna_htmlspecialchars($cur_topic['subject']).'</a> <span class="byuser">'.$lang['by'].' '.luna_htmlspecialchars($cur_topic['poster']).'</span>';
		else
		{
			$subject = '<a href="viewtopic.php?id='.$cur_topic['id'].'">'.luna_htmlspecialchars($cur_topic['subject']).'</a> <span class="byuser">'.$lang['by'].' '.luna_htmlspecialchars($cur_topic['poster']).'</span>';
			$status_text[] = '<span class="label label-danger">'.$lang['Closed'].'</span>';
			$item_status .= ' iclosed';
		}

		if (!$ghost_topic && $cur_topic['last_post'] > $luna_user['last_visit'] && (!isset($tracked_topics['topics'][$cur_topic['id']]) || $tracked_topics['topics'][$cur_topic['id']] < $cur_topic['last_post']) && (!isset($tracked_topics['forums'][$fid]) || $tracked_topics['forums'][$fid] < $cur_topic['last_post']))
		{
			$item_status .= ' inew';
			$icon_type = 'icon icon-new';
			$subject = '<strong>'.$subject.'</strong>';
			$subject_new_posts = '<span class="newtext">[ <a href="viewtopic.php?id='.$cur_topic['id'].'&amp;action=new" title="'.$lang['New posts info'].'">'.$lang['New posts'].'</a> ]</span>';
		}
		else
			$subject_new_posts = null;

		// Insert the status text before the subject
		$subject = implode(' ', $status_text).' '.$subject;

		$num_pages_topic = ceil(($cur_topic['num_replies'] + 1) / $luna_user['disp_posts']);

		if ($num_pages_topic > 1)
			$subject_multipage = '<span class="inline-pagination"> '.simple_paginate($num_pages_topic, -1, 'viewtopic.php?id='.$cur_topic['id']).'</span>';
		else
			$subject_multipage = null;

		// Should we show the "New posts" and/or the multipage links?
		if (!empty($subject_new_posts) || !empty($subject_multipage))
		{
			$subject .= !empty($subject_new_posts) ? ' '.$subject_new_posts : '';
			$subject .= !empty($subject_multipage) ? ' '.$subject_multipage : '';
		}

?>
    <div class="row topic-row <?php echo $item_status ?>">
        <div class="col-xs-6">
            <div class="<?php echo $icon_type ?>"><div class="nosize"><?php echo forum_number_format($topic_count + $start_from) ?></div></div>
            <div class="tclcon">
                <div>
                    <?php echo $subject."\n" ?>
                </div>
            </div>
        </div>
					<div class="col-xs-1 hidden-xs"><p class="text-center"><?php echo (!$ghost_topic) ? forum_number_format($cur_topic['num_replies']) : '-' ?></p></div>
<?php if ($luna_config['o_topic_views'] == '1'): ?>					<div class="col-xs-1 hidden-xs"><p class="text-center"><?php echo (!$ghost_topic) ? forum_number_format($cur_topic['num_views']) : '-' ?></p></div>
<?php endif; ?>					<div class="col-xs-3 hidden-xs"><?php echo $last_post ?></div>
					<div class="col-xs-1"><p class="text-center"><input type="checkbox" name="topics[<?php echo $cur_topic['id'] ?>]" value="1" /></p></div>
    </div>
<?php

	}
}
else
{
	$colspan = ($luna_config['o_topic_views'] == '1') ? 5 : 4;
	$button_status = ' disabled="disabled"';
	echo "\t\t\t\t\t".'<tr><td class="tcl" colspan="'.$colspan.'">'.$lang['Empty forum'].'</td></tr>'."\n";
}

?>
</div>

<span class="pull-right"><input type="submit" class="btn btn-primary" name="move_topics" value="<?php echo $lang['Move'] ?>"<?php echo $button_status ?> /><input type="submit" class="btn btn-primary" name="delete_topics" value="<?php echo $lang['Delete'] ?>"<?php echo $button_status ?> /><input type="submit" class="btn btn-primary" name="merge_topics" value="<?php echo $lang['Merge'] ?>"<?php echo $button_status ?> /> <input type="submit" class="btn btn-primary" name="open" value="<?php echo $lang['Open'] ?>"<?php echo $button_status ?> /><input type="submit" class="btn btn-primary" name="close" value="<?php echo $lang['Close'] ?>"<?php echo $button_status ?> /></span>
<div class="pagepost">
    <ul class="pagination">
        <?php echo $paging_links ?>
    </ul>
</div>
<ul class="breadcrumb">
    <li><a href="index.php"><?php echo $lang['Index'] ?></a></li>
    <li><a href="viewforum.php?id=<?php echo $fid ?>"><?php echo luna_htmlspecialchars($cur_forum['forum_name']) ?></a></li>
    <li><?php echo $lang['Moderate'] ?></li>
</ul>
</form>
<?php

require FORUM_ROOT.'footer.php';
