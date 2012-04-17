<?php defined('SYSPATH') OR die('No direct access allowed.');

/**
 * River Controller
 *
 * PHP version 5
 * LICENSE: This source file is subject to GPLv3 license 
 * that is available through the world-wide-web at the following URI:
 * http://www.gnu.org/copyleft/gpl.html
 * @author	   Ushahidi Team <team@ushahidi.com> 
 * @package	   SwiftRiver - http://github.com/ushahidi/Swiftriver_v2
 * @subpackage Controllers
 * @copyright  Ushahidi - http://www.ushahidi.com
 * @license	   http://www.gnu.org/copyleft/gpl.html GNU General Public License v3 (GPLv3) 
 */
class Controller_River extends Controller_Swiftriver {

	/**
	 * Channels
	 */
	protected $channels;
	
	/**
	 * ORM reference for the currently selected river
	 * @var Model_River
	 */
	protected $river;
	
	/**
	 * Boolean indicating whether the logged in user owns the river
	 * @var bool
	 */
	protected $owner = FALSE; 

	/**
	 * Whether the river is newly created
	 * @var bool
	 */
	protected $is_newly_created = FALSE;
	
	/**
	 * Base URL for this river.
	 */
	protected $river_base_url = NULL;

	/**
	 * @return	void
	 */
	public function before()
	{
		// Execute parent::before first
		parent::before();
		
		// Get the river name from the url
		$river_name_url = $this->request->param('name');
		
		// This check should be made when this controller is accessed
		// and the database id of the rive is non-zero
		$this->river = ORM::factory('river')
			->where('river_name_url', '=', $river_name_url)
			->where('account_id', '=', $this->visited_account->id)
			->find();
		
		// Action involves a specific river, check permissions
		if ($river_name_url)
		{
			if ( ! $this->river->loaded())
			{
				// Redirect to the dashboard
				$this->request->redirect($this->dashboard_url);
			}
					
			// Is the logged in user an owner
			if ($this->river->is_owner($this->user->id)) 
			{
				$this->owner = TRUE;
			}
			
			// If this river is not public and no ownership...
			if( ! $this->river->river_public AND ! $this->owner)
			{
				$this->request->redirect($this->dashboard_url);			
			}

			// Set the base url for this specific river
			$this->river_base_url = $this->river->get_base_url();

			// Settings url
			$this->settings_url = $this->river_base_url.'/settings';

			// Navigation Items
			$this->nav = Swiftriver_Navs::river($this->river);

			// Store the id of the current river in session - for search
			Session::instance()->set('search_river_id', $this->river->id);
		}
	}

	/**
	 * @return	void
	 */
	public function action_index()
	{
		// Get the id of the current river
		$river_id = $this->river->id;
		
		if ($this->river->account->user->id == $this->user->id OR $this->river->account->user->username == 'public')
		{
			$this->template->header->title = $this->river->river_name;
		}
		else
		{
			$this->template->header->title = $this->river->account->account_path.' / '.$this->river->river_name;
		}
				
		$this->template->content = View::factory('pages/river/layout')
			->bind('river', $this->river)
			->bind('sub_content', $droplets_view)
			->bind('river_base_url', $this->river_base_url)
			->bind('settings_url', $this->settings_url)
			->bind('owner', $this->owner)
			->bind('user', $this->user)
			->bind('nav', $this->nav);
				
		// The maximum droplet id for pagination and polling
		$max_droplet_id = Model_River::get_max_droplet_id($river_id);
		
		// River filters
		$filters = $this->_get_filters();
				
		//Get Droplets
		$droplets_array = Model_River::get_droplets($this->user->id, $river_id, 0, 1, 
			$max_droplet_id, NULL, $filters);
		
		// Total Droplets Before Filtering
		$total = $droplets_array['total'];
		
		// The Droplets
		$droplets = $droplets_array['droplets'];
		
		// Total Droplets After Filtering
		$filtered_total = count($droplets);
				
		// Bootstrap the droplet list
		$droplet_js = View::factory('pages/drop/js/drops');
		$droplet_js->fetch_base_url = $this->river_base_url;
		$droplet_js->droplet_list = json_encode($droplets);
		$droplet_js->max_droplet_id = $max_droplet_id;
		$droplet_js->user = $this->user;
		$droplet_js->bucket_list = json_encode($this->user->get_buckets_array());
		$droplet_js->channels = json_encode($this->river->get_channels());
		$droplet_js->polling_enabled = TRUE;
		$droplet_js->default_view = $this->river->default_layout;
		
		// Check if any filters exist and modify the fetch urls
		$droplet_js->filters = NULL;
		if ( ! empty($filters))
		{
			$droplet_js->filters = json_encode($filters);
		}
		
		// Select droplet list view with drops view as the default if list not specified
		$droplets_view = View::factory('pages/drop/drops')
		    ->bind('droplet_js', $droplet_js)
		    ->bind('user', $this->user)
		    ->bind('owner', $this->owner)
		    ->bind('anonymous', $this->anonymous);
		$droplets_view->nothing_to_display = View::factory('pages/river/nothing_to_display')
		    ->bind('anonymous', $this->anonymous);
		$droplets_view->nothing_to_display->river_url = $this->request->url(TRUE);
	}
	
