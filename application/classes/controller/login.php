<?php defined('SYSPATH') OR die('No direct access allowed.');

/**
 * Login Controller
 *
 * PHP version 5
 * LICENSE: This source file is subject to GPLv3 license 
 * that is available through the world-wide-web at the following URI:
 * http://www.gnu.org/copyleft/gpl.html
 * @author	   Ushahidi Team <team@ushahidi.com> 
 * @package	   SwiftRiver - http://github.com/ushahidi/Swiftriver_v2
 * @category Controllers
 * @copyright  Ushahidi - http://www.ushahidi.com
 * @license	   http://www.gnu.org/copyleft/gpl.html GNU General Public License v3 (GPLv3) 
 */
class Controller_Login extends Controller_Swiftriver {
	
	/**
	 * @var Kohana_View
	 */
	private $sub_content;

	/**
	 * Stores the status of public registration (on|off)
	 * @var int
	 */
	private $public_registration_enabled;

	/**
	 * The before() method is called before main controller action.
	 * In our template controller we override this method so that we can
	 * set up default values. These variables are then available to our
	 * controllers if they need to be modified.
	 *
	 * @return	void
	 */
	public function before()
	{
		// Execute parent::before first
		parent::before();
		
		if (strtolower(Kohana::$config->load('auth.driver')) == 'riverid') 
		{
			$this->riverid_auth = TRUE;
		}

		$this->template->content = View::factory('pages/login/layout')
		    ->bind('sub_content', $this->sub_content)
		    ->bind('public_registration_enabled', $this->public_registration_enabled);

		$this->public_registration_enabled = Model_Setting::get_setting('public_registration_enabled');
		$this->template->content->active = 'login';
	}
	
	/**
	 * Log User In
	 * 
	 * @return void
	 */	
	public function action_index()
	{
		$this->sub_content = View::factory('pages/login/main')
		    ->bind('messages', $this->messages)
		    ->bind('referrer', $referrer);

		if ($this->user)
		{
			$this->request->redirect($this->dashboard_url);
		}

		// Get the referriing URL
		$referrer = $this->request->query('redirect_to') 
		    ? $this->request->query('redirect_to') 
		    : NULL;

		//Check for system messages
		$session = Session::instance();
		$messages = $session->get_once('system_messages');
		if ($messages)
		{
			$this->messages = $messages;
		}
		
		$errors = $session->get_once('system_errors');
		if ($errors)
		{
			$this->template->content->set('errors', $errors);
		}
		
		
		// New user registration
		if
		(
			$this->request->post('new_email') AND 
			CSRF::valid($this->request->post('form_auth_id'))
		)
		{
			$messages = $this->_new_user($this->request->post('new_email'));
			
			// Display the messages
			if (isset($messages['errors']))
			{
				$this->template->content->set('errors', $messages['errors']);
			}
			if (isset($messages['messages']))
			{
				$this->template->content->set('messages', $messages['messages']);
			}					
		}
		
		
		// Password reset request
		if ($this->request->post('recover_email'))
		{
			$email = $this->request->post('recover_email');
			$csrf_token = $this->request->post('form_auth_id');
			
			if ( ! Valid::email($email) OR ! CSRF::valid($csrf_token))
			{
				$this->template->content->set('errors', 
					array(__('The email address you have provided is invalid')));
			}
			else 
			{

				// Is the email registed in this site?
				$user = ORM::factory('user',array('email'=>$email));

				if ( ! $user->loaded())
				{
					$this->template->content->set('errors', 
						array(__('The provided email address is not registered')));
				} 
				else
				{
					// Do the password reset depending on the auth driver we are using.
					if ($this->riverid_auth) 
					{
						$this->_password_reset_riverid($email, $user);
					}
					else
					{
						$this->_password_reset_orm($email, $user);
					}
				}
			}

		}

		// Check, has the form been submitted, if so, setup validation
		if ($this->request->post('username') AND $this->request->post('password'))
		{
			// Validate the form token
			if (CSRF::valid($this->request->post('form_auth_id')))
			{
				$username = $this->request->post('username');
				$password = $this->request->post('password');
				
				// Check Auth if the post data validates using the rules setup in the user model
				if (Auth::instance()->login($username, $password, 
					$this->request->post('remember') == 1))
				{
					// Always redirect after a successful POST to prevent refresh warnings
					// First check if a referrer was provided in the post parameters
					// and if not provided, use the referrer from the request otherwise
					// just redirect to the user profile if the above are not found or do
					// not point to a url in this site
					$redirect_to = $this->request->post('referrer');
					$redirect_to = $redirect_to ? $redirect_to : $this->request->referrer();
					
					if ( ! $redirect_to 
						OR strpos($redirect_to, URL::base($this->request)) === FALSE
						OR strpos($redirect_to, URL::base($this->request)) != 0)
					{
						$user = Auth::instance()->get_user();
						$redirect_to = URL::site().$user->account->account_path;
					}
					$this->request->redirect($redirect_to);
				}
				else
				{
					$this->template->content->set('username', $username);

					// Get errors for display in view
					$validation = Validation::factory($this->request->post())
						->rule('username', 'not_empty')
						->rule('password', 'not_empty');
					if ($validation->check())
					{
						$validation->error('password', 'invalid');
					}
					$this->template->content->set('errors', $validation->errors('login'));
				}
			}
			else
			{
				// Show invalid request message
				Kohana::$log->add(Log::ERROR, "Invalid CSRF token :token", 
					array(':token' => $this->request->post('form_auth_id')));
			}
		}
	}
	
