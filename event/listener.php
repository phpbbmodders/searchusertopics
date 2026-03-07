<?php
/**
*
* Search User Topics extension for the phpBB Forum Software package.
*
* @copyright (c) 2015 Rich McGirr (RMcGirr83)
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

namespace rmcgirr83\searchusertopics\event;

/**
* @ignore
*/
use phpbb\auth\auth;
use phpbb\cache\service as cache;
use phpbb\config\config;
use phpbb\db\driver\driver_interface;
use phpbb\language\language;
use phpbb\template\template;
use phpbb\user;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
* Event listener
*/
class listener implements EventSubscriberInterface
{
	/** @var auth */
	protected $auth;

	/** @var cache */
	protected $cache;

	/** @var config */
	protected $config;

	/** @var driver_interface */
	protected $db;

	/** @var language */
	protected $language;

	/** @var template */
	protected $template;

	/** @var user */
	protected $user;

	/** @var string phpBB root path */
	protected $root_path;

	/** @var string phpEx */
	protected $php_ext;

	public function __construct(
		auth $auth,
		cache $cache,
		config $config,
		driver_interface $db,
		language $language,
		template $template,
		user $user,
		$root_path,
		$php_ext)
	{
		$this->auth = $auth;
		$this->cache = $cache;
		$this->config = $config;
		$this->db = $db;
		$this->language = $language;
		$this->template = $template;
		$this->user = $user;
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;
	}

	/**
	* Assign functions defined in this class to event listeners in the core
	*
	* @return array
	* @static
	* @access public
	*/
	static public function getSubscribedEvents()
	{
		return array(
			'core.acp_extensions_run_action_after'	=>	'acp_extensions_run_action_after',
			'core.memberlist_view_profile'			=> 'memberlist_view_profile',
			'core.page_header_after'	=> 'page_header_after',
			'core.submit_post_end'		=> 'submit_post_end',
			'core.delete_post_after'	=> 'delete_post_after',
			'core.viewtopic_cache_user_data'			=> 'viewtopic_cache_user_data',
			'core.viewtopic_cache_guest_data'			=> 'viewtopic_cache_guest_data',
			'core.viewtopic_modify_post_row'			=> 'viewtopic_modify_post_row',
		);
	}

	/**
	* Display topics search for the user in quick links
	*
	* @param object $event The event object
	* @return null
	* @access public
	*/
	public function page_header_after($event): void
	{
		$this->language->add_lang('common', 'rmcgirr83/searchusertopics');
		$user_id = $this->user->data['user_id'];
		$this->template->assign_vars(array(
			'U_SEARCH_TOPICS'	=> ($this->auth->acl_get('u_search')) ? append_sid("{$this->root_path}search.$this->php_ext", "author_id=$user_id&amp;sr=topics&amp;sf=firstpost") : '',
		));

		if ($this->cache->get('_searchusertopics') == false)
		{
			$this->build_cache();
		}
	}

	/* Display additional metdate in extension details
	*
	* @param $event			event object
	* @param return null
	* @access public
	*/
	public function acp_extensions_run_action_after($event): void
	{
		if ($event['ext_name'] == 'rmcgirr83/searchusertopics' && $event['action'] == 'details')
		{
			$this->language->add_lang('common', $event['ext_name']);
			$this->template->assign_var('S_BUY_ME_A_BEER_SUT', true);
		}
	}

