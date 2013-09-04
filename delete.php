<?php

/**
 * Copyright (C) 2013 ModernBB
 * Based on code by FluxBB copyright (C) 2008-2012 FluxBB
 * Based on code by Rickard Andersson copyright (C) 2002-2008 PunBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 3 or higher
 */

define('FORUM_ROOT', dirname(__FILE__).'/');
require FORUM_ROOT.'include/common.php';


if ($pun_user['g_read_board'] == '0')
	message($lang_common['No view'], false, '403 Forbidden');


$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id < 1)
	message($lang_common['Bad request'], false, '404 Not Found');

// Fetch some info about the post, the topic and the forum
$result = $db->query('SELECT f.id AS fid, f.forum_name, f.moderators, f.redirect_url, fp.post_replies, fp.post_topics, t.id AS tid, t.subject, t.first_post_id, t.closed, p.posted, p.poster, p.poster_id, p.message, p.hide_smilies FROM '.$db->prefix.'posts AS p INNER JOIN '.$db->prefix.'topics AS t ON t.id=p.topic_id INNER JOIN '.$db->prefix.'forums AS f ON f.id=t.forum_id LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id=f.id AND fp.group_id='.$pun_user['g_id'].') WHERE (fp.read_forum IS NULL OR fp.read_forum=1) AND p.id='.$id) or error('Unable to fetch post info', __FILE__, __LINE__, $db->error());
if (!$db->num_rows($result))
	message($lang_common['Bad request'], false, '404 Not Found');

$cur_post = $db->fetch_assoc($result);

if ($pun_config['o_censoring'] == '1')
	$cur_post['subject'] = censor_words($cur_post['subject']);

// Sort out who the moderators are and if we are currently a moderator (or an admin)
$mods_array = ($cur_post['moderators'] != '') ? unserialize($cur_post['moderators']) : array();
$is_admmod = ($pun_user['g_id'] == FORUM_ADMIN || ($pun_user['g_moderator'] == '1' && array_key_exists($pun_user['username'], $mods_array))) ? true : false;

$is_topic_post = ($id == $cur_post['first_post_id']) ? true : false;

// Do we have permission to edit this post?
if (($pun_user['g_delete_posts'] == '0' ||
	($pun_user['g_delete_topics'] == '0' && $is_topic_post) ||
	$cur_post['poster_id'] != $pun_user['id'] ||
	$cur_post['closed'] == '1') &&
	!$is_admmod)
	message($lang_common['No permission'], false, '403 Forbidden');
	
if ($is_admmod && $pun_user['g_id'] != FORUM_ADMIN && in_array($cur_post['poster_id'], get_admin_ids()))
	message($lang_common['No permission'], false, '403 Forbidden');  


// Load the frontend.php language file
require FORUM_ROOT.'lang/'.$pun_user['language'].'/frontend.php';


if (isset($_POST['delete']))
{
	require FORUM_ROOT.'include/search_idx.php';

	if ($is_topic_post)
	{
		// Delete the topic and all of its posts
		delete_topic($cur_post['tid']);
		update_forum($cur_post['fid']);

		redirect('viewforum.php?id='.$cur_post['fid'], $lang_front['Topic del redirect']);
	}
	else
	{
		// Delete just this one post
		delete_post($id, $cur_post['tid']);
		update_forum($cur_post['fid']);

		// Redirect towards the previous post
		$result = $db->query('SELECT id FROM '.$db->prefix.'posts WHERE topic_id='.$cur_post['tid'].' AND id < '.$id.' ORDER BY id DESC LIMIT 1') or error('Unable to fetch post info', __FILE__, __LINE__, $db->error());
		$post_id = $db->result($result);

		redirect('viewtopic.php?pid='.$post_id.'#p'.$post_id, $lang_front['Post del redirect']);
	}
}


$page_title = array(pun_htmlspecialchars($pun_config['o_board_title']), $lang_front['Delete post']);
define ('FORUM_ACTIVE_PAGE', 'index');
require FORUM_ROOT.'header.php';

require FORUM_ROOT.'include/parser.php';
$cur_post['message'] = parse_message($cur_post['message'], $cur_post['hide_smilies']);

?>
<ul class="breadcrumb">
    <li><a href="index.php"><?php echo $lang_common['Index'] ?></a></li>
    <li><a href="viewforum.php?id=<?php echo $cur_post['fid'] ?>"><?php echo pun_htmlspecialchars($cur_post['forum_name']) ?></a></li>
    <li><a href="viewtopic.php?pid=<?php echo $id ?>#p<?php echo $id ?>"><?php echo pun_htmlspecialchars($cur_post['subject']) ?></a></li>
    <li class="active"><?php echo $lang_front['Delete post'] ?></li>
</ul>

<div class="panel panel-default">
    <div class="panel-heading">
        <h3 class="panel-title"><?php printf($is_topic_post ? $lang_front['Topic by'] : $lang_front['Reply by'], '<strong>'.pun_htmlspecialchars($cur_post['poster']).'</strong>', format_time($cur_post['posted'])) ?></h3>
    </div>
	<div class="panel-body">
		<form method="post" action="delete.php?id=<?php echo $id ?>">
            <p><?php echo ($is_topic_post) ? '<strong>'.$lang_front['Topic warning'].'</strong>' : '<strong>'.$lang_front['Warning'].'</strong>' ?><br /><?php echo $lang_front['Delete info'] ?></p>
			<div><input type="submit" class="btn btn-danger" name="delete" value="<?php echo $lang_front['Delete'] ?>" /> <a href="javascript:history.go(-1)" class="btn btn-default"><?php echo $lang_common['Go back'] ?></a></div>
		</form>
	</div>
</div>

<div class="panel panel-default">
    <div class="panel-heading">
        <h3 class="panel-title"><?php echo pun_htmlspecialchars($cur_post['poster']) ?></h3>
    </div>
	<div class="panel-body">
		<?php echo $cur_post['message'] ?>
    </div>
</div>
<?php

require FORUM_ROOT.'footer.php';