	/**
	* Below are aliases for the index.
	*/
	public function action_drops()
	{
		$this->action_index();
	}
	
	public function action_list()
	{
		$this->action_index();
	}

	public function action_drop()
	{
		$this->action_index();
	}

	
	/**
	 * XHR endpoint for fetching droplets
	 */
	public function action_droplets()
	{
		$this->template = "";
		$this->auto_render = FALSE;

		switch ($this->request->method())
		{
			case "GET":
				$drop_id = $this->request->param('id');
				$page = 1;
				if ($drop_id)
				{
					// Specific drop requested
					$droplets_array = Model_River::get_droplets($this->user->id, 
				    	$this->river->id, $drop_id);
					$droplets = array_pop($droplets_array['droplets']);
				}
				else
				{
					//Use page paramter or default to page 1
					$page = $this->request->query('page') ? intval($this->request->query('page')) : 1;
					$max_id = $this->request->query('max_id') ? intval($this->request->query('max_id')) : PHP_INT_MAX;
					$since_id = $this->request->query('since_id') ? intval($this->request->query('since_id')) : 0;
					$filters = $this->_get_filters();

					if ($since_id)
					{
					    $droplets_array = Model_River::get_droplets_since_id($this->user->id, 
					    	$this->river->id, $since_id, $filters);
					}
					else
					{
					    $droplets_array = Model_River::get_droplets($this->user->id, 
					    	$this->river->id, 0, $page, $max_id, NULL, $filters);
					}
					$droplets = $droplets_array['droplets'];
				}				
				

				//Throw a 404 if a non existent page/drop is requested
				if (($page > 1 OR $drop_id) AND empty($droplets))
				{
				    throw new HTTP_Exception_404('The requested page was not found on this server.');
				}
				

				echo json_encode($droplets);

			break;
			
			case "PUT":
				// No anonymous actions
				if ($this->anonymous)
				{
					throw new HTTP_Exception_403();
				}
			
				$droplet_array = json_decode($this->request->body(), TRUE);
				$droplet_id = intval($this->request->param('id', 0));
				$droplet_orm = ORM::factory('droplet', $droplet_id);
				$droplet_orm->update_from_array($droplet_array);
			break;
			
			case "DELETE":
				$droplet_id = intval($this->request->param('id', 0));
				$droplet_orm = ORM::factory('droplet', $droplet_id);
				
				// Does the user exist
				if ( ! $droplet_orm->loaded())
				{
					throw new HTTP_Exception_404(
				        'The requested page :page was not found on this server.',
				        array(':page' => $page)
				        );
				}
				
				// Is the logged in user an owner?
				if ( ! $this->owner)
				{
					throw new HTTP_Exception_403();
				}
				
				ORM::factory('river', $this->river->id)->remove('droplets', $droplet_orm);
			break;
		}
	}


	
	/**
	 * River collaborators restful api
	 * 
	 * @return	void
	 */
	public function action_collaborators()
	{
		$this->template = '';
		$this->auto_render = FALSE;
		
		// No anonymous here
		if ($this->anonymous)
		{
			throw new HTTP_Exception_403();
		}
		
		$query = $this->request->query('q') ? $this->request->query('q') : NULL;
		
		if ($query)
		{
			echo json_encode(Model_User::get_like($query, array($this->user->id, $this->river->account->user->id)));
			return;
		}
		
		switch ($this->request->method())
		{
			case "DELETE":
				// Is the logged in user an owner?
				if ( ! $this->owner)
				{
					throw new HTTP_Exception_403();
				}
							
				$user_id = intval($this->request->param('id', 0));
				$user_orm = ORM::factory('user', $user_id);
				
				if ( ! $user_orm->loaded()) 
					return;
					
				$collaborator_orm = $this->river->river_collaborators->where('user_id', '=', $user_orm->id)->find();
				if ($collaborator_orm->loaded())
				{
					$collaborator_orm->delete();
					Model_User_Action::delete_invite($this->user->id, 'river', $this->river->id, $user_orm->id);
				}
			break;
			
			case "PUT":
				// Is the logged in user an owner?
				if ( ! $this->owner)
				{
					throw new HTTP_Exception_403();
				}
			
				$user_id = intval($this->request->param('id', 0));
				$user_orm = ORM::factory('user', $user_id);
				
				$collaborator_orm = ORM::factory("river_collaborator")
									->where('river_id', '=', $this->river->id)
									->where('user_id', '=', $user_orm->id)
									->find();
				
				if ( ! $collaborator_orm->loaded())
				{
					$collaborator_orm->river = $this->river;
					$collaborator_orm->user = $user_orm;
					$collaborator_orm->save();
					Model_User_Action::create_action($this->user->id, 'river', $this->river->id, $user_orm->id);
				}				
			break;
		}
	}
	
