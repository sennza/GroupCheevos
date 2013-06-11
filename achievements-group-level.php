<?php
/**
 * Plugin Name: BuddyPress Group Achievements
 * Description: Encourage collaboration on your BuddyPress site with group achievements
 *
 * Version: 1.0
 * Author: Sennza
 * Author URI: http://www.sennza.com.au/
 *
 * Requires at least: 3.5.1
 * Tested up to: 3.6
 * License: GPLv3
 */

Sennza_GroupCheevos::bootstrap();

class Sennza_GroupCheevos {
	/**
	 * Do we have all our requirements?
	 * @var boolean
	 */
	protected static $has_requirements = false;

	/**
	 * Setup the actions and filters for the plugin
	 */
	public static function bootstrap() {
		add_action( 'plugins_loaded',         array( __CLASS__, 'check_requirements' ) );
		add_action( 'bp_group_header_meta',   array( __CLASS__, 'print_group_achievements' ) );
		add_action( 'dpa_unlock_achievement', array( __CLASS__, 'maybe_unlock_group_achievement' ), 10, 2 );
		add_filter( 'cmb_meta_boxes',         array( __CLASS__, 'ts_group_bonus_metabox' ) );
	}

	public static function check_requirements() {
		self::$has_requirements = (
			class_exists( 'DPA_Achievements_Loader' )
			&& function_exists( 'bp_is_active' )
			&& bp_is_active( 'groups' )
		);

		if ( ! self::$has_requirements )
			add_action( 'admin_notices', array( __CLASS__, 'print_requirement_warning' ) );

		// Load the metabox handler
		if ( ! function_exists( 'cmb_init' ) )
			require_once dirname( __FILE__ ) . '/metabox/custom-meta-boxes.php';
	}

	public static function print_requirement_warning() {
?>
		<div class="error">
			<p>
<?php
		switch ( false ) {
			case class_exists( 'DPA_Achievements_Loader' ):
				$message = __( 'BP Group Level Achievements requires Achievements to be installed.', 'sennza_groupcheevos' );
				$instruction = __( 'Please <a href="%s">download Achievements</a> to get started.', 'sennza_groupcheevos' );
				$url = 'http://achievementsapp.com/';
				break;

			case function_exists( 'bp_is_active' ):
				$message = __( 'BP Group Level Achievements requires BuddyPress to be installed.', 'sennza_groupcheevos' );
				$instruction = __( 'Please <a href="%s">download BuddyPress</a> to get started.', 'sennza_groupcheevos' );
				$url = 'http://buddypress.org/';
				break;

			case bp_is_active( 'groups' ):
				$message = __( 'BP Group Level Achievements requires BuddyPress Groups to be activated.', 'sennza_groupcheevos' );
				$instruction = __( 'Please <a href="%s">activate Groups</a> to get started.', 'sennza_groupcheevos' );
				$url = admin_url('options-general.php?page=bp-components');
				break;
		}


		echo $message . '<br />' . sprintf( $instruction, $url );
?>
			</p>
		</div>
<?php
	}

	/**
	 * Get the members of a group who have not yet unlocked an achievement
	 *
	 * @param array|int $members Either an array of users keyed by ID, or a group ID
	 * @param int $achivement_id Achievement ID
	 * @return array Members who haven't unlocked the achivement
	 */
	public static function remaining_group_members( $members, $achievement_id ) {
		// Bail if we don't have Achievements or Groups
		if ( ! self::$has_requirements )
			return array();

		if ( ! is_array( $members ) ) {
			$members = self::get_group_members( $members );
		}
		$query = array(
			// Get data for all users
			'author' => implode( ',', array_keys( $members ) ),

			// Only get progress for the 
			'post_parent' => $achievement_id,
		);
		if ( ! dpa_has_progress( $query ) )
			return $members;

		while ( dpa_progress() ) {
			dpa_the_progress();

			$member = dpa_get_progress_author_id();

			if ( isset( $members[ $member ] ) )
				unset( $members[ $member ] );
		}

		return $members;
	}

