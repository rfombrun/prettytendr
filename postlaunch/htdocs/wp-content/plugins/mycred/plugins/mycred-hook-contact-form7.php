<?php

/**
 * Contact Form 7 Plugin
 * @since 0.1
 * @version 1.1
 */
if ( defined( 'myCRED_VERSION' ) ) {

	/**
	 * Register Hook
	 * @since 0.1
	 * @version 1.0
	 */
	add_filter( 'mycred_setup_hooks', 'contact_form_seven_myCRED_Hook' );
	function contact_form_seven_myCRED_Hook( $installed ) {
		$installed['contact_form7'] = array(
			'title'       => __( 'Contact Form 7 Form Submissions', 'mycred' ),
			'description' => __( 'Awards %_plural% for successful form submissions (by logged in users).', 'mycred' ),
			'callback'    => array( 'myCRED_Contact_Form7' )
		);
		return $installed;
	}

	/**
	 * Contact Form 7 Hook
	 * @since 0.1
	 * @version 1.0
	 */
	if ( ! class_exists( 'myCRED_Contact_Form7' ) && class_exists( 'myCRED_Hook' ) ) {
		class myCRED_Contact_Form7 extends myCRED_Hook {

			/**
			 * Construct
			 */
			function __construct( $hook_prefs, $type = 'mycred_default' ) {
				parent::__construct( array(
					'id'       => 'contact_form7',
					'defaults' => array()
				), $hook_prefs, $type );
			}

			/**
			 * Run
			 * @since 0.1
			 * @version 1.0
			 */
			public function run() {
				add_action( 'wpcf7_mail_sent', array( $this, 'form_submission' ) );
			}

			/**
			 * Get Forms
			 * Queries all Contact Form 7 forms.
			 * @since 0.1
			 * @version 1.2
			 */
			public function get_forms() {
				global $wpdb;

				$restuls = array();
				$forms = $wpdb->get_results( $wpdb->prepare( "
					SELECT ID, post_title  
					FROM {$wpdb->posts} 
					WHERE post_type = %s 
					ORDER BY ID ASC;", 'wpcf7_contact_form' ) );

				if ( $forms ) {
					foreach ( $forms as $form )
						$restuls[ $form->ID ] = $form->post_title;
				}

				return $restuls;
			}

			/**
			 * Successful Form Submission
			 * @since 0.1
			 * @version 1.2
			 */
			public function form_submission( $cf7_form ) {
				// Login is required
				if ( ! is_user_logged_in() ) return;

				$form_id = $cf7_form->id;
				if ( ! isset( $this->prefs[ $form_id ] ) || ! $this->prefs[ $form_id ]['creds'] != 0 ) return;

				$this->core->add_creds(
					'contact_form_submission',
					get_current_user_id(),
					$this->prefs[ $form_id ]['creds'],
					$this->prefs[ $form_id ]['log'],
					$form_id,
					array( 'ref_type' => 'post' ),
					$this->mycred_type
				);
			}

			/**
			 * Preferences for Contact Form 7 Hook
			 * @since 0.1
			 * @version 1.0.1
			 */
			public function preferences() {
				$prefs = $this->prefs;
				$forms = $this->get_forms();

				// No forms found
				if ( empty( $forms ) ) {
					echo '<p>' . __( 'No forms found.', 'mycred' ) . '</p>';
					return;
				}

				// Loop though prefs to make sure we always have a default settings (happens when a new form has been created)
				foreach ( $forms as $form_id => $form_title ) {
					if ( ! isset( $prefs[ $form_id ] ) ) {
						$prefs[ $form_id ] = array(
							'creds' => 1,
							'log'   => ''
						);
					}
				}

				// Set pref if empty
				if ( empty( $prefs ) ) $this->prefs = $prefs;

				// Loop for settings
				foreach ( $forms as $form_id => $form_title ) { ?>

<label for="<?php echo $this->field_id( array( $form_id, 'creds' ) ); ?>" class="subheader"><?php echo $form_title; ?></label>
<ol>
	<li>
		<div class="h2"><input type="text" name="<?php echo $this->field_name( array( $form_id, 'creds' ) ); ?>" id="<?php echo $this->field_id( array( $form_id, 'creds' ) ); ?>" value="<?php echo $this->core->number( $prefs[ $form_id ]['creds'] ); ?>" size="8" /></div>
	</li>
	<li class="empty">&nbsp;</li>
	<li>
		<label for="<?php echo $this->field_id( array( $form_id, 'log' ) ); ?>"><?php _e( 'Log template', 'mycred' ); ?></label>
		<div class="h2"><input type="text" name="<?php echo $this->field_name( array( $form_id, 'log' ) ); ?>" id="<?php echo $this->field_id( array( $form_id, 'log' ) ); ?>" value="<?php echo esc_attr( $prefs[ $form_id ]['log'] ); ?>" class="long" /></div>
		<span class="description"><?php echo $this->available_template_tags( array( 'general', 'post' ) ); ?></span>
	</li>
</ol>
<?php			}
			}
		}
	}
}
?>