	public function action_register_ajax()
	{
		$this->auto_render = FALSE;

		if ($this->request->post('new_email'))
		{
			$messages = $this->_new_user($this->request->post('new_email'), (bool) $this->request->post('invite'));
			$ret = array();
        
			if (isset($messages['errors']))
			{
				$ret['status'] = 'ERROR';
				$ret['errors'] = $messages['errors'];
			}
			if (isset($messages['messages']))
			{
				$ret['status'] = 'OK';
				$ret['messages'] = $messages['messages'];
			}
        
			echo json_encode($ret);
		}
	}
	
	private function _new_user($email, $invite = FALSE)
	{
		$messages = array();
		
		// Check if an admin user is logged in
		$admin = FALSE;
		if (Auth::instance()->logged_in())
		{
			$admin = Auth::instance()->get_user()->has('roles', 
				ORM::factory('role',array('name'=>'admin')));
		}
		
		if ( ! (bool) Model_Setting::get_setting('public_registration_enabled') AND ! $admin)
		{
			$messages['errors'] = array(__('This site is not open to public registration'));
		}
		else
		{
			if ( ! Valid::email($email))
			{
				$messages['errors'] = array(__('The email address provided is invalid'));
			} 
			else
			{
				if ($this->riverid_auth)
				{
					$messages = $this->_new_user_riverid($this->request->post('new_email'), $invite);
				}
				else
				{
					$messages = $this->_new_user_orm($this->request->post('new_email'), $invite);
				}
			}
		}		  
		
		return $messages;
	}
	
	/**
	* Send a river id registration request
	*
	*/
	private function _new_user_riverid($email, $invite = FALSE) 
	{
		$ret = array();
		$riverid_api = RiverID_API::instance();
		
		if ( $riverid_api->is_registered($email)) 
		{
			$ret['errors'] = array(__('The email address provided is already registered.'));
		}
		else
		{
			$mail_body = NULL;
			if ($invite)
			{
				$mail_body = View::factory('emails/invite')
							 ->bind('secret_url', $secret_url);
				$mail_body->site_name = Model_Setting::get_setting('site_name');
				$mail_subject = __(':sitename Invite!', array(':sitename' => Model_Setting::get_setting('site_name')));
			}
			else
			{
				$mail_body = View::factory('emails/createuser')
							 ->bind('secret_url', $secret_url);
				$mail_subject = __(':sitename: Please confirm your email address', array(':sitename' => Model_Setting::get_setting('site_name')));
			}
			$secret_url = url::site('login/create/'.urlencode($email).'/%token%', TRUE, TRUE);
			$site_email = Kohana::$config->load('useradmin.email_address');
			$response = $riverid_api->request_password($email, $mail_body, $mail_subject, $site_email);
			
			if ($response['status']) 
			{
				$ret['messages'] = array(__('An email has been sent with instructions to complete the registration process.'));
			} 
			else 
			{
				$ret['errors'] = array($response['error']);
			}

		}
		
		return $ret;
	}

