<?php if (!defined('SITE')) exit('No direct script access allowed');

/**
* Access class
*
* User authentications
* 
* @version 1.0
* @author Vaska 
*/
#[AllowDynamicProperties]
class Access 
{
	public $settings		= array();
	public $prefs			= array();
	public $user_settings	= array();
	public $salt			= 'widewhitespace';
	
	public function __construct()
	{
		
	}
	
	/**
	* User logout 
	*
	* @param void
	* @return mixed
	*/
	public function logout()
	{
		setcookie('ndxz_hash', '', ndxz_auth_cookie_options(time() - 3600));
		setcookie('ndxz_access', '', ndxz_auth_cookie_options(time() - 3600));
		
		$self = (dirname($_SERVER['PHP_SELF']) == '/') ? '' : dirname($_SERVER['PHP_SELF']);
		header('Location: ' . $self . '/');
		exit;
	}

	/**
	* Returns settings array or error
	*
	* @param void
	* @return mixed
	*/
	public function settings()
	{
		$OBJ =& get_instance();
		
		$adm['adm_id'] = 1;
		
		$this->settings = $OBJ->db->selectArray(PX.'settings', $adm, 'record');
		$OBJ->vars->site = unserialize($this->settings['site_vars']);
		$OBJ->vars->settings = $this->settings;
			
		if (!$this->settings) show_error('error finding settings');
	}
	