	/**
	 * Has a group collectively unlocked an achievement?
	 *
	 * @param array|int $members Either an array of users keyed by ID, or a group ID
	 * @param int $achivement_id Achievement ID
	 * @return boolean Has everyone unlocked the achievement?
	 */
	public static function group_has_achievement( $members, $achievement_id ) {
		// Bail if we don't have Achievements or Groups
		if ( ! self::$has_requirements )
			return false;

		return count( self::remaining_group_members( $members, $achievement_id ) ) === 0;
	}

	/**
	 * Output the group achievement progress
	 */
	public static function print_group_achievements() {
		// Bail if we don't have Achievements or Groups
		if ( ! self::$has_requirements )
			return;

		global $groups_template;
		$achievements = dpa_get_achievements();

		$members = self::get_group_members( $groups_template->group->id );

		$total = count( $members );

		echo '<div class="sennza_groupcheevos_progress">';
		echo '<h2>' . __( 'Group Achievement Progress', 'sennza_groupcheevos' ) . '</h2><ul>';

		foreach ($achievements as $achievement) {
			$remaining = count( self::remaining_group_members( $members, $achievement->ID ) );
			$percentage = round(1 - ( $remaining / $total ), 2 ) * 100;
			echo '<li><strong class="achievement_name">' . $achievement->post_title
				. '</strong>: <span class="achievement_percentage">' . $percentage . '%</span></li>';
		}

		echo '</ul></div>';
	}

	/**
	 * Unlock the group achievement if all members now have the base achievement
	 *
	 * @param WP_Post $achievement_obj Achievement the user just unlocked
	 * @param int $user_id Which user just unlocked the achievement
	 */
	public static function maybe_unlock_group_achievement( WP_Post $achievement_obj, $user_id ) {
		// Bail if we don't have Achievements or Groups
		if ( ! self::$has_requirements )
			return;

		// Does the achievement have a bonus for group completion?
		if (empty($achievement_obj->_sennza_groupcheevos_cheevo_id)) {
			return;
		}
		$bonus = $achievement_obj->_sennza_groupcheevos_cheevo_id;

		// Loop over all groups that the member is in
		$groups = BP_Groups_Member::get_group_ids( $user_id );

		foreach ($groups['groups'] as $group) {
			$members = self::get_group_members( $group );

			// Check that everyone now has the achievement
			if ( ! self::group_has_achievement( $members, $achievement_obj->ID ) )
				continue;

			// Make sure we haven't already unlocked it
			if ( self::group_has_achievement( $members, $bonus ) )
				continue;

			// Late load the bonus object for performance reasons
			if ( empty( $bonus_obj ) ) {
				$bonus_obj = get_post($bonus);
			}

			// Award our group achievement to all members
			foreach ( $members as $member_id => $data ) {
				dpa_maybe_unlock_achievement( $member_id, 'skip_validation', null, $bonus_obj );
			}
		}
	}

	/**
	 * Get the members of a group
	 *
	 * @param int $group_id Group ID
	 * @return array Member WP_User objects, keyed by their user ID
	 */
	public static function get_group_members( $group_id ) {
		// Bail if we don't have Achievements or Groups
		if ( ! self::$has_requirements )
			return array();

		$group_data = BP_Groups_Member::get_all_for_group( $group_id );
		$members = array();

		foreach ($group_data['members'] as $member) {
			$members[$member->user_id] = $member;
		}
		return $members;
	}

	public static function register_metabox( $boxes ) {
		// Bail if we don't have Achievements or Groups
		if ( ! self::$has_requirements )
			return $boxes;

		$boxes[] = array(
			'id' => 'sennza_groupcheevos',
			'title' => __( 'Group Achievements', 'sennza_groupcheevos' ),
			'pages' => dpa_get_achievement_post_type(),
			'context' => 'normal',
			'priority' => 'default',
			'fields' => array(
				array(
					'name' => __( 'Group Completion Achievement:', 'sennza_groupcheevos' ),
					'desc' => __( 'Once all members of a group get an achievement, award this to all members.', 'sennza_groupcheevos' ),
					'id' => '_sennza_groupcheevos_cheevo_id',
					'type' => 'post_select',
					'use_ajax' => true,
					'query' => array( 'showposts' => -1, 'post_type' =>  dpa_get_achievement_post_type())
				),
			),
		);
		return $boxes;
	}
}