	/**
	* New user registration for ORM auth
	*
	*/
	private function _new_user_orm($email, $invite = FALSE)
	{
		$ret = array();
		
		// Is the email registed in this site?
		$user = ORM::factory('user',array('email'=>$email));

		if ($user->loaded())
		{
			$ret['errors'] = array(__('The email address provided is already registered.'));
		}
		else
		{
			$auth_token = Model_Auth_Token::create_token($email, 'new_registration');        
			if ($auth_token->loaded())
			{
				//Send an email with a secret token URL
				$mail_body = NULL;
				$mail_subject = NULL;
				if ($invite)
				{
					$mail_body = View::factory('emails/invite')
								 ->bind('secret_url', $secret_url);
					$mail_body->site_name = Model_Setting::get_setting('site_name');
					$mail_subject = __(':sitename Invite!', array(':sitename' => Model_Setting::get_setting('site_name')));
				}
				else
				{
					$mail_body = View::factory('emails/createuser')
								 ->bind('secret_url', $secret_url);
					$mail_subject = __(':sitename: Please confirm your email address', array(':sitename' => Model_Setting::get_setting('site_name')));
				}
				
				$secret_url = url::site('login/create/'.urlencode($email).'/'.$auth_token->token, TRUE, TRUE);
				Swiftriver_Mail::send($email, $mail_subject, $mail_body); 


				$ret['messages'] = array(__('An email has been sent with instructions to complete the registration process.'));
			}
			else
			{
				$ret['errors'] = array($response['error']);
			}
		}
		
		return $ret;
	}	

	/**
	* Send a river id password reset request
	*
	*/	
	private function _password_reset_riverid($email, $user)
	{
		$riverid_api = RiverID_API::instance();		            
		$mail_body = View::factory('emails/resetpassword')
					 ->bind('secret_url', $secret_url);		            
		$secret_url = url::site('login/reset/'.$user->id.'/%token%', TRUE, TRUE);
		$site_email = Kohana::$config->load('useradmin.email_address');
		$mail_subject = __(':sitename: Password Reset', array(':sitename' => Model_Setting::get_setting('site_name')));
		$response = $riverid_api->request_password($email, $mail_body, $mail_subject, $site_email);
		
		if ($response['status']) 
		{
			$this->messages = array(__('An email has been sent with instructions to complete the password reset process.'));
		} 
		else 
		{
			$this->$errors = array($response['error']);
		}
	}

	/**
	* Password reset for ORM auth.
	*
	*/	
	private function _password_reset_orm($email, $user)
	{
		$auth_token = Model_Auth_Token::create_token($email, 'password_reset');        
		if ($auth_token->loaded())
		{
			//Send an email with a secret token URL
			$mail_body = View::factory('emails/resetpassword')
						 ->bind('secret_url', $secret_url);		            
			$secret_url = url::site('login/reset/'.$user->id.'/'.$auth_token->token, TRUE, TRUE);
			$mail_subject = __(':sitename: Password Reset', array(':sitename' => Model_Setting::get_setting('site_name')));
			Swiftriver_Mail::send($email, $mail_subject, $mail_body);
			
			
			$this->messages = array(
				__('An email has been sent with instructions to complete the password reset process.'));
		}
		else
		{
			$this->$messages = array(__('error'));
		}
	}
	