	 /**
	  * Tags restful api
	  */ 
	 public function action_tags()
	{
		$this->template = "";
		$this->auto_render = FALSE;
		
		$droplet_id = intval($this->request->param('id', 0));
		$tag_id = intval($this->request->param('id2', 0));
		
		switch ($this->request->method())
		{
			case "POST":
				// Is the logged in user an owner?
				if ( ! $this->owner)
				{
					throw new HTTP_Exception_403();
				}
				
				$tag_array = json_decode($this->request->body(), true);
				$tag_name = $tag_array['tag'];
				$account_id = $this->visited_account->id;
				$tag_orm = Model_Account_Droplet_Tag::get_tag($tag_name, $droplet_id, $account_id);
				echo json_encode(array('id' => $tag_orm->tag->id, 'tag' => $tag_orm->tag->tag));
			break;

			case "DELETE":
				// Is the logged in user an owner?
				if ( ! $this->owner)
				{
					throw new HTTP_Exception_403();
				}
				
				Model_Droplet::delete_tag($droplet_id, $tag_id, $this->visited_account->id);
			break;
		}
	}
	
	/**
	  * Links restful api
	  */ 
	 public function action_links()
	{
		// Is the logged in user an owner?
		if ( ! $this->owner)
		{
			throw new HTTP_Exception_403();
		}
		
		$this->template = "";
		$this->auto_render = FALSE;
		
		$droplet_id = intval($this->request->param('id', 0));
		$link_id = intval($this->request->param('id2', 0));
		
		switch ($this->request->method())
		{
			case "POST":
				$link_array = json_decode($this->request->body(), true);
				$url = $link_array['url'];
				if ( ! Valid::url($url))
				{
					$this->response->status(400);
					$this->response->headers('Content-Type', 'application/json');
					$errors = array(__("Invalid url"));
					echo json_encode(array('errors' => $errors));
					return;
				}
				$account_id = $this->visited_account->id;
				$link_orm = Model_Account_Droplet_Link::get_link($url, $droplet_id, $account_id);
				echo json_encode(array('id' => $link_orm->link->id, 'tag' => $link_orm->link->url));
			break;

			case "DELETE":
				Model_Droplet::delete_link($droplet_id, $link_id, $this->visited_account->id);
			break;
		}
	}
	
