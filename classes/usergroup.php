<?php
/**
 * @package Habari
 *
 */

/**
 * Habari UserGroup Class
 *
 */
class UserGroup extends QueryRecord
{
	// These arrays hold the current membership and permission settings for this group
	// $member_ids is not NOT matched key and value pairs ( like array('foo'=>'foo') )
	private $member_ids = array();
	private $permissions;

	/**
	 * get default fields for this record
	 * @return array an array of the fields used in the UserGroup table
	 */
	public static function default_fields()
	{
		return array(
			'id' => '',
			'name' => ''
		);
	}

	/**
	 * Constructor for the UserGroup class
	 * @param array $paramarray an associative array of UserGroup fields
	 */
	public function __construct( $paramarray = array() )
	{
		$this->fields = array_merge(
			self::default_fields(),
			$this->fields
		);
		parent::__construct( $paramarray );

		// if we have an ID, load this UserGroup's members & permissions
		if ( $this->id ) {
			if ( $result = DB::get_column( 'SELECT user_id FROM {users_groups} WHERE group_id= ?', array( $this->id ) ) ) {
				$this->member_ids = $result;
			}
		}

		// exclude field keys from the $this->fields array that should not be updated in the database on insert/update
		$this->exclude_fields( array( 'id' ) );
	}

	/**
	 * Create a new UserGroup object and save it to the database
	 * @param array $paramarray An associative array of UserGroup fields
	 * @return UserGroup the UserGroup that was created
	 * @todo Make this function accept only a name, since you can't set an id into an autoincrement field, and we don't try.
	 */
	public static function create( $paramarray )
	{
		$usergroup = new UserGroup( $paramarray );
		if ( $usergroup->insert() ) {
			return $usergroup;
		}
		else {
			// Does the group already exist?
			if(isset($paramarray['name'])) {
				$exists = DB::get_value('SELECT count(1) FROM {groups} WHERE name = ?', array($paramarray['name']));
				if($exists) {
					return UserGroup::get_by_name($paramarray['name']);
				}
			}
			return false;
		}
	}

	/**
	 * Save a new UserGroup to the UserGroup table
	 */
	public function insert()
	{
		$exists = DB::get_value('SELECT count(1) FROM {groups} WHERE name = ?', array($this->name));
		if($exists) {
			return false;
		}

		$allow = true;
		// plugins have the opportunity to prevent insertion
		$allow = Plugins::filter('usergroup_insert_allow', $allow, $this);
		if ( ! $allow ) {
			return false;
		}
		Plugins::act('usergroup_insert_before', $this);
		$this->exclude_fields('id');
		$result = parent::insertRecord( DB::table('groups') );
		$this->fields['id'] = DB::last_insert_id();

		$this->set_member_list();

		EventLog::log( sprintf(_t('New group created: %s'), $this->name), 'info', 'default', 'habari');
		Plugins::act('usergroup_insert_after', $this);
		return $result;
	}

	/**
	 * Updates an existing UserGroup in the DB
	 */
	public function update()
	{
		$allow = true;
		// plugins have the opportunity to prevent modification
		$allow = Plugins::filter('usergroup_update_allow', $allow, $this);
		if ( ! $allow ) {
			return false;
		}
		Plugins::act('usergroup_update_before', $this);

		$this->set_member_list();

		EventLog::log(sprintf(_t('User Group updated: %s'), $this->name), 'info', 'default', 'habari');
		Plugins::act('usergroup_update_after', $this);
	}

	/**
	 * Set the member list for this group
	 */
	protected function set_member_list()
	{
		// Remove all users from this group in preparation for adding the current list
		DB::query('DELETE FROM {users_groups} WHERE group_id=?', array( $this->id ) );
		// Add the current list of users into the group
		foreach( $this->member_ids as $user_id ) {
			DB::query('INSERT INTO {users_groups} (user_id, group_id) VALUES (?, ?)', array( $user_id, $this->id) );
		}
		EventLog::log( _t( 'User Group %s: Member list reset', array( $this->name ) ), 'notice', 'user', 'habari' );
	}

