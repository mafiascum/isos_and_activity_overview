<?php

use \Symfony\Component\HttpFoundation\Response;

use \mafiascum\privatetopics\Utils;

namespace mafiascum\isos\controller;

class main
{
    /* @var \phpbb\config\config */
    protected $config;

    /* @var \phpbb\controller\helper */
    protected $helper;

    /* @var \phpbb\language\language */
    protected $language;

    /* @var \phpbb\template\template */
    protected $template;

    /* @var \phpbb\db\driver\driver */
	protected $db;

    /* @var \phpbb\user */
	protected $user;

    /* @var \phpbb\auth\auth */
    protected $auth;

    /* @var \phpbb\request\request */
    protected $request;

    /**
     * Constructor
     *
     * @param \phpbb\config\config      $config
     * @param \phpbb\controller\helper  $helper
     * @param \phpbb\language\language  $language
     * @param \phpbb\template\template  $template
     * @param \phpbb\db\driver\driver   $db
     * @param \phpbb\user               $user
     * @param \phpbb\auth\auth          $auth
     * @param \phpbb\request\request    $request
     */
    public function __construct(\phpbb\config\config $config, \phpbb\controller\helper $helper, \phpbb\language\language $language, \phpbb\template\template $template,	\phpbb\db\driver\driver_interface $db, \phpbb\user $user, \phpbb\auth\auth $auth, \phpbb\request\request $request)
    {
        $this->config   = $config;
        $this->helper   = $helper;
        $this->language = $language;
        $this->template = $template;
        $this->db       = $db;
        $this->user     = $user;
        $this->auth     = $auth;
        $this->request  = $request;

        $this->sort_type_map = array(
            'pc' => 'count(*)',
            'fpt' => 'min(p.post_time)',
            'pt' => 'max(p.post_time)',
            'un' => 'lower(u.username)',
        );
    }

    public function assign_template_for_topic_post_count($topic_id, $sort_type_sql, $sort_order_sql)
    {
		global $phpbb_root_path, $phpEx;
        $sql = "SELECT count(*) count, p.poster_id, u.username, u.user_colour, Coalesce(ppfo.lang_value, 'Unspecified') as pronoun, min(p.post_time) first_post_time, max(p.post_time) last_post_time"
               . " FROM " . POSTS_TABLE . " p"
               . " JOIN " . USERS_TABLE . " u"
               . " ON p.poster_id = u.user_id"
               . " LEFT JOIN " . PROFILE_FIELDS_DATA_TABLE . " ppfd ON u.user_id = ppfd.user_id"
			   . " LEFT JOIN (SELECT ppfl.option_id, ppfl.lang_value FROM " . PROFILE_FIELDS_TABLE . " ppf JOIN " . PROFILE_FIELDS_LANG_TABLE . " ppfl ON ppf.field_id = ppfl.field_id WHERE ppf.field_ident = 'user_pronoun') ppfo"
			   . " ON ppfd.pf_user_pronoun = ppfo.option_id + 1"
               . " WHERE p.topic_id = " . $topic_id
               . " GROUP BY p.poster_id, u.username"
               . " ORDER BY " . $sort_type_sql . " " . $sort_order_sql;

        $result = $this->db->sql_query($sql);
		
        while ($row = $this->db->sql_fetchrow($result))
        {
            $daysSince = (int) ((time() - $row['last_post_time']) / 60 / 60 / 24);
            $hoursSince = (int) ((time() - $row['first_post_time']) / 60 / 60) % 24;
			$idleTime = "$daysSince day" . ($daysSince==1?"":"s") . " $hoursSince hour" . ($hoursSince==1?"":"s");
			$poster_id = $row['poster_id'];
			$isoUrl = append_sid("{$phpbb_root_path}viewtopic.{$phpEx}", "t={$topic_id}&user_select%5B%5D={$poster_id}");

            $this->template->assign_block_vars('POSTS_BY_USER', array(
                'COUNT' => $row['count'],
                'POSTER_ID' => $row['poster_id'],
                'USERNAME' => get_username_string('full', $row['poster_id'], $row['username'] . " (" . $row['pronoun'] . ")", $row['user_colour']),
                'FIRST_POST_TIME' => $this->user->format_date($row['first_post_time']),
                'LAST_POST_TIME' => $this->user->format_date($row['last_post_time']),
				'IDLE_TIME' => $idleTime,
				'ISO_URL' => $isoUrl,
            ));
        }
        $this->db->sql_freeresult($result);
    }

    public function determine_sort_order($current_sort_type, $current_sort_order, $new_sort_type) {
        if ($current_sort_type != $new_sort_type) {
            return $current_sort_order;
        } else {
            return $current_sort_order == 'd' ? 'a' : 'd';
        }
    }
    /**
     * Controller for activity overview
     *
     * @param string $topic_id
     * @param string $sort_type
     * @param string $sort_order
     * @throws \phpbb\exception\http_exception
     * @return \Symfony\Component\HttpFoundation\Response A Symfony Response object
     */
    public function handle($topic_id)
    {
        global $table_prefix;
        
        $sort_type = $this->request->variable('sort_type', 'pt');
        $sort_order = $this->request->variable('sort_order', 'a');

        $sort_type_sql = $this->sort_type_map[$sort_type] ?? 'max(p.post_time)';
        $sort_order_sql = $sort_order == 'd' ? 'DESC' : 'ASC';

        if (!$topic_id) {
            trigger_error('NO_TOPIC');
        }

        $sql = 'SELECT forum_id FROM ' . $table_prefix . 'topics
                WHERE topic_id = ' . $topic_id;

        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);

        $forum_id = $row['forum_id'];

        if (!$forum_id) {
            trigger_error('NO_TOPIC');
        }

        if (!\mafiascum\privatetopics\Utils::is_user_authorized_for_topic(
            $this->db,
            $this->auth,
            $this->user->data['user_id'],
            $forum_id,
            $topic_id
        )) {
            trigger_error('NO_TOPIC');
        }

        $this->assign_template_for_topic_post_count($topic_id, $sort_type_sql, $sort_order_sql);
        $this->template->assign_vars(array(
            'TOPIC_ID'  => $topic_id,
            'PC_LINK_SORT_ORDER' => $this->determine_sort_order($sort_type, $sort_order, 'pc'),
            'FPT_LINK_SORT_ORDER' => $this->determine_sort_order($sort_type, $sort_order, 'fpt'),
            'PT_LINK_SORT_ORDER' => $this->determine_sort_order($sort_type, $sort_order, 'pt'),
            'UN_LINK_SORT_ORDER' => $this->determine_sort_order($sort_type, $sort_order, 'un'),
            'U_ACTIVITY_OVERVIEW' => $this->helper->route('activity_overview_route', array('topic_id' => $topic_id)),
        ));

        return $this->helper->render('activity_overview.html', $name);
    }
}
