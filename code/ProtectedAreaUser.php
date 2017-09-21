<?php


class ProtectedAreaUser extends DataObject
{
	private static $admin_can_set_password = false;
	private static $user_can_update_password = true;
	private static $auto_generate_password = true;
	private static $min_password_length = 6;
	private static $secure_salt = 'b391a4df558331de1668df9086c57a9f';
	private static $cookie_name = '_ppu';
	private static $cookie_lifetime = 1;
	
	private static $db = array(
		'Active' => 'Boolean',
		'FirstName' => 'Varchar(255)',
		'LastName' => 'Varchar(255)',
		'Email' => 'Varchar(255)',
		'Password' => 'Varchar(255)',
		'PasswordSalt' => 'Varchar(255)',
		'TempPassword' => 'Varchar(255)',
		'TempPasswordSalt' => 'Varchar(255)',
		'UserHash' => 'Varchar(32)',
	);
	
	private static $many_many = array(
		'ProtectedAreaUserGroups' => 'ProtectedAreaUserGroup'
	);	

	private static $summary_fields = array(
		'Active.Nice' => 'Active',
		'FirstName' => 'First Name',
		'LastName' => 'Last Name',
		'Email' => 'Email',
		'GroupsCSV' => 'Groups'
	);
	
	private static $defaults = array(
		'Active' => 1
	);
	
	public function getTitle()
	{
		return $this->FirstName.' '.$this->LastName;
	}

	function getCMSFields()
	{
		$fields = parent::getCMSFields();
		$fields->removeByName('Password');
		$fields->removeByName('PasswordSalt');
		$fields->removeByName('TempPassword');
		$fields->removeByName('TempPasswordSalt');
		$fields->removeByName('UserHash');
		$fields->removeByName('ProtectedAreaUserGroups');
		if ($this->Config()->get('admin_can_set_password'))
		{
			$fields->addFieldToTab('Root.Main', PasswordField::create('NewPassword','New Password')
				->setDescription('Leave blank to keep current password') );
			if ( (!$this->ID) && ($this->Config()->get('auto_generate_password')) )
			{
				$fields->dataFieldByName('NewPassword')->setDescription('Leave blank to auto generate');
			}
		}
		$fields->addFieldToTab('Root.Main', CheckboxSetField::create('ProtectedAreaUserGroups','Secure Groups')
			->setSource(ProtectedAreaUserGroup::get()->map('ID','Title')) );
		if ( (!$this->ID) && ($groupsField = $fields->dataFieldByName('ProtectedAreaUserGroups')) )
		{
			$groupsField->setDescription('Make sure to select the groups before saving, an access link will be included in the user email');
		}
		return $fields;
	}

	public function canCreate($member = null) { return true; }
	public function canDelete($member = null) { return Permission::check('ADMIN'); }
	public function canEdit($member = null)   { return true; }
	public function canView($member = null)   { return true; }

	public function GroupsCSV()
	{
		return implode(',',$this->ProtectedAreaUserGroups()->column('Title'));
	}
	
	public function validate()
	{
		$result = parent::validate();
		if (!$this->FirstName || !$this->LastName || !$this->Email)
		{
			$result->error('Please provide first name, last name and email');
		}
		if (ProtectedAreaUser::get()->exclude('ID',$this->ID)->find('Email',$this->Email))
		{
			$result->error('An account with that email already exists');
		}
		if ( (!$this->Password) && (!$this->NewPassword) && (!$this->Config()->get('auto_generate_password')) )
		{
			$result->error('You must assign a password');
		}
		if ( ($this->NewPassword) && (strlen($this->NewPassword) < $this->Config()->get('min_password_length')) )
		{
			$result->error('Password must be at least '.$this->Config()->get('min_password_length').' charaters');
		}
		// user must be assigned to at least one group before it can be saved for the first time
		if ( (!$this->ID) && (!$this->ProtectedAreaUserGroups()->Count()) )
		{
			$result->error('You must assign a user to a secure group on creation');
		}
		return $result;
	}
	
	public function onBeforeWrite()
	{
		parent::onBeforeWrite();
		if (!$this->UserHash)
		{
			$this->UserHash = md5(md5($this->GeneratePassword()));
		}
		$sendPasswordEmail = false;
		if ( (!$this->Password) && ($this->Config()->get('auto_generate_password')) )
		{
			$this->NewPassword = $this->GeneratePassword();
			$sendPasswordEmail = true;
		}
		if ( ($this->NewPassword) && ( ($this->Config()->get('admin_can_set_password')) || ($this->Config()->get('auto_generate_password')) ) )
		{
			$newPassword = $this->NewPassword;
			$passwordData = $this->EncryptPassword($newPassword);
			$this->Password = $passwordData['Password'];
			$this->PasswordSalt = $passwordData['Salt'];
			$sendPasswordEmail = true;
		}
		if ( ($this->ChangePassword) && ($this->Config()->get('user_can_update_password')) )
		{
			$newPassword = $this->ChangePassword;
			$passwordData = $this->EncryptPassword($newPassword);
			$this->Password = $passwordData['Password'];
			$this->PasswordSalt = $passwordData['Salt'];
			$sendPasswordEmail = false;
		}
		if ( ($sendPasswordEmail) && ($this->isChanged('Password')) )
		{
			$siteConfig = SiteConfig::current_site_config();
			$explode = array_reverse(explode('.',$_SERVER['HTTP_HOST']));
			$domain = implode('.',array_reverse(array(array_shift($explode),array_shift($explode))));
			$this->SiteDomain = preg_replace('/(\/)$/','',preg_replace('/http(s)?\:\/\//','',$_SERVER['HTTP_HOST']));
			Email::create()
				->setTo($this->Email)
				->setFrom($siteConfig->Title.'<donotreply@'.$domain.'>')
				->setSubject($siteConfig->Title.' Credentials')
				->setTemplate('email_PasswordSet')
				->populateTemplate($this)
				->send();
		}
	}
	
