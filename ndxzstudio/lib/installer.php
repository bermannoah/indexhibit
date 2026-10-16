<?php if (!defined('SITE')) exit('No direct script access allowed');

/**
* Standalone Indexhibit installer.
*
* Creates the database schema and seed data, and writes the runtime
* config file. Used by the CLI entry point (ndxzstudio/install-cli.php)
* and by the container entrypoint. There is intentionally no web UI.
*
* @version 2.2
*/
#[AllowDynamicProperties]
class IndexhibitInstaller
{
	public $config = array();
	public $prefix = 'ndxz_';
	public $link;
	public $charset = 'utf8';
	public $collate = 'utf8_unicode_ci';
	public $isam = 'ENGINE=MyISAM DEFAULT CHARSET=utf8';

	/**
	* @param array $config DB_* and seed values
	*/
	public function __construct(array $config)
	{
		$this->config = $config;
		$this->prefix = isset($config['prefix']) && $config['prefix'] !== '' ? $config['prefix'] : 'ndxz_';
	}

	/**
	* Build a config array from environment variables.
	*
	* @return array
	*/
	public static function configFromEnv()
	{
		return array(
			'host'   => getenv('DB_HOST') ? getenv('DB_HOST') : 'localhost',
			'db'     => getenv('DB_NAME') ? getenv('DB_NAME') : '',
			'user'   => getenv('DB_USER') ? getenv('DB_USER') : '',
			'pass'   => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
			'prefix' => getenv('DB_PREFIX') ? getenv('DB_PREFIX') : 'ndxz_',
			'site'   => getenv('SITE_NAME') ? getenv('SITE_NAME') : 'example.com',
			'fname'  => getenv('ADMIN_FNAME') ? getenv('ADMIN_FNAME') : 'Admin',
			'lname'  => getenv('ADMIN_LNAME') ? getenv('ADMIN_LNAME') : 'Admin',
			'email'  => getenv('ADMIN_EMAIL') ? getenv('ADMIN_EMAIL') : 'admin@example.com',
		);
	}

	/**
	* Validate that required config values are present.
	*
	* @return void
	*/
	public function validate()
	{
		foreach (array('host', 'db', 'user') as $key)
		{
			if (!isset($this->config[$key]) || trim((string) $this->config[$key]) === '')
			{
				throw new RuntimeException("Missing required configuration value: $key");
			}
		}
	}

	/**
	* Write ndxzsite/config/config.php.
	*
	* @param array $config
	* @param string $path
	* @return bool
	*/
	public static function writeConfigFile(array $config, $path)
	{
		$content  = "<?php if (!defined('SITE')) exit('No direct script access allowed');\n\n";
		$content .= "\$indx['db']   = " . var_export($config['db'], true) . ";\n";
		$content .= "\$indx['user'] = " . var_export($config['user'], true) . ";\n";
		$content .= "\$indx['pass'] = " . var_export($config['pass'], true) . ";\n";
		$content .= "\$indx['host'] = " . var_export($config['host'], true) . ";\n";
		$content .= "\$indx['sql']  = 'mysql';\n\n";
		$content .= "if (!defined('PX')) { define('PX', " . var_export($config['prefix'], true) . "); }\n";

		$dir = dirname($path);
		if (!is_dir($dir)) mkdir($dir, 0775, true);

		return file_put_contents($path, $content) !== false;
	}

	/**
	* Connect to the database.
	*
	* @return void
	*/
	public function connect()
	{
		mysqli_report(MYSQLI_REPORT_OFF);

		$this->link = @mysqli_connect(
			$this->config['host'],
			$this->config['user'],
			$this->config['pass']
		);

		if (!$this->link)
		{
			throw new RuntimeException('Database connection failed: ' . mysqli_connect_error());
		}

		if (!@mysqli_select_db($this->link, $this->config['db']))
		{
			throw new RuntimeException('Cannot select database: ' . mysqli_error($this->link));
		}

		@mysqli_set_charset($this->link, 'utf8');
		@mysqli_query($this->link, "SET SESSION sql_mode = 'ANSI'");
	}

	/**
	* Set the storage engine/charset fragment used by the schema.
	*
	* @return void
	*/
	public function setCharsetCollation()
	{
		$version = preg_replace('/[^0-9.].*/', '', mysqli_get_server_info($this->link));

		if (version_compare($version, '4.1', '>='))
		{
			$this->charset_collate = 'DEFAULT CHARACTER SET ' . $this->charset;
			$this->charset_collate .= ' COLLATE ' . $this->collate;
		}

		$ver = substr(mysqli_get_client_version(), 0, 1);

		if (is_numeric($ver) && $ver <= 4)
		{
			$this->isam = 'TYPE=MyISAM';
		}
	}