	/**
	 * Delete a UserGroup
	 */
	public function delete()
	{
		$allow = true;
		// plugins have the opportunity to prevent deletion
		$allow = Plugins::filter('usergroup_delete_allow', $allow, $this);
		 if ( ! $allow ) {
		 	return;
		}

		$name = $this->name;
		Plugins::act('usergroup_delete_before', $this);
		// remove all this group's permissions
		$results = DB::query( 'DELETE FROM {group_token_permissions} WHERE group_id=?', array( $this->id ) );
		// remove all this group's members
		$results = DB::query( 'DELETE FROM {users_groups} WHERE group_id=?', array( $this->id ) );
		// remove this group
		$result = parent::deleteRecord( DB::table('groups'), array( 'id' => $this->id ) );
		Plugins::act('usergroup_delete_after', $this);
		EventLog::log( _t( 'User Group %s: Group deleted.', array( $name ) ), 'notice', 'user', 'habari' );
		return $result;
	}

	/**
	 * function __get
	 * magic get function for returning virtual properties of the class
	 * @param mixed the property to get
	 * @return mixed the property
	 */
	public function __get( $param )
	{
		switch ( $param ) {
			case 'members':
				return (array) $this->member_ids;
				break;
			case 'permissions':
				return $this->permissions;
				break;
			default:
				return parent::__get( $param );
				break;
		}
	}

	/**
	 * Add one or more users to this group
	 * @param mixed $users a user ID or name, or an array of the same
	 */
	public function add( $users )
	{
		$users = Utils::single_array( $users );
		// Use ids internally for all users
		$user_ids = array_map(array('User', 'get_id'), $users);
		// Remove users from group membership
		$this->member_ids = array_merge( (array) $this->member_ids, (array) $user_ids);
		// List each group member exactly once
		$this->member_ids = array_unique($this->member_ids);
		$this->update();

		EventLog::log( _t( 'User Group %1$s: Users were added to the group.', array( $this->name ) ), 'notice', 'user', 'habari' );
	}

	/**
	 * Remove one or more user from this group
	 * @param mixed $users A user ID or name, or an array of the same
	 */
	public function remove( $users )
	{
		$users = Utils::single_array( $users );
		// Use ids internally for all users
		$users = array_map(array('User', 'get_id'), $users);
		// Remove users from group membership
		$this->member_ids = array_diff( $this->member_ids, $users);
		$this->update();

		EventLog::log( _t( 'User Group %1$s: Users were removed from the group.', array( $this->name ) ), 'notice', 'user', 'habari' );
	}

	/**
	 * Assign one or more new permissions to this group
	 * @param mixed A permission token ID, name, or array of the same
	 */
	public function grant( $permissions, $access = 'full' )
	{
		$permissions = Utils::single_array( $permissions );
		// Use ids internally for all permissions
		$permissions = array_map(array('ACL', 'token_id'), $permissions);

		// grant the new permissions
		foreach ( $permissions as $permission ) {
			ACL::grant_group( $this->id, $permission, $access );
			EventLog::log( _t( 'Group %1$s: Access to %2$s changed to %3$s', array( $this->name, ACL::token_name( $permission ), $access ) ), 'notice', 'user', 'habari' );
		}
	}

	/**
	 * Deny one or more permissions to this group
	 * @param mixed The permission ID or name to be denied, or an array of the same
	 */
	public function deny( $permissions )
	{
		$this->grant( $permissions, 'deny' );
	}

	/**
	 * Remove one or more permissions from a group
	 * @param mixed a permission ID, name, or array of the same
	 */
	public function revoke( $permissions )
	{
		$permissions = Utils::single_array( $permissions );
		$permissions = array_map(array('ACL', 'token_id'), $permissions);

		foreach ( $permissions as $permission ) {
			ACL::revoke_group_permission( $this->id, $permission );
			EventLog::log( _t( 'Group %1$s: Permission to %2$s revoked.', array( $this->name, ACL::token_name( $permission ) ) ), 'notice', 'user', 'habari' );
		}
	}