	/**
	* Display number of topics on viewing user profile
	*
	* @param object $event The event object
	* @return null
	* @access public
	*/
	public function memberlist_view_profile($event): void
	{
		$user_id = $event['member']['user_id'];
		$reg_date = $event['member']['user_regdate'];
		$this->language->add_lang('common', 'rmcgirr83/searchusertopics');

		// get all topics started by the user and make sure they are visible
		$sql = 'SELECT t.*, p.post_visibility
			FROM ' . TOPICS_TABLE . ' t
			LEFT JOIN ' . POSTS_TABLE . ' p ON t.topic_first_post_id = p.post_id
			WHERE t.topic_poster = ' . (int) $user_id;
		$result = $this->db->sql_query($sql);

		$topics_num = 0;
		while ($row = $this->db->sql_fetchrow($result))
		{
			if ($row['topic_status'] == ITEM_MOVED)
			{
				continue;
			}
			if (!$this->auth->acl_get('f_read', $row['forum_id']))
			{
				continue;
			}
			if ($row['post_visibility'] != ITEM_APPROVED && !$this->auth->acl_get('m_approve', $row['forum_id']))
			{
				continue;
			}
			++$topics_num;
		}
		$this->db->sql_freeresult($result);

		if ($topics_num)
		{
			// Do the relevant calculations
			$users_days = max(1, round((time() - $reg_date) / 86400));
			$topics_per_day = $topics_num / $users_days;
			$topics_percent = ($this->config['num_topics']) ? min(100, ($topics_num / $this->config['num_topics']) * 100) : 0;
			$this->template->assign_vars(array(
				'TOPICS'			=> $topics_num,
				'L_TOTAL_TOPICS'	=> $this->language->lang('TOTAL_TOPICS', $topics_num),
				'TOPICS_PER_DAY'	=> $this->language->lang('TOPICS_PER_DAY', $topics_per_day),
				'TOPICS_PERCENT'	=> $this->language->lang('TOPICS_PERCENT', $topics_percent),
				'U_SEARCH_USER_TOPICS'	=> ($this->auth->acl_get('u_search')) ? append_sid("{$this->root_path}search.$this->php_ext", "author_id=$user_id&amp;sr=topics&amp;sf=firstpost") : '',
			));
		}
	}

	/**
	* Build a cache of user topic counts for displaying through out forum
	* This is only run when mode equals post
	* @param object $event The event object
	* @return null
	* @access public
	*/
	public function submit_post_end($event): void
	{
		$mode = $event['mode'];

		if ($mode == 'post')
		{
			$this->build_cache();
		}
	}

	/**
	* Rebuild the cache if topics/posts are deleted
	*
	* @param object $event The event object
	* @return null
	* @access public
	*/
	public function delete_post_after($event): void
	{
		$this->build_cache();
	}

	/**
	 * Update viewtopic user data
	 *
	 * @param object $event The event object
	 * @return null
	 * @access public
	 */
	public function viewtopic_cache_user_data($event)
	{
		$user_topic_counts = $this->cache->get('_searchusertopics');

		$user_id = $event['poster_id'];
		$array = $event['user_cache_data'];
		$array['user_topics_count'] = !empty($user_topic_counts[$user_id]) ? $user_topic_counts[$user_id] : '';

		$event['user_cache_data'] = $array;
	}

	/**
	 * Update viewtopic guest data
	 *
	 * @param object $event The event object
	 * @return null
	 * @access public
	 */
	public function viewtopic_cache_guest_data($event)
	{
		$array = $event['user_cache_data'];
		$array['user_topics_count'] = 0;
		$event['user_cache_data'] = $array;
	}

	/**
	 * Modify the viewtopic post row
	 *
	 * @param object $event The event object
	 * @return null
	 * @access public
	 */
	public function viewtopic_modify_post_row($event)
	{
		$user_id = $event['poster_id'];

		$event['post_row'] = array_merge($event['post_row'], [
			'TOPICS_COUNT' => !empty($event['user_poster_data']['user_topics_count']) ? $event['user_poster_data']['user_topics_count'] : '',
			'U_SEARCH_USER_TOPICS'	=> ($this->auth->acl_get('u_search')) ? append_sid("{$this->root_path}search.$this->php_ext", "author_id=$user_id&amp;sr=topics&amp;sf=firstpost") : '',
		]);
	}

	/* this function is to build the cache of a count of topics started by users_days
	 * it is only used if the cache doesn't exist due to a purge, or if a new topic is posted
	 *
	 * @return null
	 * @access private
	 */
	private function build_cache(): void
	{
		$user_topic_count = [];

		$sql = 'SELECT topic_poster, COUNT(topic_id) AS numberoftopics
			FROM ' . TOPICS_TABLE . '
			WHERE topic_visibility = ' . ITEM_APPROVED . '
			GROUP BY topic_poster';
		$result = $this->db->sql_query($sql);

		while ($row = $this->db->sql_fetchrow($result))
		{
			$user_topic_count[$row['topic_poster']] = $row['numberoftopics'];
		}
		$this->db->sql_freeresult($result);

		$this->cache->put('_searchusertopics', $user_topic_count);
	}
}