	/**
	* Is the schema already installed?
	*
	* @return bool
	*/
	public function isInstalled()
	{
		$res = @mysqli_query($this->link, "SELECT 1 FROM `" . $this->prefix . "objects` LIMIT 1");
		if ($res) { mysqli_free_result($res); return true; }
		return false;
	}

	/**
	* Run the installer. Safe to call repeatedly.
	*
	* @return void
	*/
	public function run()
	{
		$this->validate();

		if (!defined('PX')) define('PX', $this->prefix);

		$this->connect();
		$this->setCharsetCollation();

		if (!$this->isInstalled())
		{
			$this->installSchema();
		}

		$this->writeConfig();
	}

	/**
	* Write the runtime config file from the installer config.
	*
	* @return void
	*/
	public function writeConfig()
	{
		$path = DIRNAME . '/ndxzsite/config/config.php';
		if (!self::writeConfigFile($this->config, $path))
		{
			throw new RuntimeException("Could not write config file: $path");
		}
	}

	/**
	* Escape a value for SQL.
	*
	* @param string $value
	* @return string
	*/
	public function escape($value)
	{
		return "'" . mysqli_real_escape_string($this->link, (string) $value) . "'";
	}

	/**
	* Create tables and seed the default content.
	*
	* @return void
	*/
	public function installSchema()
	{
		$p = $this->prefix;
		$isam = $this->isam;

		$sql = array();

		$sql[] = "CREATE TABLE IF NOT EXISTS iptocountry (
		  ip_from double NOT NULL DEFAULT '0',
		  ip_to double NOT NULL DEFAULT '0',
		  country_code2 char(2) NOT NULL DEFAULT '',
		  country_code3 char(3) NOT NULL DEFAULT '',
		  country_name varchar(50) NOT NULL DEFAULT '',
		  KEY ip_from_to_idx (ip_from,ip_to)
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS {$p}abstracts (
		  ab_id int(11) NOT NULL AUTO_INCREMENT,
		  ab_obj varchar(32) NOT NULL DEFAULT '',
		  ab_obj_id int(11) NOT NULL DEFAULT '0',
		  ab_var varchar(255) NOT NULL DEFAULT '',
		  abstract text,
		  PRIMARY KEY (ab_id)
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS {$p}profile (
			pr_id int(11) NOT NULL AUTO_INCREMENT,
			pr_apikey varchar(32) NOT NULL,
			pr_sitekey varchar(32) NOT NULL,
			pr_name varchar(250) NOT NULL,
			pr_title varchar(250) NOT NULL,
			pr_image varchar(1000) NOT NULL,
			pr_location varchar(250) NOT NULL,
			pr_freelance varchar(1) NOT NULL,
			PRIMARY KEY (pr_id)
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS {$p}media (
		  media_id int(11) NOT NULL AUTO_INCREMENT,
		  media_ref_id smallint(6) NOT NULL DEFAULT '0',
		  media_obj_type varchar(15) NOT NULL DEFAULT '',
		  media_mime varchar(15) NOT NULL DEFAULT '',
		  media_tags varchar(255) NOT NULL DEFAULT '0',
		  media_file varchar(255) NOT NULL DEFAULT '',
		  media_thumb varchar(255) NOT NULL DEFAULT '',
		  media_file_replace varchar(255) NOT NULL DEFAULT '',
		  media_title varchar(255) NOT NULL DEFAULT '',
		  media_caption text NOT NULL,
		  media_x varchar(5) NOT NULL DEFAULT '',
		  media_y varchar(5) NOT NULL DEFAULT '',
		  media_xr smallint(4) NOT NULL DEFAULT '0',
		  media_yr smallint(4) NOT NULL DEFAULT '0',
		  media_kb mediumint(9) NOT NULL DEFAULT '0',
		  media_udate datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		  media_uploaded datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		  media_order smallint(3) NOT NULL DEFAULT '999',
		  media_hide tinyint(1) NOT NULL DEFAULT '0',
		  media_dir varchar(255) NOT NULL DEFAULT '',
		  media_src varchar(25) NOT NULL DEFAULT '',
		  PRIMARY KEY (media_id)
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS {$p}objects (
		  id int(11) NOT NULL AUTO_INCREMENT,
		  object varchar(100) NOT NULL DEFAULT '',
		  obj_ref_id int(4) NOT NULL DEFAULT '0',
		  title varchar(100) NOT NULL DEFAULT '',
		  content mediumtext NOT NULL,
		  home tinyint(1) NOT NULL DEFAULT '0',
		  link varchar(255) NOT NULL DEFAULT '',
		  target tinyint(1) NOT NULL DEFAULT '0',
		  iframe tinyint(1) NOT NULL DEFAULT '0',
		  new tinyint(1) NOT NULL DEFAULT '0',
		  tags varchar(250) NOT NULL DEFAULT '0',
		  header text NOT NULL,
		  udate datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		  pdate datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		  creator tinyint(3) NOT NULL DEFAULT '0',
		  status tinyint(1) NOT NULL DEFAULT '0',
		  process tinyint(1) NOT NULL DEFAULT '1',
		  page_cache tinyint(1) NOT NULL DEFAULT '0',
		  section_id tinyint(3) NOT NULL DEFAULT '0',
		  section_top tinyint(1) NOT NULL DEFAULT '0',
		  section_sub varchar(255) NOT NULL DEFAULT '',
		  subdir tinyint(1) NOT NULL DEFAULT '0',
		  url varchar(250) NOT NULL DEFAULT '',
		  ord smallint(3) NOT NULL DEFAULT '999',
		  color varchar(7) NOT NULL DEFAULT 'ffffff',
		  bgimg varchar(255) NOT NULL DEFAULT '',
		  hidden tinyint(1) NOT NULL DEFAULT '0',
		  current tinyint(1) NOT NULL DEFAULT '0',
		  perm tinyint(1) NOT NULL DEFAULT '0',
		  media_source tinyint(3) NOT NULL DEFAULT '0',
		  media_source_detail varchar(255) NOT NULL,
		  images smallint(4) NOT NULL DEFAULT '9999',
		  thumbs_shape tinyint(1) NOT NULL DEFAULT '0',
		  thumbs smallint(4) NOT NULL DEFAULT '200',
		  format varchar(100) NOT NULL DEFAULT 'visual_index',
		  thumbs_format tinyint(1) NOT NULL DEFAULT '0',
		  operand tinyint(4) NOT NULL DEFAULT '0',
		  titling tinyint(1) NOT NULL DEFAULT '0',
		  break smallint(2) NOT NULL DEFAULT '0',
		  tiling tinyint(1) NOT NULL DEFAULT '1',
		  year varchar(4) NOT NULL DEFAULT '2010',
		  report tinyint(1) NOT NULL DEFAULT '0',
		  pwd varchar(100) NOT NULL DEFAULT '',
		  placement tinyint(1) NOT NULL DEFAULT '0',
		  template varchar(25) NOT NULL DEFAULT 'index.php',
		  ling varchar(7) NOT NULL DEFAULT 'en',
		  ling_id varchar(32) NOT NULL DEFAULT '',
		  serial longtext NOT NULL,
		  extra1 varchar(255) NOT NULL DEFAULT '',
		  extra2 varchar(255) NOT NULL DEFAULT '',
		  PRIMARY KEY (id)
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS {$p}objects_prefs (
		  obj_id int(11) NOT NULL AUTO_INCREMENT,
		  obj_ref_type varchar(255) NOT NULL DEFAULT '',
		  obj_active tinyint(1) NOT NULL DEFAULT '1',
		  obj_title varchar(255) NOT NULL DEFAULT '',
		  obj_section smallint(3) NOT NULL DEFAULT '1',
		  obj_template varchar(50) NOT NULL DEFAULT '',
		  obj_members varchar(255) NOT NULL DEFAULT '',
		  obj_img varchar(255) NOT NULL DEFAULT '',
		  obj_settings longtext NOT NULL,
		  obj_group varchar(255) NOT NULL DEFAULT '',
		  PRIMARY KEY (obj_id)
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS {$p}plugins (
		  pl_id int(4) NOT NULL AUTO_INCREMENT,
		  pl_primary tinyint(1) NOT NULL DEFAULT '0',
		  pl_type varchar(15) NOT NULL DEFAULT '',
		  pl_name varchar(255) NOT NULL DEFAULT '',
		  pl_uri varchar(255) NOT NULL DEFAULT '',
		  pl_version varchar(20) NOT NULL DEFAULT '',
		  pl_file varchar(255) NOT NULL DEFAULT '',
		  pl_function varchar(255) NOT NULL DEFAULT '',
		  pl_hook varchar(255) NOT NULL DEFAULT '',
		  pl_space varchar(100) NOT NULL DEFAULT '',
		  pl_creator varchar(50) NOT NULL DEFAULT '',
		  pl_www varchar(255) NOT NULL DEFAULT '',
		  pl_desc text NOT NULL,
		  pl_options text NOT NULL,
		  pl_options_build text NOT NULL,
		  pl_usage varchar(255) NOT NULL DEFAULT '',
		  pl_usage_desc varchar(255) NOT NULL DEFAULT '',
		  pl_order smallint(3) NOT NULL DEFAULT '100',
		  PRIMARY KEY (pl_id)
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS {$p}sections (
		  secid tinyint(3) NOT NULL AUTO_INCREMENT,
		  section varchar(60) NOT NULL DEFAULT '',
		  sec_obj varchar(50) NOT NULL DEFAULT 'exhibits',
		  sec_ord tinyint(4) NOT NULL DEFAULT '0',
		  sec_disp tinyint(3) NOT NULL DEFAULT '1',
		  sec_hide tinyint(1) NOT NULL DEFAULT '0',
		  sec_pwd varchar(32) NOT NULL DEFAULT '',
		  sec_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		  sec_path varchar(250) NOT NULL DEFAULT '',
		  sec_subs varchar(100) NOT NULL DEFAULT '',
		  sec_desc varchar(100) NOT NULL DEFAULT '',
		  sec_proj tinyint(4) NOT NULL DEFAULT '0',
		  sec_group tinyint(4) NOT NULL DEFAULT 0,
		  sec_report tinyint(1) NOT NULL DEFAULT '0',
		  PRIMARY KEY (secid)
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS {$p}settings (
		  adm_id tinyint(3) NOT NULL AUTO_INCREMENT,
		  site_name varchar(40) NOT NULL DEFAULT '',
		  installdate varchar(20) NOT NULL DEFAULT '',
		  version varchar(25) NOT NULL DEFAULT '',
		  curr_time tinyint(3) NOT NULL DEFAULT '0',
		  site_lang varchar(8) NOT NULL DEFAULT 'en-us',
		  time_format varchar(25) NOT NULL DEFAULT '',
		  tagging tinyint(1) NOT NULL DEFAULT '1',
		  help tinyint(1) NOT NULL DEFAULT '0',
		  caching tinyint(1) NOT NULL DEFAULT '0',
		  hibernate varchar(255) NOT NULL DEFAULT '',
		  obj_name varchar(255) NOT NULL DEFAULT '',
		  obj_theme varchar(50) NOT NULL DEFAULT '',
		  obj_itop text NOT NULL,
		  obj_ibot text NOT NULL,
		  obj_org tinyint(1) NOT NULL DEFAULT '1',
		  obj_apikey varchar(32) NOT NULL DEFAULT '',
		  site_format varchar(30) NOT NULL DEFAULT '%d %B %Y',
		  site_offset tinyint(3) NOT NULL DEFAULT '0',
		  site_vars longtext NOT NULL,
		  PRIMARY KEY (adm_id)
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS {$p}stats (
		  hit_id int(14) NOT NULL AUTO_INCREMENT,
		  hit_addr varchar(16) NOT NULL DEFAULT '',
		  hit_country varchar(30) NOT NULL DEFAULT '',
		  hit_lang varchar(10) NOT NULL DEFAULT '',
		  hit_domain varchar(100) NOT NULL DEFAULT '',
		  hit_referrer varchar(100) NOT NULL DEFAULT '',
		  hit_page varchar(100) NOT NULL DEFAULT '',
		  hit_agent varchar(250) NOT NULL DEFAULT '',
		  hit_keyword varchar(250) NOT NULL DEFAULT '',
		  hit_os varchar(20) NOT NULL DEFAULT '',
		  hit_browser varchar(20) NOT NULL DEFAULT '',
		  hit_time datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		  hit_month varchar(7) NOT NULL DEFAULT '',
		  hit_day date NOT NULL DEFAULT '0000-00-00',
		  PRIMARY KEY (hit_id)
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS {$p}stats_exhibits (
		  stor_url varchar(255) NOT NULL DEFAULT '',
		  stor_count smallint(6) NOT NULL DEFAULT '0'
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS {$p}stats_storage (
		  stor_date varchar(7) NOT NULL DEFAULT '0000-00',
		  stor_hits int(11) NOT NULL DEFAULT '0',
		  stor_unique int(11) NOT NULL DEFAULT '0',
		  stor_referrer int(11) NOT NULL DEFAULT '0'
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS {$p}subsections (
		  sub_id tinyint(3) NOT NULL AUTO_INCREMENT,
		  sub_sec_id tinyint(3) NOT NULL DEFAULT 0,
		  sub_title varchar(255) NOT NULL DEFAULT '',
		  sub_folder varchar(255) NOT NULL DEFAULT '',
		  sub_order tinyint(3) NOT NULL DEFAULT 0,
		  sub_hide tinyint(1) NOT NULL DEFAULT 0,
		  PRIMARY KEY (sub_id)
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS {$p}tagged (
		  tagged_prim int(11) NOT NULL AUTO_INCREMENT,
		  tagged_id smallint(6) NOT NULL DEFAULT 0,
		  tagged_object varchar(3) NOT NULL DEFAULT '',
		  tagged_obj_id smallint(6) NOT NULL DEFAULT 0,
		  PRIMARY KEY (tagged_prim)
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS {$p}tags (
		  tag_id int(11) NOT NULL AUTO_INCREMENT,
		  tag_name varchar(255) NOT NULL DEFAULT '',
		  tag_group smallint(3) NOT NULL DEFAULT '1',
		  tag_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		  tag_icon varchar(255) NOT NULL DEFAULT '',
		  PRIMARY KEY (tag_id),
		  UNIQUE KEY tag_name (tag_name)
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS {$p}users (
		  ID int(11) NOT NULL AUTO_INCREMENT,
		  userid varchar(100) NOT NULL DEFAULT '',
		  password varchar(255) NOT NULL DEFAULT '',
		  email varchar(100) NOT NULL DEFAULT '',
		  threads tinyint(3) NOT NULL DEFAULT '10',
		  writing tinyint(1) NOT NULL DEFAULT '0',
		  user_offset tinyint(3) NOT NULL DEFAULT '0',
		  user_format varchar(30) NOT NULL DEFAULT '%d %B %Y',
		  user_lang varchar(8) NOT NULL DEFAULT 'en-us',
		  user_hash varchar(32) NOT NULL DEFAULT '',
		  user_help tinyint(1) NOT NULL DEFAULT '0',
		  user_mode tinyint(1) NOT NULL DEFAULT '0',
		  user_name varchar(35) NOT NULL DEFAULT '',
		  user_surname varchar(35) NOT NULL DEFAULT '',
		  user_admin tinyint(1) NOT NULL DEFAULT '0',
		  user_active tinyint(1) NOT NULL DEFAULT '1',
		  user_client tinyint(1) NOT NULL DEFAULT '0',
		  user_img varchar(255) NOT NULL DEFAULT '',
		  PRIMARY KEY (ID),
		  UNIQUE KEY userid (userid),
		  KEY ID (ID)
		) $isam ;";

		$now = getNow();

		$sql[] = "INSERT INTO `{$p}objects` (`id`, `object`, `obj_ref_id`, `title`, `content`, `home`, `link`, `target`, `iframe`, `new`, `tags`, `header`, `udate`, `pdate`, `creator`, `status`, `process`, `page_cache`, `section_id`, `section_top`, `url`, `ord`, `color`, `bgimg`, `hidden`, `current`, `perm`, `media_source`, `media_source_detail`, `images`, `thumbs_shape`, `thumbs`, `format`, `thumbs_format`, `operand`, `titling`, `break`, `tiling`, `year`, `report`, `pwd`, `placement`, `template`, `ling`, `ling_id`, `serial`, `extra1`, `extra2`) VALUES
		(1, 'exhibits', 1, 'Main', '', 1, '', 0, 0, 0, '2', '', '{$now}', '{$now}', 1, 1, 1, 0, 1, 1, '/', 0, 'ffffff', '', 0, 0, 0, 0, '', 600, 0, 200, 'visual_index', 0, 2, 1, 0, 1, '2011', 0, '', 0, 'index.php', 'en', '', '', '', ''),
		(2, 'exhibits', 2, 'Project', '', 0, '', 0, 0, 0, '0', '', '{$now}', '{$now}', 1, 1, 1, 0, 2, 1, '/project/', 0, 'ffffff', '', 0, 0, 0, 2, '', 9999, 0, 200, 'visual_index', 0, 0, 1, 0, 1, '2011', 0, '', 1, 'index.php', 'en', '', '', '', ''),
		(3, 'exhibits', 3, 'Tags', '', 0, '', 0, 0, 0, '0', '', '{$now}', '{$now}', 1, 0, 1, 0, 3, 1, '/tag/', 0, 'ffffff', '', 0, 0, 0, 0, '', 9999, 0, 200, 'visual_index', 0, 0, 0, 0, 1, '2011', 0, '', 0, 'index.php', 'en', '', '', '', '');";

		$sql[] = "INSERT INTO `{$p}objects_prefs` (`obj_id`, `obj_ref_type`, `obj_active`, `obj_title`, `obj_section`, `obj_template`, `obj_members`, `obj_img`, `obj_settings`, `obj_group`) VALUES
		(1, 'exhibits', 1, '', 1, '', '', '', '', ''),
		(2, 'xml', 1, '', 1, '', '', '', '', ''),
		(3, 'tag', 1, '', 1, '', '', '', 'a:7:{s:10:\"section_id\";s:1:\"3\";s:8:\"template\";s:7:\"tag\.php\";s:6:\"format\";s:12:\"visual_index\";s:6:\"thumbs\";s:3:\"200\";s:12:\"thumbs_shape\";s:1:\"0\";s:5:\"break\";s:1:\"0\";s:7:\"titling\";s:1:\"0\";}', '');";

		$sql[] = "INSERT INTO `{$p}sections` (`secid`, `section`, `sec_obj`, `sec_ord`, `sec_disp`, `sec_hide`, `sec_pwd`, `sec_date`, `sec_path`, `sec_desc`, `sec_proj`, `sec_group`, `sec_report`) VALUES
		(1, 'root', 'exhibits', 2, 1, 0, '', '2006-12-20 17:01:31', '/', 'Main', 0, 0, 0),
		(2, 'project', 'exhibits', 1, 1, 0, '', '2010-03-03 23:48:44', '/project', 'Projects', 0, 0, 0),
		(3, 'tag', 'exhibits', 3, 1, 1, '', '2010-03-04 05:51:22', '/tag', 'Tags', 3, 0, 0);";

		$sql[] = "INSERT INTO `{$p}settings` (`adm_id`, `site_name`, `installdate`, `version`, `curr_time`, `site_lang`, `time_format`, `tagging`, `help`, `hibernate`, `obj_name`, `obj_theme`, `obj_itop`, `obj_ibot`, `obj_org`, `obj_apikey`, `site_format`, `site_offset`, `site_vars`) VALUES
		(1, " . $this->escape($this->config['site']) . ", '{$now}', '" . VERSION . "', 1, 'en-us', '%d %B %Y', 1, 0, '', " . $this->escape($this->config['site']) . ", 'default', '<h1><a href=\"/\" title=\"{{obj_name}}\">{{obj_name}}</a></h1>', '<p>Copyright 2007-2017<br />\n<a href=\"http://www.indexhibit.org/\">Built with Indexhibit</a></p>', 2, '" . bin2hex(random_bytes(16)) . "', '%d %B %Y', 0, 'a:3:{s:9:\"passwords\";s:1:\"1\";s:9:\"templates\";s:1:\"0\";s:4:\"tags\";s:1:\"1\";}');";

		$init_password = ndxz_password_hash('exhibit');

		$sql[] = "INSERT INTO `{$p}users` (`ID`, `userid`, `password`, `email`, `threads`, `writing`, `user_offset`, `user_format`, `user_lang`, `user_hash`, `user_help`, `user_mode`, `user_name`, `user_surname`, `user_admin`, `user_active`, `user_client`) VALUES
		(1, 'index1', " . $this->escape($init_password) . ", " . $this->escape($this->config['email']) . ", 15, 0, 0, '%d %B %Y', 'en-us', '', 0, 1, " . $this->escape($this->config['fname']) . ", " . $this->escape($this->config['lname']) . ", 1, 1, 0);";

		foreach ($sql as $query)
		{
			if (!@mysqli_query($this->link, $query))
			{
				throw new RuntimeException('Install query failed: ' . mysqli_error($this->link) . "\n" . $query);
			}
		}
	}
}