	/**
	  * Links restful api
	  */ 
	 public function action_places()
	{
		// Is the logged in user an owner?
		if ( ! $this->owner)
		{
			throw new HTTP_Exception_403();
		}
		
		$this->template = "";
		$this->auto_render = FALSE;
		
		$droplet_id = intval($this->request->param('id', 0));
		$place_id = intval($this->request->param('id2', 0));
		
		switch ($this->request->method())
		{
			case "POST":
				$places_array = json_decode($this->request->body(), true);
				$place_name = $places_array['place_name'];
				if ( ! Valid::not_empty($place_name))
				{
					$this->response->status(400);
					$this->response->headers('Content-Type', 'application/json');
					$errors = array(__("Invalid location"));
					echo json_encode(array('errors' => $errors));
					return;
				}
				$account_id = $this->visited_account->id;
				$place_orm = Model_Account_Droplet_Place::get_place($place_name, $droplet_id, $account_id);
				echo json_encode(array('id' => $place_orm->place->id, 'place_name' => $place_orm->place->place_name));
			break;

			case "DELETE":
				Model_Droplet::delete_place($droplet_id, $place_id, $this->visited_account->id);
			break;
		}
	}
	
	 /**
	  * Replies restful api
	  */ 
	 public function action_reply()
	{
		$this->template = "";
		$this->auto_render = FALSE;
		
		$droplet_id = intval($this->request->param('id', 0));
		
		switch ($this->request->method())
		{
			case "POST":
				// Is the logged in user an owner?
				if ( ! $this->owner)
				{
					throw new HTTP_Exception_403();
				}
				
				// Get the POST data
				$droplet = json_decode($this->request->body(), TRUE);
				
				// Set the remaining properties
				$droplet['parent_id'] = intval($this->request->param('id', 0));
				$droplet['droplet_type'] = 'reply';
				$droplet['channel'] = 'swiftriver';
				$droplet['droplet_title'] = $droplet['droplet_content'];
				$droplet['droplet_date_pub'] = gmdate('Y-m-d H:i:s', time());
				$droplet['droplet_orig_id'] = 0;
				$droplet['droplet_locale'] = 'en';
				$droplet['identity_orig_id'] = $this->user->id;
				$droplet['identity_username'] = $this->user->username;
				$droplet['identity_name'] = $this->user->name;
				$droplet['identity_avatar'] = Swiftriver_Users::gravatar($this->user->email, 80);
				// Set the river id
				$droplet['river_id'] = $this->river->id;
				// Add the droplet to the queue
				$droplet_orm = Swiftriver_Dropletqueue::add($droplet);
				
				if ($droplet_orm) 
				{
					echo json_encode(array(
						'id' => $droplet_orm->id,
						'channel' => $droplet['channel'],
						'identity_avatar' => $droplet['identity_avatar'],
						'identity_name' => $droplet['identity_name'],
						'droplet_date_pub' => gmdate('M d, Y H:i:s', time()).' UTC',
						'droplet_content' => $droplet['droplet_content']
					));
				}
				else
				{
					$this->response->status(400);
				}
			break;
		}
	}
	
	
	/**
	 * Return filter parameters as a hash array
	 */
	private function _get_filters()
	{
		$filters = array();
		$parameters = array('tags', 'channel', 'start_date', 'end_date');
		
		foreach ($parameters as $parameter)
		{
			$values = $this->request->query($parameter);
			if ($values) {
				$filters[$parameter] = array();				
				// Parameters are array strings that are comma delimited
				// The below converts them into a php array, trimming each
				// value
				foreach (explode(',', urldecode($values)) as $value) {
					$filters[$parameter][] = trim($value);
				}
			}
		}
		
		return $filters;
	}
}