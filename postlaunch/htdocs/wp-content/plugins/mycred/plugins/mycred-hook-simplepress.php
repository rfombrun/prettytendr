<?php

/**
 * Simple:Press
 * @since 1.3.3
 * @version 1.1
 */
if ( defined( 'myCRED_VERSION' ) ) {

	/**
	 * Register Hook
	 * @since 1.3.3
	 * @version 1.1
	 */
	add_filter( 'mycred_setup_hooks', 'SimplePress_myCRED_Hook' );
	function SimplePress_myCRED_Hook( $installed ) {
		$installed['hook_simplepress'] = array(
			'title'       => __( 'Simple:Press' ),
			'description' => __( 'Awards %_plural% for Simple:Press actions.', 'mycred' ),
			'callback'    => array( 'myCRED_SimplePress' )
		);
		return $installed;
	}

	/**
	 * Simple:Press Hook
	 * @since 1.3.3
	 * @version 1.1
	 */
	if ( ! class_exists( 'myCRED_SimplePress' ) && class_exists( 'myCRED_Hook' ) ) {
		class myCRED_SimplePress extends myCRED_Hook {

			/**
			 * Construct
			 */
			function __construct( $hook_prefs, $type = 'mycred_default' ) {
				parent::__construct( array(
					'id'       => 'hook_simplepress',
					'defaults' => array(
						'new_topic' => array(
							'creds'    => 1,
							'log'      => '%plural% for new forum topic'
						),
						'delete_topic' => array(
							'creds'    => 0-1,
							'log'      => '%singular% deduction for deleted topic'
						),
						'new_post' => array(
							'creds'    => 1,
							'log'      => '%plural% for new topic post',
							'author'   => 0,
							'limit'    => 10,
						),
						'delete_post' => array(
							'creds'    => 0-1,
							'log'      => '%singular% deduction for deleted topic reply'
						)
					)
				), $hook_prefs, $type );
				
			}

			/**
			 * Run
			 * @since 1.3.3
			 * @version 1.0
			 */
			public function run() {
				// New Topic
				if ( $this->prefs['new_topic']['creds'] != 0 )
					add_action( 'sph_post_create', array( $this, 'new_topic' ) );

				// Delete Topic
				if ( $this->prefs['delete_topic']['creds'] != 0 )
					add_action( 'sph_topic_delete', array( $this, 'delete_topic' ) );

				// New Reply
				if ( $this->prefs['new_post']['creds'] != 0 )
					add_action( 'sph_post_create', array( $this, 'new_post' ) );

				// Delete Reply
				if ( $this->prefs['delete_post']['creds'] != 0 )
					add_action( 'sph_post_delete', array( $this, 'delete_post' ) );
				
				add_filter( 'mycred_parse_log_entry', array( $this, 'adjust_log_templates' ), 10, 2 );
			}

			/**
			 * Custom Template Tags
			 * @since 1.3.3
			 * @version 1.1
			 */
			public function adjust_log_templates( $content, $log_entry ) {
				if ( ! isset( $log_entry->ref ) || $log_entry->data != 'simplepress' ) return $content;

				switch ( $log_entry->ref ) {
					case 'new_forum_topic' :

						global $wpdb;
						$db = SFTOPICS;
						$topic = $wpdb->get_row( $wpdb->prepare( "
						SELECT * 
						FROM {$db} 
						WHERE user_id = %d 
							AND topic_id = %d;", $log_entry->user_id, $log_entry->ref_id ) );

						// Topic name
						$topic_name = '';
						if ( isset( $topic->topic_name ) )
							$topic_name = $topic->topic_name;

						$content = str_replace( '%topic_name%', $topic_name, $content );

					break;
				}

				return $content;
			}

			/**
			 * New Topic
			 * @since 1.3.3
			 * @version 1.1
			 */
			public function new_topic( $post ) {
				if ( $post['action'] != 'topic' ) return;

				// Topic details
				$topic_author = $post['userid'];
				
				$forum_id = $post['forumid'];
				$topic_id = $post['topicid'];
				
				// Check if user is excluded
				if ( $this->core->exclude_user( $topic_author ) ) return;

				// Make sure this is unique event
				if ( $this->has_entry( 'new_forum_topic', $topic_id, $topic_author ) ) return;

				// Execute
				$this->core->add_creds(
					'new_forum_topic',
					$topic_author,
					$this->prefs['new_topic']['creds'],
					$this->prefs['new_topic']['log'],
					$topic_id,
					'simplepress',
					$this->mycred_type
				);
			}

			/**
			 * Delete Topic
			 * @since 1.3.3
			 * @version 1.1
			 */
			public function delete_topic( $topic ) {
				if ( $topic->user_id == 0 ) return;
				
				// Topic details
				$topic_author = $topic->user_id;
				$topic_id = $topic->topic_id;

				// If gained, points, deduct
				if ( $this->has_entry( 'new_forum_topic', $topic_id, $topic_author ) ) {

					// Execute
					$this->core->add_creds(
						'deleted_topic',
						$topic_author,
						$this->prefs['delete_topic']['creds'],
						$this->prefs['delete_topic']['log'],
						$topic_id,
						'simplepress',
						$this->mycred_type
					);

				}
			}

			/**
			 * New Post
			 * @since 1.3.3
			 * @version 1.1
			 */
			public function new_post( $post ) {
				if ( $post['action'] != 'post' ) return;
				
				// Post details
				$post_author = $post['userid'];

				$post_id = $post['postid'];
				$topic_id = $post['topicid'];

				// Check if user is excluded
				if ( $this->core->exclude_user( $post_author ) ) return;

				// Check if topic author gets points for their own replies
				if ( (bool) $this->prefs['new_post']['author'] === false ) {
					if ( $this->get_topic_author( $topic_id ) == $post_author ) return;
				}

				// Check daily limit
				if ( $this->reached_daily_limit( $post_author, 'new_post' ) ) return;

				// Make sure this is unique event
				if ( $this->has_entry( 'new_topic_post', $post_id, $post_author ) ) return;

				// Execute
				$this->core->add_creds(
					'new_topic_post',
					$post_author,
					$this->prefs['new_post']['creds'],
					$this->prefs['new_post']['log'],
					$post_id,
					'simplepress',
					$this->mycred_type
				);

				// Update Limit
				$this->update_daily_limit( $post_author, 'new_post' );
			}

			/**
			 * Delete Post
			 * @since 1.3.3
			 * @version 1.0
			 */
			public function delete_post( $target ) {
				if ( $target->user_id == 0 ) return;

				// Post details
				$post_author = $target->user_id;
				$post_id = $target->post_id;

				// If gained, points, deduct
				if ( $this->has_entry( 'new_topic_post', $post_id, $post_author ) ) {

					// Execute
					$this->core->add_creds(
						'deleted_topic_post',
						$post_author,
						$this->prefs['delete_post']['creds'],
						$this->prefs['delete_post']['log'],
						$post_id,
						'simplepress',
						$this->mycred_type
					);

				}
			}

			/**
			 * Reched Daily Limit
			 * Checks if a user has reached their daily limit.
			 * @since 1.3.3
			 * @version 1.1
			 */
			public function reached_daily_limit( $user_id, $id ) {
				// No limit used
				if ( $this->prefs[ $id ]['limit'] == 0 ) return false;
				$today = date_i18n( 'Y-m-d' );

				$key = 'mycred_simplepress_limits_' . $id;
				if ( ! $this->is_main_type )
					$key .= '_' . $this->mycred_type;

				$current = get_user_meta( $user_id, $key, true );
				if ( empty( $current ) || ! array_key_exists( $today, (array) $current ) ) $current[$today] = 0;
				if ( $current[ $today ] < $this->prefs[ $id ]['limit'] ) return false;
				return true;
			}

			/**
			 * Update Daily Limit
			 * Updates a given users daily limit.
			 * @since 1.3.3
			 * @version 1.1
			 */
			public function update_daily_limit( $user_id, $id ) {
				// No limit used
				if ( $this->prefs[ $id ]['limit'] == 0 ) return;

				$today = date_i18n( 'Y-m-d' );

				$key = 'mycred_simplepress_limits_' . $id;
				if ( ! $this->is_main_type )
					$key .= '_' . $this->mycred_type;

				$current = get_user_meta( $user_id, $key, true );
				if ( empty( $current ) || ! array_key_exists( $today, (array) $current ) )
					$current[ $today ] = 0;

				$current[ $today ] = $current[ $today ]+1;

				update_user_meta( $user_id, $key, $current );
			}

			/**
			 * Get SimplePress Topic Author ID
			 * @since 1.3.3
			 * @version 1.0
			 */
			public function get_topic_author( $topic_id = '' ) {
				global $wpdb;
				
				$db = SFTOPICS;
				return $wpdb->get_var( $wpdb->prepare( "
					SELECT user_id 
					FROM {$db} 
					WHERE topic_id = %d;", $topic_id ) );
			}

			/**
			 * Preferences
			 * @since 1.3.3
			 * @version 1.0
			 */
			public function preferences() {
				$prefs = $this->prefs; ?>

<!-- Creds for New Topic -->
<label for="<?php echo $this->field_id( array( 'new_topic', 'creds' ) ); ?>" class="subheader"><?php echo $this->core->template_tags_general( __( '%plural% for New Topic', 'mycred' ) ); ?></label>
<ol>
	<li>
		<div class="h2"><input type="text" name="<?php echo $this->field_name( array( 'new_topic', 'creds' ) ); ?>" id="<?php echo $this->field_id( array( 'new_topic', 'creds' ) ); ?>" value="<?php echo $this->core->number( $prefs['new_topic']['creds'] ); ?>" size="8" /></div>
	</li>
	<li class="empty">&nbsp;</li>
	<li>
		<label for="<?php echo $this->field_id( array( 'new_topic', 'log' ) ); ?>"><?php _e( 'Log template', 'mycred' ); ?></label>
		<div class="h2"><input type="text" name="<?php echo $this->field_name( array( 'new_topic', 'log' ) ); ?>" id="<?php echo $this->field_id( array( 'new_topic', 'log' ) ); ?>" value="<?php echo esc_attr( $prefs['new_topic']['log'] ); ?>" class="long" /></div>
		<span class="description"><?php echo $this->available_template_tags( array( 'general' ), '%topic_name%' ); ?></span>
	</li>
</ol>
<!-- Creds for Deleting Topic -->
<label for="<?php echo $this->field_id( array( 'delete_topic', 'creds' ) ); ?>" class="subheader"><?php echo $this->core->template_tags_general( __( '%plural% for Topic Deletion', 'mycred' ) ); ?></label>
<ol>
	<li>
		<div class="h2"><input type="text" name="<?php echo $this->field_name( array( 'delete_topic', 'creds' ) ); ?>" id="<?php echo $this->field_id( array( 'delete_topic', 'creds' ) ); ?>" value="<?php echo $this->core->number( $prefs['delete_topic']['creds'] ); ?>" size="8" /></div>
	</li>
	<li class="empty">&nbsp;</li>
	<li>
		<label for="<?php echo $this->field_id( array( 'delete_topic', 'log' ) ); ?>"><?php _e( 'Log template', 'mycred' ); ?></label>
		<div class="h2"><input type="text" name="<?php echo $this->field_name( array( 'delete_topic', 'log' ) ); ?>" id="<?php echo $this->field_id( array( 'delete_topic', 'log' ) ); ?>" value="<?php echo esc_attr( $prefs['delete_topic']['log'] ); ?>" class="long" /></div>
		<span class="description"><?php echo $this->available_template_tags( array( 'general' ) ); ?></span>
	</li>
</ol>
<!-- Creds for New Topic Post -->
<label for="<?php echo $this->field_id( array( 'new_post', 'creds' ) ); ?>" class="subheader"><?php echo $this->core->template_tags_general( __( '%plural% for New Topic Post', 'mycred' ) ); ?></label>
<ol>
	<li>
		<div class="h2"><input type="text" name="<?php echo $this->field_name( array( 'new_post', 'creds' ) ); ?>" id="<?php echo $this->field_id( array( 'new_post', 'creds' ) ); ?>" value="<?php echo $this->core->number( $prefs['new_post']['creds'] ); ?>" size="8" /></div>
	</li>
	<li class="empty">&nbsp;</li>
	<li>
		<label for="<?php echo $this->field_id( array( 'new_post', 'log' ) ); ?>"><?php _e( 'Log template', 'mycred' ); ?></label>
		<div class="h2"><input type="text" name="<?php echo $this->field_name( array( 'new_post', 'log' ) ); ?>" id="<?php echo $this->field_id( array( 'new_post', 'log' ) ); ?>" value="<?php echo esc_attr( $prefs['new_post']['log'] ); ?>" class="long" /></div>
		<span class="description"><?php echo $this->available_template_tags( array( 'general' ) ); ?></span>
	</li>
	<li class="empty">&nbsp;</li>
	<li>
		<input type="checkbox" name="<?php echo $this->field_name( array( 'new_post' => 'author' ) ); ?>" id="<?php echo $this->field_id( array( 'new_post' => 'author' ) ); ?>" <?php checked( $prefs['new_post']['author'], 1 ); ?> value="1" />
		<label for="<?php echo $this->field_id( array( 'new_post' => 'author' ) ); ?>"><?php echo $this->core->template_tags_general( __( 'Topic authors can receive %_plural% for posting on their own Topic', 'mycred' ) ); ?></label>
	</li>
	<li class="empty">&nbsp;</li>
	<li>
		<label for="<?php echo $this->field_id( array( 'new_post', 'limit' ) ); ?>"><?php _e( 'Daily Limit', 'mycred' ); ?></label>
		<div class="h2"><input type="text" name="<?php echo $this->field_name( array( 'new_post', 'limit' ) ); ?>" id="<?php echo $this->field_id( array( 'new_post', 'limit' ) ); ?>" value="<?php echo abs( $prefs['new_post']['limit'] ); ?>" size="8" /></div>
		<span class="description"><?php _e( 'Use zero for unlimited', 'mycred' ); ?></span>
	</li>
</ol>
<!-- Creds for Deleting Post -->
<label for="<?php echo $this->field_id( array( 'delete_post', 'creds' ) ); ?>" class="subheader"><?php echo $this->core->template_tags_general( __( '%plural% for Topic Post Deletion', 'mycred' ) ); ?></label>
<ol>
	<li>
		<div class="h2"><input type="text" name="<?php echo $this->field_name( array( 'delete_post', 'creds' ) ); ?>" id="<?php echo $this->field_id( array( 'delete_post', 'creds' ) ); ?>" value="<?php echo $this->core->number( $prefs['delete_post']['creds'] ); ?>" size="8" /></div>
	</li>
	<li class="empty">&nbsp;</li>
	<li>
		<label for="<?php echo $this->field_id( array( 'delete_post', 'log' ) ); ?>"><?php _e( 'Log template', 'mycred' ); ?></label>
		<div class="h2"><input type="text" name="<?php echo $this->field_name( array( 'delete_post', 'log' ) ); ?>" id="<?php echo $this->field_id( array( 'delete_post', 'log' ) ); ?>" value="<?php echo esc_attr( $prefs['delete_post']['log'] ); ?>" class="long" /></div>
		<span class="description"><?php echo $this->available_template_tags( array( 'general' ) ); ?></span>
	</li>
</ol>
<?php
			}

			/**
			 * Sanitise Preference
			 * @since 1.3.3
			 * @version 1.0
			 */
			function sanitise_preferences( $data ) {
				$new_data = $data;
				$new_data['new_post']['author'] = ( isset( $data['new_post']['author'] ) ) ? $data['new_post']['author'] : 0;
				return $new_data;
			}
		}
	}
}
?>