	/**
	* Returns user preferences array or error
	*
	* @param void
	* @return mixed
	*/
	public function checkLogin()
	{
		$OBJ =& get_instance();
	
		// if logging out
		if (isset($_POST['logout'])) $this->logout();
		
		// looking for password reset query string
		if (isset($_GET['key']) && isset($_GET['id']))
		{	
			$id = (int) $_GET['id'];
			$key = (string) $_GET['key'];
			
			if (($id > 0) && preg_match('/^[a-f0-9]{64}$/', $key))
			{
				// get user id record
				$rs = $OBJ->db->fetchRecord("SELECT ID, email, userid, password, user_hash 
					FROM ".PX."users 
					WHERE ID = '" . $id . "'");
				
				if ($rs && hash_equals(ndxz_login_token_hash($key), (string) $rs['user_hash']))
				{
					// make new password, update database, send to user, notify
					$new_password = $this->createRandomPassword();
					$this->db_ensure_password_column_length();
					$password['password'] = ndxz_password_hash($new_password);
					$password['user_hash'] = '';
			
					// update
					$OBJ->db->updateArray(PX.'users', $password, "ID = '$rs[ID]'");
					
					// send email info
					#produce message in txt format 
					$body = "Hi again! Did you miss us already?<br /><br />";
					$body .= "Enclosed are your login details - you may change these after you login (Preferences).<br /><br />";
					$body .= "Login: [your_email_address]<br />";
					$body .= "Password: $new_password<br /><br />";
					$body .= "URL: " . BASEURL . "/ndxzstudio/ <br /><br />";
					$body .= "Indexhibit probably loves you. ;)";

					// load mail class
					$mail =& load_class('mail', true, 'lib');
					
					$mail->setTo($rs['email'], 'From your website');
					$mail->setSubject('Indexhibit Password Reset');
					$mail->setMessage($body);
					$mail->addMailHeader('Reply-To', 'noreply@indexhibit.org', 'indexhibit.org');
					$mail->addGenericHeader('X-Mailer', 'PHP/' . phpversion());
					$mail->addGenericHeader('Content-Type', 'text/html; charset="utf-8"');
					$mail->setWrap(100);
						
					$mail->send();
					
					show_login('almost there, check your email', true, true);
				}
				else
				{
					show_login('something is wrong');
				}
			}
			else
			{
				show_login('something is wrong');
			}
		}
		
		// retreieving
		if (isset($_POST['retrievePassword'])) 
		{
			$email = getPOST('email', null, 'connect', 100);
			$clean['email'] = md5($email);
			
			// search for info
			$rs = $OBJ->db->fetchRecord("SELECT ID, email, userid, user_name, user_surname, password 
				FROM ".PX."users 
				WHERE MD5(email) = '$clean[email]' 
				AND user_active = '1'");
			
			if ($rs)
			{
				// invalidate any existing login session and store a reset token
				$reset = ndxz_login_token_new();
				$OBJ->db->updateArray(PX.'users', array('user_hash' => ndxz_login_token_hash($reset)), "ID = '" . $rs['ID'] . "'");
				
				#produce message in txt format 
				$body = "Hello from your Indexhibit website!<br /><br />";
				$body .= "Somebody requested a new password - was that you?<br /><br />";
				$body .= "If this is a mistake do nothing - your password has not been reset yet.<br /><br />";
				$body .= "Otherwise, follow the link below to continue reset:<br /><br />";
				$body .= "URL: " . BASEURL . "/ndxzstudio/?id=" . $rs['ID'] . "&key=" . $reset . "<br /><br />";
				$body .= "Byeeeeeeee... ;)";
				
				// reset these
				setcookie('ndxz_accessed', '', ndxz_auth_cookie_options(time() - 3600));
				
				// load mail class
				$mail =& load_class('mail', true, 'lib');
				
				$mail->setTo($rs['email'], 'From your website');
				$mail->setSubject('Indexhibit Password Reset');
				$mail->setMessage($body);
				$mail->addMailHeader('Reply-To', 'noreply@indexhibit.org', 'indexhibit.org');
				$mail->addGenericHeader('X-Mailer', 'PHP/' . phpversion());
				$mail->addGenericHeader('Content-Type', 'text/html; charset="utf-8"');
				$mail->setWrap(100);
					
				$mail->send();
								
				show_login('check your email for information', false);
			}
			else
			{
				// notify that it's incorrect
				show_login('email address not found');
			}
		}
		
		// if logging in
		if (isset($_POST['submitLogin'])) 
		{	
			$login = getPOST('uid', null, 'connect', 100);
			$password = getPOST('pwd', null, 'connect', 100);
			
			$this->prefs = $OBJ->db->fetchRecord("SELECT * FROM ".PX."users 
				WHERE (MD5(userid) = '" . md5($login) . "' OR MD5(email) = '" . md5($login) . "') 
				AND user_active = '1'");
				
			if ($this->prefs && ndxz_password_verify($password, $this->prefs['password']))
			{
				// migrate legacy md5 passwords to password_hash()
				if (ndxz_password_needs_rehash($this->prefs['password']))
				{
					$this->db_ensure_password_column_length();
					$OBJ->db->updateArray(PX.'users', array('password' => ndxz_password_hash($password)), "ID='" . $this->prefs['ID'] . "'");
				}
				
				// create a new random login token upon login
				$token = ndxz_login_token_new();
				$temp['user_hash'] = ndxz_login_token_hash($token);

				$OBJ->db->updateArray(PX.'users', $temp, "ID='".$this->prefs['ID']."'");
				
				setcookie('ndxz_hash', $temp['user_hash'], ndxz_auth_cookie_options(time()+3600*24*2));
				setcookie('ndxz_access', $token, ndxz_auth_cookie_options(time()+3600*24*2));
				
				// if it was set
				if (isset($_COOKIE['ndxz_accessed']))
				{
					setcookie('ndxz_accessed', '', ndxz_auth_cookie_options(time() - 3600));
				}

				$this->settings();
				return;
			}
			else
			{
				// let's track failed login attempts
				$attempt = (isset($_COOKIE['ndxz_accessed'])) ? (((int) $_COOKIE['ndxz_accessed']) + 1) : 1;
				
				setcookie('ndxz_accessed', $attempt, ndxz_auth_cookie_options(time()+3600));
				
				show_login('login err');
			}
		}

		// return access
		if (isset($_COOKIE['ndxz_access']) && isset($_COOKIE['ndxz_hash'])) 
		{
			$token = (string) $_COOKIE['ndxz_access'];
			$hash = (string) $_COOKIE['ndxz_hash'];
			
			if (preg_match('/^[a-f0-9]{64}$/', $token) && preg_match('/^[a-f0-9]{32}$/', $hash))
			{
				$token_hash = ndxz_login_token_hash($token);
				
				$this->prefs = $OBJ->db->fetchRecord("SELECT * FROM ".PX."users 
					WHERE user_hash = '" . $token_hash . "' 
					AND user_active = '1'");
					
				if ($this->prefs && hash_equals($token_hash, (string) $this->prefs['user_hash']) && hash_equals($hash, $token_hash))
				{	
					// we'll update each time so no more weird logouts
					setcookie('ndxz_hash', $token_hash, ndxz_auth_cookie_options(time()+3600*24*2));
					setcookie('ndxz_access', $token, ndxz_auth_cookie_options(time()+3600*24*2));
					
					$this->settings();
					return;
				}
			}
		}

		show_login();
	}
	
	/**
	* Make sure the password column is long enough for password_hash().
	*
	* @param void
	* @return void
	*/
	public function db_ensure_password_column_length()
	{
		$OBJ =& get_instance();
		$OBJ->db->query("ALTER TABLE ".PX."users MODIFY password varchar(255) NOT NULL DEFAULT ''");
	}
	
	/**
	* Returns user preferences array or error
	*
	* @param void
	* @return mixed
	*/
	public function remoteLogin()
	{
		$OBJ =& get_instance();
		
		$p =& load_class('processor', TRUE, 'lib');
	
		$hash = $p->process('hash', array('alpha'));
		$token = $p->process('access', array('alpha'));
		
		if ($hash == '' || $token == '') return;
		
		$token_hash = ndxz_login_token_hash($token);

		$this->prefs = $OBJ->db->fetchRecord("SELECT * FROM ".PX."users 
			WHERE user_hash = '" . $token_hash . "' 
			AND user_active = '1'");
				
		if ($this->prefs && hash_equals($token_hash, (string) $this->prefs['user_hash']))
		{	
			$this->settings();
		}
	}


	public function is_admin()
	{
		return ($this->prefs['user_admin'] == 1) ? true : false;
	}
	
	
	public function createRandomPassword($limit=12)
	{
		$chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ023456789';
		$max = strlen($chars) - 1;
		$pass = '';

		for ($i = 0; $i < $limit; $i++)
		{
			$pass .= $chars[random_int(0, $max)];
		}

		return $pass;
	}
}