	public function GeneratePassword()
	{
		return substr(md5(strtotime('now').$_SERVER['REMOTE_ADDR']),0,intval($this->Config()->get('min_password_length')));
	}
	
	public function EncryptPassword($password)
	{
		$encryptor = new PasswordEncryptor_Blowfish();
		$Salt = $encryptor->salt($password);
		$encryptedPassword = $encryptor->encrypt($password,substr($Salt,0,25).substr($this->Config()->get('secure_salt'),0,25));
		return array('Password' => $encryptedPassword, 'Salt' => $Salt);
	}
	
	public function CheckPassword($password)
	{
		$encryptor = new PasswordEncryptor_Blowfish();
		return $encryptor->check($this->Password, $password, substr($this->PasswordSalt,0,25).substr($this->Config()->get('secure_salt'),0,25));	
	}
	
	public function CheckTempPassword($password)
	{
		$encryptor = new PasswordEncryptor_Blowfish();
		return $encryptor->check($this->TempPassword, $password, substr($this->TempPasswordSalt,0,25).substr($this->Config()->get('secure_salt'),0,25));	
	}
	
	/**
	 * @var stores the instance of the current user
	 */
	protected static $CurrentSiteUser;
	/**
	 * Retrieves the user by the current session
	 * @returns object User
	 */
	public static function CurrentSiteUser()
	{
		if (!self::$CurrentSiteUser)
		{
			if ($UserHash = Cookie::get(self::Config()->get('cookie_name'))) self::$CurrentSiteUser = self::get()->filter('Active',1)->filter("UserHash",$UserHash)->First();
		}
		return self::$CurrentSiteUser;
	}

	/**
	 * Logs this user into a session
	 * @returns object $this
	 */
	public function Login()
	{
		$this->extend('onBeforeLogin',$this);
		Cookie::set($this->Config()->get('cookie_name'),$this->UserHash,$this->Config()->get('cookie_lifetime'));
		$this->extend('onAfterLogin',$this);
		return $this;
	}
	
	/**
	 * Ends this user's session
	 * @returns object $this
	 */
	public function Logout()
	{
		$this->extend('onBeforeLogout',$this);
		Cookie::set($this->Config()->get('cookie_name'),false,0);
		Cookie::force_expiry($this->Config()->get('cookie_name'));
		$this->extend('onAfterLogout',$this);
		return $this;
	}
	
	/**
	 * Solidifies the temporary password into the permenant password
	 * @returns object $this
	 */
	public function ConvertTemporaryPassword()
	{
		if ( ($this->TempPassword) && ($this->TempPasswordSalt) )
		{
			$this->Password = $this->TempPassword;
			$this->PasswordSalt = $this->TempPasswordSalt;
			$this->TempPassword = null;
			$this->TempPasswordSalt = null;
			$this->write();
		}
		return $this;
	}
	
	/**
	 * removes the temporary password 
	 * @returns object $this
	 */
	public function ClearTemporaryPassword()
	{
		if ( ($this->TempPassword) || ($this->TempPasswordSalt) )
		{
			$this->TempPassword = null;
			$this->TempPasswordSalt = null;
			$this->write();
		}
		return $this;
	}
	
	/**
	 * Generates a new temp password for the user and emails it to them
	 * @returns object $this
	 */
	public function ResetPassword()
	{
		$siteConfig = SiteConfig::current_site_config();
		$this->NewTempPassword = $this->GeneratePassword();
		$passwordData = $this->EncryptPassword($this->NewTempPassword);
		$this->TempPassword = $passwordData['Password'];
		$this->TempPasswordSalt = $passwordData['Salt'];

		$explode = array_reverse(explode('.',$_SERVER['HTTP_HOST']));
		$domain = implode('.',array_reverse(array(array_shift($explode),array_shift($explode))));
		Email::create()
			->setTo($this->Email)
			->setFrom($siteConfig->Title.'<donotreply@'.$domain.'>')
			->setSubject($siteConfig->Title.' Password Reset')
			->setTemplate('email_PasswordReset')
			->populateTemplate($this)
			->send();
		// write after email is sent so new password isn't lost
		$this->write();
		return $this;
	}

}