	/**
	 * Determine whether members of a group can do something.
	 * This function should not be used to determine composite permissions among several groups
	 * @param mixed a permission ID or name
	 * @return boolean If this group has been granted and not denied this permission, return true.  Otherwise, return false.
	 * @see ACL::group_can()
	 * @see ACL::user_can()
	 */
	public function can( $permission, $access = 'full' )
	{
		$permission = ACL::token_id( $permission );
		if ( is_null( $this->permissions ) ) {
			$this->load_permissions_cache();
		}
		if ( isset( $this->permissions[$permission] ) && ACL::access_check( $this->permissions[$permission], $access ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Return the access bitmask for a specific token for this group.
	 *
	 * @param string $token The
	 * @return
	 */
	public function get_access( $token )
	{
		$token = ACL::token_id( $token );
		if ( is_null( $this->permissions ) ) {
			$this->load_permissions_cache();
		}
		if ( isset( $this->permissions[$token] ) ) {
			return ACL::get_bitmask( $this->permissions[$token]);
		}
		return false;
	}

	/**
	 * Clear permissions cache.
	 */
	public function clear_permissions_cache()
	{
		//unset( $this->permissions );
		$this->permissions = NULL;
	}

	/**
	 * Load permissions cache.
	 */
	public function load_permissions_cache()
	{
		if ( $results = DB::get_results( 'SELECT token_id, permission_id FROM {group_token_permissions} WHERE group_id=?', array( $this->id ) ) ) {
			foreach ( $results as $result ) {
				$this->permissions[$result->token_id] = $result->permission_id;
			}
		}
	}

	/**
	 * Fetch a group from the database by ID or name.
	 * This is a wrapper for get_by_id() and get_by_name()
	 * @param mixed $group A group ID or name
	 * @return mixed UserGroup object, or boolean FALSE
	 */
	public static function get( $group )
	{
		if ( is_numeric( $group ) ) {
			return self::get_by_id( $group );
		}
		else {
			return self::get_by_name( $group );
		}
	}

	/**
	 * Select a group from the DB by its ID
	 * @param int A group ID
	 * @return mixed A UserGroup object, or boolean FALSE
	 */
	public static function get_by_id( $id )
	{
		return DB::get_row( 'SELECT * FROM {groups} WHERE id=?', array( $id ), 'UserGroup' );
	}

	/**
	 * Select a group from the DB by its name
	 * @param string A group name
	 * @return mixed A UserGroup object, or boolean FALSE
	 */
	public static function get_by_name( $name )
	{
		return DB::get_row( 'SELECT * FROM {groups} WHERE name=?', array( $name ), 'UserGroup' );
	}

	/**
	 * Determine whether a group exists
	 * @param mixed The name or ID of the group
	 * @return bool Whether the group exists or not
	 */
	public static function exists( $group )
	{
		return self::id($group) !== null;
	}

	/**
	 * Given a group's ID, return its friendly name
	 * @param int a group's ID
	 * @return string the group's name
	 */
	public static function name( $id )
	{
		$check_field = is_numeric( $id ) ? 'id' : 'name';
		$name = DB::get_value( "SELECT name FROM {groups} WHERE {$check_field}=?", array( $id ) );
		return $name;  // get_value returns false if no record is returned
	}

	/**
	 * Given a group's name, return its ID
	 * @param string a group's name
	 * @return int the group's ID
	 */
	public static function id( $name )
	{
		if( is_numeric($name) ) {
			return $name;
		}
		$id = DB::get_value( "SELECT id FROM {groups} WHERE name=?", array( $name ) );
		return $id; // get_value returns false if no record is returned
	}

	/**
	 * Determine whether the specified user is a member of the group
	 * @param mixed A user ID or name
	 * @return bool True if the user is in the group, otherwise false
	 */
	public function member( $user_id )
	{
		if ( ! is_numeric( $user_id ) ) {
			$user = User::get( $user_id );
			$user_id = $user->id;
		}

		if ( in_array( $user_id, $this->member_ids ) ) {
			return true;
		}
		return false;
	}
}
?>