	/**
	* Reset password
	* 
	* @return void
	*/	
	public function action_reset()
	{
		$this->template->content = View::factory('pages/login/reset')
		                                 ->bind('errors', $errors);
        
		$user_id = intval($this->request->param('id', 0));
		$email = $this->request->param('email');
		$token = $this->request->param('token');
        
		$user = ORM::factory('user', $user_id);
		if ($user->loaded())
		{
			// If we have userid only, get email from the user object
			$email = $user->email;
		}	        
        
        
		// If the form has been filled in and submitted
		if ($email AND $this->request->post('password_confirm') AND $this->request->post('password'))
		{
			// Validate the passwords
			$post = Model_Auth_User::get_password_validation($this->request->post());
			if ( ! $post->check())
			{
				$errors = $post->errors('user');
			}
			else
			{
				// Do a RiverID password reset
				if ($this->riverid_auth)
				{
					$riverid_api = RiverID_API::instance();
					$resp = $riverid_api->set_password($email, $token, $this->request->post('password'));
        
					if ( ! $resp['status']) 
					{
						$errors = array($resp['error']);
					}
					else
					{
						$session = Session::instance();
						$session->set('system_messages', array(__('Password reset was successful. Proceed to Log in')));
						$this->request->redirect('login');
						return;
					}
				}
				else
				{
					// Do an ORM password reset
					if (Model_Auth_Token::is_valid_token($email, $token, 'password_reset') OR
						Model_Auth_Token::is_valid_token($email, $token, 'new_registration'))
					{
						if ( ! $user->loaded() ) {
							// New user registration
							$user->username = $user->email = $email;
							$user->save();
        
							// Allow the user be able to login immediately
							$login_role = ORM::factory('role',array('name'=>'login'));
							$user->add('roles', $login_role);
						}                   
						$user->password = $this->request->post('password');
						$user->save();
						Session::instance()->set('system_messages', array(__('Password reset was successful. Proceed to Log in')));
						$this->request->redirect('login');
						return;	                    
					}
					else
					{
						$errors = array(__('Error'));
					}
				}
			}
		}
	}

	
	/**
	 * Change email address
	 * 
	 * @return void
	 */		
	public function action_changeemail()
	{
		// Force logout
		Auth::instance()->logout();
		
		$session = Session::instance();
		
		$user_id = intval($this->request->param('id', 0));
		$user = ORM::factory('user', $user_id);
		$new_email = $this->request->param('email');
		$token = $this->request->param('token');

		if ($user->loaded())
		{
			if ($this->riverid_auth)
			{
				$riverid_api = RiverID_API::instance();
				$resp = $riverid_api->confirm_email($new_email, $token);	        

				if ( ! $resp['status']) 
				{
					$errors = array($resp['error']);
				}            
			}
			else
			{
				if ( ! Model_Auth_Token::is_valid_token($new_email, $token, 'change_email'))
				{
					$errors = array(__('Error'));
				}
			}

			if (empty($errors))
			{
				// Email change was validated, make the change to the user object
				$user->email = $user->username = $new_email;
				$user->save();

				$session->set('system_messages', array(__('Email changed successfully. Proceed to Log in')));
			}
			else
			{
				$session->set('system_errors', $errors);
			}
		}           
		
		// Redirect to login page
		$this->request->redirect('login');
		echo $template;   
	}

	/**
	 * Create account page
	 */
	public function action_create_account()
	{
		// Check if public registration if enabled
		if ($this->public_registration_enabled)
		{
			$this->template->content->active = 'create';
			$this->sub_content = View::factory('pages/login/create_account');
		}
		else
		{
			// Redirect to the login page
			$this->request->redirect('login');
		}
	}

	/**
	 * Log User Out
	 * 
	 * @return void
	 */	
	public function action_done()
	{
		// Sign out the user
		Auth::instance()->logout();
		
		Request::current()->redirect('login');
	}
}
