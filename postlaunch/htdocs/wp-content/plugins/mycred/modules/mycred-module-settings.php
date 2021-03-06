<?php
if ( ! defined( 'myCRED_VERSION' ) ) exit;

/**
 * myCRED_Settings_Module class
 * @since 0.1
 * @version 1.2
 */
if ( ! class_exists( 'myCRED_Settings_Module' ) ) {
	class myCRED_Settings_Module extends myCRED_Module {

		/**
		 * Construct
		 */
		function __construct( $type = 'mycred_default' ) {
			parent::__construct( 'myCRED_Settings_Module', array(
				'module_name' => 'general',
				'option_id'   => 'mycred_pref_core',
				'labels'      => array(
					'menu'        => __( 'Settings', 'mycred' ),
					'page_title'  => __( 'Settings', 'mycred' ),
					'page_header' => __( 'Settings', 'mycred' )
				),
				'screen_id'   => 'myCRED_page_settings',
				'accordion'   => true,
				'menu_pos'    => 99
			), $type );
		}

		/**
		 * Admin Init
		 * @since 1.3
		 * @version 1.0
		 */
		public function module_admin_init() {
			if ( isset( $_GET['do'] ) && $_GET['do'] == 'export' )
				$this->load_export();

			add_action( 'wp_ajax_mycred-action-empty-log',       array( $this, 'action_empty_log' ) );
			add_action( 'wp_ajax_mycred-action-empty-log',       array( $this, 'action_clean_up_log' ) );
			add_action( 'wp_ajax_mycred-action-reset-accounts',  array( $this, 'action_reset_balance' ) );
			add_action( 'wp_ajax_mycred-action-export-balances', array( $this, 'action_export_balances' ) );
			add_action( 'wp_ajax_mycred-action-generate-key',    array( $this, 'action_generate_key' ) );

			// Delete users log entries when the user is deleted
			if ( isset( $this->core->delete_user ) && $this->core->delete_user )
				add_action( 'delete_user', array( $this, 'action_delete_users_log_entries' ) );
		}

		/**
		 * Empty Log Action
		 * @since 1.3
		 * @version 1.2
		 */
		public function action_empty_log() {
			// Security
			check_ajax_referer( 'mycred-management-actions', 'token' );

			// Access
			if ( ! is_user_logged_in() || ! $this->core->can_edit_plugin() )
				wp_send_json_error( __( 'Access denied for this action', 'mycred' ) );

			if ( ! isset( $_POST['type'] ) )
				wp_send_json_error( __( 'Missing point type', 'mycred' ) );

			$mycred_types = mycred_get_types();
			$type = sanitize_text_field( $_POST['type'] );

			global $wpdb;

			// If we only have one point type we truncate the log
			if ( count( $mycred_types ) == 1 && $type == 'mycred_default' )
				$wpdb->query( "TRUNCATE TABLE {$this->core->log_table};" );

			// Else we want to delete the selected point types only
			else
				$wpdb->delete(
					$this->core->log_table,
					array( 'ctype' => $type ),
					array( '%s' )
				);

			// Count results
			$total_rows = $wpdb->get_var( "SELECT COUNT(1) FROM {$this->core->log_table} WHERE ctype = '{$type}';" );
			$wpdb->flush();

			// Response
			wp_send_json_success( $total_rows );
		}

		/**
		 * Reset All Balances Action
		 * @since 1.3
		 * @version 1.2
		 */
		public function action_reset_balance() {
			// Security
			check_ajax_referer( 'mycred-management-actions', 'token' );

			// Access
			if ( ! is_user_logged_in() || ! $this->core->can_edit_plugin() )
				wp_send_json_error( __( 'Access denied for this action', 'mycred' ) );

			if ( ! isset( $_POST['type'] ) )
				wp_send_json_error( __( 'Missing point type', 'mycred' ) );

			$mycred_types = mycred_get_types();
			$type = sanitize_text_field( $_POST['type'] );

			global $wpdb;

			if ( ! isset( $this->core->format['decimals'] ) )
				$decimals = $this->core->core['format']['decimals'];
			else
				$decimals = $this->core->format['decimals'];

			if ( $decimals > 0 )
				$format = 'CAST( %f AS DECIMAL( 10, ' . $decimals . ' ) )';
			else
				$format = '%d';

			$wpdb->query( $wpdb->prepare( "
				UPDATE {$wpdb->usermeta} 
				SET meta_value = {$format} 
				WHERE meta_key = %s;", $this->core->zero(), $type ) );

			// Response
			wp_send_json_success( __( 'Accounts successfully reset', 'mycred' ) );
		}

		/**
		 * Export User Balances
		 * @filter mycred_export_raw
		 * @since 1.3
		 * @version 1.1
		 */
		public function action_export_balances() {
			// Security
			check_ajax_referer( 'mycred-management-actions', 'token' );

			global $wpdb;

			// Log Template
			$log = sanitize_text_field( $_POST['log_temp'] );

			// Identify users by
			switch ( $_POST['identify'] ) {
				case 'ID' :

					$SQL = "SELECT user_id AS user, meta_value AS balance FROM {$wpdb->usermeta} WHERE meta_key = %s;";

				break;
				case 'email' :

					$SQL = "SELECT user_email AS user, meta_value AS balance FROM {$wpdb->usermeta} LEFT JOIN {$wpdb->users} ON {$wpdb->usermeta}.user_id = {$wpdb->users}.ID WHERE {$wpdb->usermeta}.meta_key = %s;";

				break;
				case 'login' :

					$SQL = "SELECT user_login AS user, meta_value AS balance FROM {$wpdb->usermeta} LEFT JOIN {$wpdb->users} ON {$wpdb->usermeta}.user_id = {$wpdb->users}.ID WHERE {$wpdb->usermeta}.meta_key = %s;";

				break;
			}

			$query = $wpdb->get_results( $wpdb->prepare( $SQL, $this->mycred_type ) );

			if ( empty( $query ) )
				wp_send_json_error( __( 'No users found to export', 'mycred' ) );

			$array = array();
			foreach ( $query as $result ) {
				$data = array(
					'mycred_user'   => $result->user,
					'mycred_amount' => $this->core->number( $result->balance )
				);

				if ( ! empty( $log ) )
					$data = array_merge_recursive( $data, array( 'mycred_log' => $log ) );

				$array[] = $data;
			}

			set_transient( 'mycred-export-raw', apply_filters( 'mycred_export_raw', $array ), 3000 );

			// Response
			wp_send_json_success( admin_url( 'admin.php?page=myCRED_page_settings&do=export' ) );
		}

		/**
		 * Generate Key Action
		 * @since 1.3
		 * @version 1.1
		 */
		public function action_generate_key() {
			// Security
			check_ajax_referer( 'mycred-management-actions', 'token' );

			// Response
			wp_send_json_success( wp_generate_password( 14, true, true ) );
		}

		/**
		 * Load Export
		 * Creates a CSV export file of the 'mycred-export-raw' transient.
		 * @since 1.3
		 * @version 1.1
		 */
		public function load_export() {
			// Security
			if ( $this->core->can_edit_plugin() ) {

				$export = get_transient( 'mycred-export-raw' );
				if ( $export === false ) return;

				if ( isset( $export[0]['mycred_log'] ) )
					$headers = array( 'mycred_user', 'mycred_amount', 'mycred_log' );
				else
					$headers = array( 'mycred_user', 'mycred_amount' );	

				require_once( myCRED_ASSETS_DIR . 'libs/parsecsv.lib.php' );
				$csv = new parseCSV();

				delete_transient( 'mycred-export-raw' );
				$csv->output( true, 'mycred-balance-export.csv', $export, $headers );
				die();
			}
		}

		/**
		 * Delete Users Log Entries
		 * Will remove a given users log entries.
		 * @since 1.4
		 * @version 1.0
		 */
		public function action_delete_users_log_entries( $user_id ) {
			global $wpdb;

			$wpdb->delete(
				$this->core->log_table,
				array( 'user_id' => $user_id, 'ctype' => $this->mycred_type ),
				array( '%d' )
			);
		}

		/**
		 * Settings Header
		 * Inserts the export styling
		 * @since 1.3
		 * @version 1.1
		 */
		public function settings_header() {
			global $wp_filter;

			// Allows to link to the settings page with a defined module to be opened
			// in the accordion. Request must be made under the "open-tab" key and should
			// be the module name in lowercase with the myCRED_ removed.
			$this->accordion_tabs = array( 'core' => 0, 'management' => 1, 'point-types' => 2 );

			// Check if there are registered action hooks for mycred_after_core_prefs
			if ( isset( $wp_filter['mycred_after_core_prefs'] ) ) {
				$count = count( $this->accordion_tabs );

				// If remove access is enabled
				$settings = mycred_get_remote();
				if ( $settings['enabled'] )
					$this->accordion_tabs['remote'] = $count++;

				foreach ( $wp_filter['mycred_after_core_prefs'] as $priority ) {
					foreach ( $priority as $key => $data ) {
						if ( ! isset( $data['function'] ) ) continue;
						foreach ( $data['function'] as $id => $object ) {
							if ( isset( $object->module_id ) ) {
								$module_id = str_replace( 'myCRED_', '', $object->module_id );
								$module_id = strtolower( $module_id );
								$this->accordion_tabs[ $module_id ] = $count++;
							}
						}
					}
				}
			}

			// If the requested tab exists, localize the accordion script to open this tab.
			// For this to work, the variable "active" must be set to the position of the
			// tab starting with zero for "Core".
			if ( isset( $_REQUEST['open-tab'] ) && array_key_exists( $_REQUEST['open-tab'], $this->accordion_tabs ) )
				wp_localize_script( 'mycred-admin', 'myCRED', array( 'active' => $this->accordion_tabs[ $_REQUEST['open-tab'] ] ) );
		
			wp_enqueue_script( 'mycred-manage' );
			wp_enqueue_style( 'mycred-inline-edit' );
		}

		/**
		 * Admin Page
		 * @since 0.1
		 * @version 1.4
		 */
		public function admin_page() {
			// Security
			if ( ! $this->core->can_edit_plugin() )
				wp_die( __( 'Access Denied', 'mycred' ) );

			// General Settings
			$general = $this->general;

			$action_hook = '';
			if ( ! $this->is_main_type )
				$action_hook = $this->mycred_type;

			$delete_user = 0;
			if ( isset( $this->core->delete_user ) )
				$delete_user = $this->core->delete_user;

			// Social Media Links
			$facebook = '<a href="https://www.facebook.com/myCRED" class="facebook" target="_blank">' . __( 'Facebook', 'mycred' ) . '</a>';
			$google = '<a href="https://plus.google.com/+MycredMe/posts" class="googleplus" target="_blank">' . __( 'Google Plus', 'mycred' ) . '</a>'; ?>

<div class="wrap list" id="myCRED-wrap">
	<h2><?php echo sprintf( __( '%s Settings', 'mycred' ), mycred_label() ); ?> <?php echo myCRED_VERSION; ?></h2>
	<?php $this->update_notice(); ?>

	<p><?php echo sprintf( __( 'Adjust your core or add-on settings. Follow us on: %s %s', 'mycred' ), $facebook, $google ); ?></p>
	<form method="post" action="options.php">
		<?php settings_fields( $this->settings_name ); ?>

		<div class="list-items expandable-li" id="accordion">
			<h4><div class="icon icon-inactive core"></div><label><?php _e( 'Core Settings', 'mycred' ); ?></label></h4>
			<div class="body" style="display:none;">
				<label class="subheader"><?php _e( 'Name', 'mycred' ); ?></label>
				<ol id="myCRED-settings-name" class="inline">
					<li>
						<label for="<?php echo $this->field_id( array( 'name' => 'singular' ) ); ?>"><?php _e( 'Name (Singular)', 'mycred' ); ?></label>
						<div class="h2"><input type="text" name="<?php echo $this->field_name( array( 'name' => 'singular' ) ); ?>" id="<?php echo $this->field_id( array( 'name' => 'singular' ) ); ?>" value="<?php echo $this->core->name['singular']; ?>" size="30" /></div>
						<div class="description"><?php _e( 'Accessible though the %singular% template tag.', 'mycred' ); ?></div>
					</li>
					<li>
						<label for="<?php echo $this->field_id( array( 'name' => 'plural' ) ); ?>"><?php _e( 'Name (Plural)', 'mycred' ); ?></label>
						<div class="h2"><input type="text" name="<?php echo $this->field_name( array( 'name' => 'plural' ) ); ?>" id="<?php echo $this->field_id( array( 'name' => 'plural' ) ); ?>" value="<?php echo $this->core->name['plural']; ?>" size="30" /></div>
						<div class="description"><?php _e( 'Accessible though the %plural% template tag.', 'mycred' ); ?></div>
					</li>
					<li class="block">
						<span class="description"><strong><?php _e( 'Tip', 'mycred' ); ?>:</strong> <?php _e( 'Adding an underscore at the beginning of template tag for names will return them in lowercase. i.e. %_singular%', 'mycred' ); ?></span>
					</li>
				</ol>
				<label class="subheader"><?php _e( 'Presentation', 'mycred' ); ?></label>
				<ol id="myCRED-settings-layout" class="inline">
					<li>
						<label for="<?php echo $this->field_id( 'before' ); ?>"><?php _e( 'Prefix', 'mycred' ); ?></label>
						<div class="h2"><input type="text" size="5" name="<?php echo $this->field_name( 'before' ); ?>" id="<?php echo $this->field_id( 'before' ); ?>" value="<?php echo $this->core->before; ?>" /></div>
					</li>
					<li>
						<label>&nbsp;</label>
						<div class="h2"><?php echo $this->core->format_number( 1000 ); ?></div>
					</li>
					<li>
						<label for="<?php echo $this->field_id( 'after' ); ?>"><?php _e( 'Suffix', 'mycred' ); ?></label>
						<div class="h2"><input type="text" size="5" name="<?php echo $this->field_name( 'after' ); ?>" id="<?php echo $this->field_id( 'after' ); ?>" value="<?php echo $this->core->after; ?>" /></div>
					</li>
					<li class="block">
						<label for="myCRED-prefix"><?php echo _n( 'Separator', 'Separators', ( (int) $this->core->format['decimals'] > 0 ) ? 2 : 1, 'mycred' ); ?></label>
						<div class="h2">1 <input type="text" size="1" maxlength="1" name="<?php echo $this->field_name( array( 'format' => 'separators' ) ); ?>[thousand]" id="<?php echo $this->field_id( array( 'format' => 'separators' ) ); ?>-thousand" value="<?php echo $this->core->format['separators']['thousand']; ?>" /> 000 <input type="<?php if ( (int) $this->core->format['decimals'] > 0 ) echo 'text'; else echo 'hidden'; ?>" size="1" maxlength="1" name="<?php echo $this->field_name( array( 'format' => 'separators' ) ); ?>[decimal]" id="<?php echo $this->field_id( array( 'format' => 'separators' ) ); ?>-decimal" value="<?php echo $this->core->format['separators']['decimal']; ?>" /><?php if ( (int) $this->core->format['decimals'] > 0 ) echo ' ' . str_repeat( '0', $this->core->format['decimals'] ); ?></div>
					</li>
				</ol>
				<label class="subheader"><?php _e( 'Security', 'mycred' ); ?></label>
				<ol id="myCRED-settings-security" class="inline">
					<li>
						<label for="<?php echo $this->field_id( array( 'caps' => 'plugin' ) ); ?>"><?php _e( 'Edit Settings', 'mycred' ); ?></label>
						<div class="h2"><input type="text" name="<?php echo $this->field_name( array( 'caps' => 'plugin' ) ); ?>" id="<?php echo $this->field_id( array( 'caps' => 'plugin' ) ); ?>" value="<?php echo $this->core->caps['plugin']; ?>" size="30" /></div>
						<div class="description"><?php _e( 'Capability to check for.', 'mycred' ); ?></div>
					</li>
					<li>
						<label for="<?php echo $this->field_id( array( 'caps' => 'creds' ) ); ?>"><?php echo $this->core->template_tags_general( __( 'Edit Users %plural%', 'mycred' ) ); ?></label>
						<div class="h2"><input type="text" name="<?php echo $this->field_name( array( 'caps' => 'creds' ) ); ?>" id="<?php echo $this->field_id( array( 'caps' => 'creds' ) ); ?>" value="<?php echo $this->core->caps['creds']; ?>" size="30" /></div>
						<div class="description"><?php _e( 'Capability to check for.', 'mycred' ); ?></div>
					</li>
					<li class="block"><?php if ( ! isset( $this->core->max ) ) $this->core->max(); ?>
						<label for="<?php echo $this->field_id( 'max' ); ?>"><?php echo $this->core->template_tags_general( __( 'Maximum %plural% payouts', 'mycred' ) ); ?></label>
						<div class="h2"><input type="text" name="<?php echo $this->field_name( 'max' ); ?>" id="<?php echo $this->field_id( 'max' ); ?>" value="<?php echo $this->core->max; ?>" size="8" /></div>
						<div class="description"><?php _e( 'As an added security, you can set the maximum amount a user can gain or loose in a single instance. If used, make sure this is the maximum amount a user would be able to transfer, buy, or spend in your store. Use zero to disable.', 'mycred' ); ?></div>
					</li>
				</ol>
				<label class="subheader"><?php _e( 'Excludes', 'mycred' ); ?></label>
				<ol id="myCRED-settings-excludes">
					<li>
						<input type="checkbox" name="<?php echo $this->field_name( array( 'exclude' => 'plugin_editors' ) ); ?>" id="<?php echo $this->field_id( array( 'exclude' => 'plugin_editors' ) ); ?>" <?php checked( $this->core->exclude['plugin_editors'], 1 ); ?> value="1" />
						<label for="<?php echo $this->field_id( array( 'exclude' => 'plugin_editors' ) ); ?>"><?php _e( 'Exclude those who can "Edit Settings".', 'mycred' ); ?></label>
					</li>
					<li>
						<input type="checkbox" name="<?php echo $this->field_name( array( 'exclude' => 'cred_editors' ) ); ?>" id="<?php echo $this->field_id( array( 'exclude' => 'cred_editors' ) ); ?>" <?php checked( $this->core->exclude['cred_editors'], 1 ); ?> value="1" />
						<label for="<?php echo $this->field_id( array( 'exclude' => 'cred_editors' ) ); ?>"><?php echo $this->core->template_tags_general( __( 'Exclude those who can "Edit Users %plural%".', 'mycred' ) ); ?></label>
					</li>
					<li class="empty">&nbsp;</li>
					<li>
						<label for="<?php echo $this->field_id( array( 'exclude' => 'list' ) ); ?>"><?php _e( 'Exclude the following user IDs:', 'mycred' ); ?></label>
						<div class="h2"><input type="text" name="<?php echo $this->field_name( array( 'exclude' => 'list' ) ); ?>" id="<?php echo $this->field_id( array( 'exclude' => 'list' ) ); ?>" value="<?php echo $this->core->exclude['list']; ?>" class="long" /></div>
						<div class="description"><?php _e( 'Comma separated list of user ids to exclude. No spaces allowed!', 'mycred' ); ?></div>
					</li>
				</ol>
				<label class="subheader"><?php _e( 'User Deletions', 'mycred' ); ?></label>
				<ol id="myCRED-settings-delete-user">
					<li>
						<input type="checkbox" name="<?php echo $this->field_name( 'delete_user' ); ?>" id="<?php echo $this->field_id( 'delete_user' ); ?>" <?php checked( $delete_user, 1 ); ?> value="1" /><label for="<?php echo $this->field_id( 'delete_user' ); ?>"><?php _e( 'Delete log entries when user is deleted.', 'mycred' ); ?></label>
					</li>
				</ol>
				<?php do_action( 'mycred_core_prefs', $this ); ?>

			</div>
<?php
			global $wpdb;

			$total_rows = $wpdb->get_var( "SELECT COUNT(1) FROM {$this->core->log_table} WHERE ctype = '{$this->mycred_type}';" );
			$reset_block = false;
			if ( get_transient( 'mycred-accounts-reset' ) !== false )
				$reset_block = true; ?>

			<h4><div class="icon icon-active core"></div><label><?php _e( 'Management', 'mycred' ); ?></label></h4>
			<div class="body" style="display:none;">
				<label class="subheader"><?php _e( 'The Log', 'mycred' ); ?></label>
				<ol id="myCRED-actions-log" class="inline">
					<li>
						<label><?php _e( 'Table Name', 'mycred' ); ?></label>
						<div class="h2"><input type="text" id="mycred-manage-table-name" disabled="disabled" value="<?php echo $this->core->log_table; ?>" class="readonly" /></div>
					</li>
					<li>
						<label><?php _e( 'Entries', 'mycred' ); ?></label>
						<div class="h2"><input type="text" id="mycred-manage-table-rows" disabled="disabled" value="<?php echo $total_rows; ?>" class="readonly short" /></div>
					</li>
					<li>
						<label><?php _e( 'Actions', 'mycred' ); ?></label>
						<div class="h2"><?php if ( ( ! is_multisite() ) || ( is_multisite() && $GLOBALS['blog_id'] == 1 ) ) { ?><input type="button" id="mycred-manage-action-empty-log" data-type="<?php echo $this->mycred_type; ?>" value="<?php _e( 'Empty Log', 'mycred' ); ?>" class="button button-large large <?php if ( $total_rows == 0 ) echo '"disabled="disabled'; else echo 'button-primary'; ?>" /><?php } ?></div>
					</li>
				</ol>
				<label class="subheader"><?php echo $this->core->plural(); ?></label>
				<ol id="myCRED-actions-cred" class="inline">
					<li>
						<label><?php _e( 'User Meta Key', 'mycred' ); ?></label>
						<div class="h2"><input type="text" disabled="disabled" value="<?php echo $this->core->cred_id; ?>" class="readonly" /></div>
					</li>
					<li>
						<label><?php _e( 'Users', 'mycred' ); ?></label>
						<div class="h2"><input type="text" disabled="disabled" value="<?php echo $this->core->count_members(); ?>" class="readonly short" /></div>
					</li>
					<li>
						<label><?php _e( 'Actions', 'mycred' ); ?></label>
						<div class="h2"><input type="button" id="mycred-manage-action-reset-accounts" data-type="<?php echo $this->mycred_type; ?>" value="<?php _e( 'Set all to zero', 'mycred' ); ?>" class="button button-large large <?php if ( $reset_block ) echo '" disabled="disabled'; else echo 'button-primary'; ?>" /> <input type="button" id="mycred-export-users-points" value="<?php _e( 'CSV Export', 'mycred' ); ?>" class="button button-large large"<?php if ( $reset_block ) echo ' disabled="disabled"'; ?> /></div>
					</li>
				</ol>
				<?php do_action( 'mycred_management_prefs' . $action_hook, $this ); ?>

			</div>
			<?php do_action( 'mycred_after_management_prefs' . $action_hook, $this ); ?>
			
			<?php

			if ( isset( $this->mycred_type ) && $this->mycred_type == 'mycred_default' ) :
				$types = mycred_get_types(); ?>

			<h4><div class="icon single"></div><label><?php _e( 'Point Types', 'mycred' ); ?></label></h4>
			<div class="body" style="display:none;">
<?php			if ( ! empty( $types ) ) {
				
					foreach ( $types as $type => $label ) {
					
						if ( $type == 'mycred_default' ) { ?>

				<label class="subheader"><?php _e( 'Default', 'mycred' ); ?></label>
				<ol id="myCRED-default-type" class="inline">
					<li>
						<label><?php _e( 'Meta Key', 'mycred' ); ?></label>
						<div class="h2"><input type="text" disabled="disabled" value="<?php echo $type; ?>" class="readonly" /></div>
					</li>
					<li>
						<label><?php _e( 'Label', 'mycred' ); ?></label>
						<div class="h2"><input type="text" disabled="disabled" value="<?php echo strip_tags( $label ); ?>" class="readonly" /></div>
					</li>
					<li>
						<label><?php _e( 'Delete', 'mycred' ); ?></label>
						<div class="h2"><input type="checkbox" disabled="disabled" class="disabled" value="<?php echo $type; ?>" /></div>
					</li>
				</ol>
<?php
						}
						else { ?>

				<label class="subheader"><?php echo $label; ?></label>
				<ol id="myCRED-<?php echo $type; ?>-type" class="inline">
					<li>
						<label><?php _e( 'Meta Key', 'mycred' ); ?></label>
						<div class="h2"><input type="text" name="mycred_pref_core[types][<?php echo $type; ?>][key]" value="<?php echo $type; ?>" class="medium" /></div>
					</li>
					<li>
						<label><?php _e( 'Label', 'mycred' ); ?></label>
						<div class="h2"><input type="text" name="mycred_pref_core[types][<?php echo $type; ?>][label]" value="<?php echo strip_tags( $label ); ?>" class="medium" /></div>
					</li>
					<li>
						<label><?php _e( 'Delete', 'mycred' ); ?></label>
						<div class="h2"><input type="checkbox" name="mycred_pref_core[delete_types][]" value="<?php echo $type; ?>" /></div>
					</li>
				</ol>
<?php
						}
					
					}
				
				} ?>

				<label class="subheader"><?php _e( 'Add New Type', 'mycred' ); ?></label>
				<ol id="myCRED-add-new-type" class="inline">
					<li>
						<label><?php _e( 'Meta Key', 'mycred' ); ?></label>
						<div class="h2"><input type="text" name="mycred_pref_core[types][new][key]" value="" class="medium" /></div>
						<span class="description"><?php _e( 'A unique ID for this type.', 'mycred' ); ?></span>
					</li>
					<li>
						<label><?php _e( 'Label', 'mycred' ); ?></label>
						<div class="h2"><input type="text" name="mycred_pref_core[types][new][label]" value="" class="medium" /></div>
						<span class="description"><?php _e( 'Menu and page title.', 'mycred' ); ?></span>
					</li>
					<li class="block">
						<p><?php _e( 'The meta key will define where your users balances are saved.', 'mycred' ); ?></p>
					</li>
				</ol>
			</div>
<?php		endif; ?>

			<?php do_action( 'mycred_after_core_prefs' . $action_hook, $this ); ?>

		</div>
		<?php submit_button( __( 'Update Settings', 'mycred' ), 'primary large', 'submit', false ); ?>

	</form>
	<?php do_action( 'mycred_bottom_settings_page' . $action_hook, $this ); ?>

	<div id="export-points" style="display:none;">
		<ul>
			<li>
				<label><?php _e( 'Identify users by', 'mycred' ); ?>:</label><br />
				<select id="mycred-export-identify-by">
<?php
			// Identify users by...
			$identify = apply_filters( 'mycred_export_by', array(
				'ID'    => __( 'User ID', 'mycred' ),
				'email' => __( 'User Email', 'mycred' ),
				'login' => __( 'User Login', 'mycred' )
			) );

			foreach ( $identify as $id => $label )
				echo '<option value="' . $id . '">' . $label . '</option>'; ?>

				</select><br />
				<span class="description"><?php _e( 'Use ID if you intend to use this export as a backup of your current site while Email is recommended if you want to export to a different site.', 'mycred' ); ?></span>
			</li>
			<li>
				<label><?php _e( 'Import Log Entry', 'mycred' ); ?>:</label><br />
				<input type="text" id="mycred-export-log-template" value="" class="regular-text" /><br />
				<span class="description"><?php echo sprintf( __( 'Optional log entry to use if you intend to import this file in a different %s installation.', 'mycred' ), mycred_label() ); ?></span>
			</li>
			<li class="action">
				<input type="button" id="mycred-run-exporter" value="<?php _e( 'Export', 'mycred' ); ?>" class="button button-large button-primary" />
			</li>
		</ul>
		<div class="clear"></div>
	</div>
</div>
<?php
		}

		/**
		 * Maybe Whitespace
		 * Since we want to allow a single whitespace in the string and sanitize_text_field() removes this whitespace
		 * this little method will make sure that whitespace is still there and that we still can sanitize the field.
		 * @since 0.1
		 * @version 1.0
		 */
		public function maybe_whitespace( $string ) {
			if ( strlen( $string ) > 1 )
				return '';

			return $string;
		}

		/**
		 * Sanititze Settings
		 * @filter 'mycred_save_core_prefs'
		 * @since 0.1
		 * @version 1.2
		 */
		public function sanitize_settings( $post ) {
			$new_data = array();

			if ( $this->mycred_type == 'mycred_default' ) {
				if ( isset( $post['types'] ) ) {

					$types = array( 'mycred_default' => mycred_label() );
					foreach ( $post['types'] as $item => $data ) {
						// Make sure it is not marked as deleted
						if ( isset( $post['delete_types'] ) && in_array( $item, $post['delete_types'] ) ) continue;

						// Skip if empty
						if ( empty( $data['key'] ) || empty( $data['label'] ) ) continue;

						// Add if not in array already
						if ( ! array_key_exists( $data['key'], $types ) ) {
							$key = str_replace( ' ', '_', $data['key'] );
							$key = sanitize_text_field( $key );

							$types[ $key ] = sanitize_text_field( $data['label'] );
						}
					}

					mycred_update_option( 'mycred_types', $types );
					unset( $post['types'] );

					if ( isset( $post['delete_types'] ) )
						unset( $post['delete_types'] );

				}
				
				delete_option( 'mycred_update_req_settings' );
			}

			// Format
			$new_data['cred_id'] = $this->mycred_type;
			$new_data['format'] = $this->core->format;

			$new_data['format']['separators']['decimal'] = $this->maybe_whitespace( $post['format']['separators']['decimal'] );
			$new_data['format']['separators']['thousand'] = $this->maybe_whitespace( $post['format']['separators']['thousand'] );

			// Name
			$new_data['name'] = array(
				'singular' => sanitize_text_field( $post['name']['singular'] ),
				'plural'   => sanitize_text_field( $post['name']['plural'] )
			);

			// Look
			$new_data['before'] = sanitize_text_field( $post['before'] );
			$new_data['after'] = sanitize_text_field( $post['after'] );

			// Capabilities
			$new_data['caps'] = array(
				'plugin' => sanitize_text_field( $post['caps']['plugin'] ),
				'creds'  => sanitize_text_field( $post['caps']['creds'] )
			);

			// Max
			$new_data['max'] = $this->core->number( $post['max'] );

			// Make sure multisites uses capabilities that exists
			if ( in_array( $new_data['caps']['creds'], array( 'create_users', 'delete_themes', 'edit_plugins', 'edit_themes', 'edit_users' ) ) && is_multisite() )
				$new_data['caps']['creds'] = 'delete_users';

			// Excludes
			$new_data['exclude'] = array(
				'plugin_editors' => ( isset( $post['exclude']['plugin_editors'] ) ) ? 1 : 0,
				'cred_editors'   => ( isset( $post['exclude']['cred_editors'] ) ) ? 1 : 0,
				'list'           => sanitize_text_field( $post['exclude']['list'] )
			);

			// User deletions
			$new_data['delete_user'] = ( isset( $post['delete_user'] ) ) ? $post['delete_user'] : 0;

			$action_hook = '';
			if ( ! $this->is_main_type )
				$action_hook = $this->mycred_type;

			$new_data = apply_filters( 'mycred_save_core_prefs' . $action_hook, $new_data, $post, $this );
			return $new_data;
		}
	}
}
?>