<?php
if ( ! defined( 'myCRED_VERSION' ) ) exit;

/**
 * myCRED_BuddyPress_Module class
 * @since 0.1
 * @version 1.3
 */
if ( ! class_exists( 'myCRED_BuddyPress_Module' ) ) {
	class myCRED_BuddyPress_Module extends myCRED_Module {

		protected $hooks;
		protected $settings;

		/**
		 * Constructor
		 */
		public function __construct( $type = 'mycred_default' ) {
			parent::__construct( 'myCRED_BuddyPress', array(
				'module_name' => 'buddypress',
				'defaults'    => array(
					'visibility'         => array(
						'balance' => 0,
						'history' => 0
					),
					'balance_location'   => '',
					'balance_template'   => '%plural% balance:',
					'history_location'   => '',
					'history_menu_title' => array(
						'me'      => __( "My History", 'mycred' ),
						'others'  => __( "%s's History", 'mycred' )
					),
					'history_menu_pos'   => 99,
					'history_url'        => 'mycred-history',
					'history_num'        => 10
				),
				'register'    => false,
				'add_to_core' => true
			), $type );

			if ( ! is_admin() )
				add_action( 'bp_setup_nav', array( $this, 'setup_nav' ) );
		}

		/**
		 * Init
		 * @since 0.1
		 * @version 1.1
		 */
		public function module_init() {
			add_filter( 'logout_url', array( $this, 'adjust_logout' ), 99, 2 );

			$this->mycred_types = mycred_get_types();
			$this->selected_type = 'mycred_default';
			if ( isset( $_REQUEST['type'] ) && array_key_exists( $_REQUEST['type'], $this->mycred_types ) )
				$this->selected_type = $_REQUEST['type'];

			if ( $this->buddypress['balance_location'] == 'top' || $this->buddypress['balance_location'] == 'both' )
				add_action( 'bp_before_member_header_meta',  array( $this, 'show_balance' ), 10 );
 
 			if ( $this->buddypress['balance_location'] == 'profile_tab' || $this->buddypress['balance_location'] == 'both' )
				add_action( 'bp_after_profile_loop_content', array( $this, 'show_balance_profile' ), 10 );
		}

		/**
		 * Adjust Logout Link
		 * If we are logging out from the points history page, we want to make
		 * sure we are redirected away from this page when we log out. All else
		 * the default logout link is used.
		 * @since 1.3.1
		 * @version 1.1
		 */
		public function adjust_logout( $logouturl, $redirect ) {
			if ( preg_match( '/(' . $this->buddypress['history_url'] . ')/', $redirect, $match ) ) {
				global $bp;

				$url = remove_query_arg( 'redirect_to', $logouturl );
				$logouturl = add_query_arg( array( 'redirect_to' => urlencode( $bp->displayed_user->domain ) ), $url );
			}

			return apply_filters( 'mycred_bp_logout_url', $logouturl, $this );
		}

		/**
		 * Show Balance in Profile
		 * @since 0.1
		 * @version 1.4
		 */
		public function show_balance_profile() {
			// Prep
			$output = '';
			$user_id = bp_displayed_user_id();

			// Check for exclusion
			if ( $this->core->exclude_user( $user_id ) ) return;

			// Check visibility settings
			if ( ! $this->buddypress['visibility']['balance'] && ! bp_is_my_profile() && ! mycred_is_admin() ) return;
			
			// Loop though all post types
			$mycred_types = mycred_get_types();
			if ( ! empty( $mycred_types ) ) {
			
				foreach ( $mycred_types as $type => $label ) {

					// Load myCRED with this points type
					$mycred = mycred( $type );

					// Check if user is excluded from this type
					if ( $mycred->exclude_user( $user_id ) ) continue;

					// Get users balance
					$balance = $mycred->get_users_cred( $user_id, $type );

					// Output
					$template = str_replace( '%label%', $label, $template );
					$output .= sprintf( '<div class="bp-widget mycred"><h4>%s</h4><table class="profile-fields"><tr class="field_1 field_current_balance"><td class="label">%s</td><td class="data">%s</td></tr></table></div>', $mycred->plural(), __( 'Current balance', 'mycred' ), $mycred->format_creds( $balance ) );

				}
			
			}

			echo apply_filters( 'mycred_bp_profile_details', $output, $balance, $this );
		}

		/**
		 * Show Balance in Header
		 * @since 0.1
		 * @version 1.4
		 */
		public function show_balance( $dump = NULL, $context = 'header' ) {
			// Prep
			$output = '';
			$user_id = bp_displayed_user_id();

			// Check for exclusion
			if ( $this->core->exclude_user( $user_id ) ) return;

			// Check visibility settings
			if ( ! $this->buddypress['visibility']['balance'] && ! bp_is_my_profile() && ! mycred_is_admin() ) return;

			// Parse template
			$template = $this->buddypress['balance_template'];
			if ( function_exists( 'mycred_get_users_rank' ) ) {
				$rank_name = mycred_get_users_rank( $user_id );
				$template = str_replace( '%rank%',      $rank_name, $template );
				$template = str_replace( '%rank_logo%', mycred_get_rank_logo( $rank_name ), $template );
				$template = str_replace( '%ranking%',   mycred_leaderboard_position( $user_id ), $template );
			}

			$template = str_replace( array( '%ranking%', '%rank%' ), mycred_leaderboard_position( $user_id ), $template );
			$template = $this->core->template_tags_general( $template );

			// Loop though all post types
			$mycred_types = mycred_get_types();
			if ( ! empty( $mycred_types ) ) {
			
				foreach ( $mycred_types as $type => $label ) {

					// Load myCRED with this points type
					$mycred = mycred( $type );

					// Check if user is excluded from this type
					if ( $mycred->exclude_user( $user_id ) ) continue;

					// Get users balance
					$balance = $mycred->get_users_cred( $user_id, $type );

					// Output
					$template = str_replace( '%label%', $label, $template );
					$output .= '<div class="mycred-balance">' . $template . ' ' . $mycred->format_creds( $balance ) . '</div>';
					
					// Reset the label template tag to allow the next type can use it agian otherwise we
					// will show the same label for all types.
					$template = str_replace( $label, '%label%', $template );
				}
			
			}

			echo apply_filters( 'mycred_bp_profile_header', $output, $this->buddypress['balance_template'], $this );
		}

		/**
		 * Setup Navigation
		 * @since 0.1
		 * @version 1.3
		 */
		public function setup_nav() {
			global $bp;

			$user_id = bp_displayed_user_id();

			// User is excluded
			if ( $this->core->exclude_user( $user_id ) ) return;

			// If visibility is not set for visitors
			if ( ! is_user_logged_in() && ! $this->buddypress['visibility']['history'] ) return;

			// Admins always see the token history
			if ( ! $this->core->can_edit_plugin() && $this->buddypress['history_location'] != 'top' ) return;

			// Show admins
			if ( $this->core->can_edit_plugin() )
				$show = true;
			else
				$show = $this->buddypress['visibility']['history'];

			// Top Level Nav Item
			$top_name = bp_word_or_name(
				$this->buddypress['history_menu_title']['me'], 
				$this->buddypress['history_menu_title']['others'], false, false );
			$top_name = str_replace( '%label%', $this->mycred_types[ $this->selected_type ], $top_name );

			bp_core_new_nav_item( array(
				'name'                    => $this->core->template_tags_general( $top_name ),
				'slug'                    => $this->buddypress['history_url'],
				'parent_url'              => $bp->displayed_user->domain,
				'default_subnav_slug'     => $this->buddypress['history_url'],
				'screen_function'         => array( $this, 'my_history' ),
				'show_for_displayed_user' => $show,
				'position'                => $this->buddypress['history_menu_pos']
			) );

			// Date Sorting
			$date_sorting = apply_filters( 'mycred_sort_by_time', array(
				''          => __( 'All', 'mycred' ),
				'today'     => __( 'Today', 'mycred' ),
				'yesterday' => __( 'Yesterday', 'mycred' ),
				'thisweek'  => __( 'This Week', 'mycred' ),
				'thismonth' => __( 'This Month', 'mycred' )
			) );

			$query = '';
			if ( $this->selected_type != 'mycred_default' )
				$query = '?type=' . $this->selected_type;

			// "All" is default
			bp_core_new_subnav_item( array(
				'name'            => __( 'All', 'mycred' ),
				'slug'            => $this->buddypress['history_url'],
				'parent_url'      => $bp->displayed_user->domain . $this->buddypress['history_url'] . '/',
				'parent_slug'     => $this->buddypress['history_url'],
				'screen_function' => array( $this, 'my_history' )
			) );

			// Loop though and add each filter option as a sub menu item
			if ( ! empty( $date_sorting ) ) {
				foreach ( $date_sorting as $sorting_id => $sorting_name ) {
					if ( empty( $sorting_id ) ) continue;

					bp_core_new_subnav_item( array(
						'name'            => $sorting_name,
						'slug'            => $sorting_id,
						'parent_url'      => $bp->displayed_user->domain . $this->buddypress['history_url'] . '/',
						'parent_slug'     => $this->buddypress['history_url'],
						'screen_function' => array( $this, 'my_history' )
					) );
				}
			}
		}

		/**
		 * Construct My History Page
		 * @since 0.1
		 * @version 1.0
		 */
		public function my_history() {
			add_action( 'bp_template_title',         array( $this, 'my_history_title' ) );
			add_action( 'bp_template_content',       array( $this, 'my_history_screen' ) );
			add_filter( 'mycred_log_column_headers', array( $this, 'columns' ) );

			bp_core_load_template( apply_filters( 'bp_core_template_plugin', 'members/single/plugins' ) );
		}

		/**
		 * Adjust Log Columns
		 * @since 0.1
		 * @version 1.0
		 */
		public function columns( $columns ) {
			unset( $columns['column-username'] );
			return $columns;
		}

		/**
		 * My History Title
		 * @since 0.1
		 * @version 1.1
		 */
		public function my_history_title() {
			$title = bp_word_or_name(
				$this->buddypress['history_menu_title']['me'],
				$this->buddypress['history_menu_title']['others'],
				false,
				false
			);
			$title = $this->core->template_tags_general( $title );
			$title = str_replace( '%label%', $this->mycred_types[ $this->selected_type ], $title );

			echo apply_filters( 'mycred_br_history_page_title', $title, $this );
		}

		/**
		 * My History Content
		 * @since 0.1
		 * @version 1.2
		 */
		public function my_history_screen() {
			global $bp;

			$mycred_types = mycred_get_types();
			$type = 'mycred_default';
			if ( isset( $_REQUEST['type'] ) && array_key_exists( $_REQUEST['type'], $mycred_types ) )
				$type = $_REQUEST['type'];

			$args = array(
				'user_id' => bp_displayed_user_id(),
				'number'  => apply_filters( 'mycred_bp_history_num_to_show', $this->buddypress['history_num'] ),
				'ctype'   => $type
			);

			if ( isset( $bp->canonical_stack['action'] ) && $bp->canonical_stack['action'] != $this->buddypress['history_url'] )
				$args['time'] = $bp->canonical_stack['action'];

			$log = new myCRED_Query_Log( $args );
			unset( $log->headers['column-username'] );
			
			ob_start();
			
			if ( count( $mycred_types ) > 1 ) : ?>

<form action="" method="get" style="display: block; height: 48px; float: right;"><label>Show:</label> <?php mycred_types_select_from_dropdown( 'type', 'mycred-select-type', $type ); ?> <input type="submit" class="btn btn-large btn-primary button button-large button-primary" value="<?php _e( 'Go', 'mycred' ); ?>" /></form>
<?php		endif; ?>

<div class="wrap" id="myCRED-wrap">
	<?php $log->mobile_support(); ?>

	<form method="get" action="">
		<?php $log->display(); ?>

	</form>
</div>
<?php
			$log->reset_query();
			
			$output = ob_get_contents();
			ob_end_clean();
			
			echo apply_filters( 'mycred_bp_history_page', $output, $this );
		}

		/**
		 * After General Settings
		 * @since 0.1
		 * @version 1.3
		 */
		public function after_general_settings() {
			// Settings
			global $bp;

			$settings = $this->buddypress;

			$balance_locations = array(
				''            => __( 'Do not show.', 'mycred' ),
				'top'         => __( 'Include in Profile Header.', 'mycred' ),
				'profile_tab' => __( 'Include under the "Profile" tab', 'mycred' ),
				'both'        => __( 'Include under the "Profile" tab and Profile Header.', 'mycred' )
			);

			$history_locations = array(
				''    => __( 'Do not show.', 'mycred' ),
				'top' => __( 'Show in Profile', 'mycred' )
			);

			$bp_nav_positions = array();
			if ( isset( $bp->bp_nav ) ) {
				foreach ( $bp->bp_nav as $pos => $data ) {
					if ( ! isset( $data['slug'] ) || $data['slug'] == $settings['history_url'] ) continue; 
					$bp_nav_positions[] = ucwords( $data['slug'] ) . ' = ' . $pos;
				}
			} ?>

<h4><div class="icon icon-hook icon-active"></div><label><?php _e( 'BuddyPress', 'mycred' ); ?></label></h4>
<div class="body" style="display:none;">
	<?php do_action( 'mycred_bp_before_settings', $this ); ?>

	<label class="subheader" for="<?php echo $this->field_id( 'balance_location' ); ?>"><?php echo $this->core->template_tags_general( __( '%singular% Balance', 'mycred' ) ); ?></label>
	<ol>
		<li>
			<select name="<?php echo $this->field_name( 'balance_location' ); ?>" id="<?php echo $this->field_id( 'balance_location' ); ?>">
<?php
			foreach ( $balance_locations as $location => $description ) { 
				echo '<option value="' . $location . '"';
				if ( isset( $settings['balance_location'] ) && $settings['balance_location'] == $location ) echo ' selected="selected"';
				echo '>' . $description . '</option>';
			}
?>

			</select>
		</li>
		<li>
			<input type="checkbox" name="<?php echo $this->field_name( array( 'visibility' => 'balance' ) ); ?>" id="<?php echo $this->field_id( array( 'visibility' => 'balance' ) ); ?>" <?php checked( $settings['visibility']['balance'], 1 ); ?> value="1" />
			<label for="<?php echo $this->field_id( array( 'visibility' => 'balance' ) ); ?>"><?php echo $this->core->template_tags_general( __( 'Members and visitors can other members %_singular% balance.', 'mycred' ) ); ?></label>
		</li>
	</ol>
	<ol>
		<li>
			<label for="<?php echo $this->field_id( 'balance_template' ); ?>"><?php _e( 'Template', 'mycred' ); ?></label>
			<div class="h2"><input type="text" name="<?php echo $this->field_name( 'balance_template' ); ?>" id="<?php echo $this->field_id( 'balance_template' ); ?>" value="<?php echo $settings['balance_template']; ?>" class="long" /></div>
			<span class="description"><?php echo $this->core->available_template_tags( array( 'general' ), '%rank%' ); ?></span>
			<?php if ( function_exists( 'mycred_get_users_rank' ) ) echo '<br /><span class="description">' . __( 'Note that you can also use %rank_logo% to show the feature image of the rank.', 'mycred' ) . '</span>'; ?>

		</li>
	</ol>
	<label class="subheader" for="<?php echo $this->field_id( 'history_location' ); ?>"><?php echo $this->core->template_tags_general( __( '%plural% History', 'mycred' ) ); ?></label>
	<ol>
		<li>
			<select name="<?php echo $this->field_name( 'history_location' ); ?>" id="<?php echo $this->field_id( 'history_location' ); ?>">
<?php
			foreach ( $history_locations as $location => $description ) { 
				echo '<option value="' . $location . '"';
				if ( isset( $settings['history_location'] ) && $settings['history_location'] == $location ) echo ' selected="selected"';
				echo '>' . $description . '</option>';
			}
?>

			</select>
		</li>
		<li>
			<input type="checkbox" name="<?php echo $this->field_name( array( 'visibility' => 'history' ) ); ?>" id="<?php echo $this->field_id( array( 'visibility' => 'history' ) ); ?>" <?php checked( $settings['visibility']['history'], 1 ); ?> value="1" />
			<label for="<?php echo $this->field_id( array( 'visibility' => 'history' ) ); ?>"><?php echo $this->core->template_tags_general( __( 'Members can view each others %_plural% history.', 'mycred' ) ); ?></label>
		</li>
	</ol>
	<ol class="inline">
		<li>
			<label for="<?php echo $this->field_id( array( 'history_menu_title' => 'me' ) ); ?>"><?php _e( 'Menu Title', 'mycred' ); ?></label>
			<div class="h2"><input type="text" name="<?php echo $this->field_name( array( 'history_menu_title' => 'me' ) ); ?>" id="<?php echo $this->field_id( array( 'history_menu_title' => 'me' ) ); ?>" value="<?php echo $settings['history_menu_title']['me']; ?>" size="25" /></div>
			<span class="description"><?php _e( 'Title shown to me', 'mycred' ); ?></span>
		</li>
		<li>
			<label>&nbsp;</label>
			<div class="h2"><input type="text" name="<?php echo $this->field_name( array( 'history_menu_title' => 'others' ) ); ?>" id="<?php echo $this->field_id( array( 'history_menu_title' => 'others' ) ); ?>" value="<?php echo $settings['history_menu_title']['others']; ?>" size="25" /></div>
			<span class="description"><?php _e( 'Title shown to others. Use %s to show the first name.', 'mycred' ); ?></span>
		</li>
	</ol>
	<ol>
		<li>
			<label for="<?php echo $this->field_id( 'history_menu_pos' ); ?>"><?php _e( 'Menu Position', 'mycred' ); ?></label>
			<div class="h2"><input type="text" name="<?php echo $this->field_name( 'history_menu_pos' ); ?>" id="<?php echo $this->field_id( 'history_menu_pos' ); ?>" value="<?php echo $settings['history_menu_pos']; ?>" class="short" /></div>
			<span class="description"><?php echo __( 'Current menu positions:', 'mycred' ) . ' ' . implode( ', ', $bp_nav_positions ); ?></span>
		</li>
	</ol>
	<ol>
		<li>
			<label for="<?php echo $this->field_id( 'history_url' ); ?>"><?php _e( 'History URL slug', 'mycred' ); ?></label>
			<div class="h2">/ <input type="text" name="<?php echo $this->field_name( 'history_url' ); ?>" id="<?php echo $this->field_id( 'history_url' ); ?>" value="<?php echo $settings['history_url']; ?>" class="medium" />/</div>
			<span class="description"><?php echo __( 'Do not use empty spaces!', 'mycred' ); ?></span>
		</li>
	</ol>
	<ol>
		<li>
			<label for="<?php echo $this->field_id( 'history_num' ); ?>"><?php _e( 'Number of history entries to show', 'mycred' ); ?></label>
			<div class="h2"><input type="text" name="<?php echo $this->field_name( 'history_num' ); ?>" id="<?php echo $this->field_id( 'history_num' ); ?>" value="<?php echo $settings['history_num']; ?>" class="short" /></div>
		</li>
	</ol>
	<?php do_action( 'mycred_bp_after_settings', $this ); ?>

</div>
<?php
		}

		/**
		 * Sanitize Core Settings
		 * @since 0.1
		 * @version 1.2
		 */
		public function sanitize_extra_settings( $new_data, $data, $core ) {
			$new_data['buddypress']['balance_location'] = sanitize_text_field( $data['buddypress']['balance_location'] );
			$new_data['buddypress']['visibility']['balance'] = ( isset( $data['buddypress']['visibility']['balance'] ) ) ? true : false;

			$new_data['buddypress']['history_location'] = sanitize_text_field( $data['buddypress']['history_location'] );
			$new_data['buddypress']['balance_template'] = sanitize_text_field( $data['buddypress']['balance_template'] );

			$new_data['buddypress']['history_menu_title']['me'] = sanitize_text_field( $data['buddypress']['history_menu_title']['me'] );
			$new_data['buddypress']['history_menu_title']['others'] = sanitize_text_field( $data['buddypress']['history_menu_title']['others'] );
			$new_data['buddypress']['history_menu_pos'] = abs( $data['buddypress']['history_menu_pos'] );

			$url = sanitize_text_field( $data['buddypress']['history_url'] );
			$new_data['buddypress']['history_url'] = urlencode( $url );
			$new_data['buddypress']['history_num'] = abs( $data['buddypress']['history_num'] );

			$new_data['buddypress']['visibility']['history'] = ( isset( $data['buddypress']['visibility']['history'] ) ) ? true : false;

			return apply_filters( 'mycred_bp_sanitize_settings', $new_data, $data, $core );
		}
	}

}
?>