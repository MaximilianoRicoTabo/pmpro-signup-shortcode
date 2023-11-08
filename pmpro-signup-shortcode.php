<?php
/*
Plugin Name: Paid Memberships Pro - Signup Shortcode Add On
Plugin URI: https://www.paidmembershipspro.com/add-ons/pmpro-signup-shortcode/
Description: Shortcode for a simplified Membership Signup Form with options for email only signup and more.
Version: 0.3.1
Author: Paid Memberships Pro
Author URI: https://www.paidmembershipspro.com/
Text Domain: pmpro-signup-shortcode
*/

/**
 * Load the languages folder for translations.
 */

function pmprosus_load_textdomain(){
	load_plugin_textdomain( 'pmpro-signup-shortcode', false, basename( dirname( __FILE__ ) ) . '/languages' );
}

add_action( 'plugins_loaded', 'pmprosus_load_textdomain' );

/*
	Use Email Address as Username and Generate a Password
*/
function pmprosus_skip_username_password()
{
	 global $current_user;

	//copy email to username if no username field is present
	if(!empty($_REQUEST['bemail']) && !isset($_REQUEST['username']))
		$_REQUEST['username'] = function_exists( 'pmpro_generateUsername' ) ? pmpro_generateUsername( $_REQUEST['bemail'] ) : $_REQUEST['bemail'];

	if(!empty($_POST['bemail']) && !isset($_POST['username']))
		$_POST['username'] = $_POST['bemail'];

	if(!empty($_GET['bemail']) && !isset($_GET['username']))
		$_GET['username'] = $_GET['bemail'];

	//autogenerate password if no field is present
	if(!empty($_REQUEST['bemail']) && !isset($_REQUEST['password']) && ! is_user_logged_in() ) {

		//generate password
		$_REQUEST['password'] = wp_generate_password();
		$_REQUEST['password2'] = $_REQUEST['password'];

		if ( function_exists( 'pmpro_start_session' ) ) {
			pmpro_start_session();
		}

		$_SESSION['pmprosus_autogenerated_password'] = $_REQUEST['password'];
	}
	if(!empty($_POST['bemail']) && !isset($_POST['password']) && !isset($current_user))
	{
		$_POST['password'] = wp_generate_password();
		$_POST['password2'] = $_POST['password'];
	}
	if(!empty($_GET['bemail']) && !isset($_GET['password']) && !isset($current_user))
	{
		$_REQUEST['password'] = wp_generate_password();
		$_REQUEST['password2'] = $_REQUEST['password'];
	}
}
add_action('init', 'pmprosus_skip_username_password');

/*
	Add password to confirmation email if it was autogenerated
*/
function pmprosus_pmpro_email_data($data, $email) {
	if ( function_exists( 'pmpro_start_session' ) ) {
		pmpro_start_session();
	}

	// Include the generated password in the confirmation email.
	if ( ! empty( $_SESSION['pmprosus_autogenerated_password'] ) && strpos( $email->template, 'checkout_' ) !== false ) {
		$data['user_email'] = sprintf(__('Password: %s Email: %s', 'pmpro-signup-shortcode'), sanitize_text_field( $_SESSION['pmprosus_autogenerated_password'] ), esc_html( $data['user_email'] ) );
    
		// Clear session variable
		unset( $_SESSION['pmprosus_autogenerated_password'] );
	}

	return $data;
}
add_filter('pmpro_email_data', 'pmprosus_pmpro_email_data', 10, 2);

/*
	Make sure we load our version of the shortcode instead of the one bundled in Register Helper.
*/
function pmprosus_load_shortcode() {
	remove_shortcode('pmpro_signup');
	add_shortcode('pmpro_signup', 'pmprosus_signup_shortcode');
}
add_action('init', 'pmprosus_load_shortcode');

/*
	Save referrer to session
*/
function pmprosus_init_referrer() {
	if(!empty($_REQUEST['pmprosus_referrer'])) {
		$_SESSION['pmprosus_referrer'] = $_REQUEST['pmprosus_referrer'];
		$_SESSION['pmprosus_redirect_to'] = $_REQUEST['redirect_to'];
	}
}
add_action('init', 'pmprosus_init_referrer');

/*
	Redirect to referrer if set.
*/
function pmprosus_pmpro_confirmation_url($url, $user_id, $level) {
	global $post;

	//figure out referrer
	if(!empty($_REQUEST['pmprosus_referrer']))
		$referrer = sanitize_url( $_REQUEST['pmprosus_referrer'] );
	elseif(!empty($_SESSION['pmprosus_referrer']))
		$referrer = sanitize_url( $_SESSION['pmprosus_referrer'] );
	else
		$referrer = '';

	//figure out redirect_to
	if(!empty($_REQUEST['redirect_to']))
		$redirect = sanitize_url( $_REQUEST['redirect_to'] );
	elseif(!empty($_SESSION['pmprosus_redirect_to']))
		$redirect = sanitize_url( $_SESSION['pmprosus_redirect_to'] );
	else
		$redirect = '';

	//unset session vars
	unset($_SESSION['pmprosus_referrer']);
	unset($_SESSION['pmprosus_redirect_to']);

	//save referrer to user meta
	update_user_meta($user_id, 'pmprosus_referrer', $referrer );

	//change confirmation URL to redirect if set
	if($redirect) {
		$url = $redirect;
	}

	return $url;
}
add_filter('pmpro_confirmation_url', 'pmprosus_pmpro_confirmation_url', 10, 3);

/*
	This shortcode will show a signup form with account fields based on attributes.

	If the level is not free, the user will be taken to the membership checkout
	page to enter billing information.
*/
function pmprosus_signup_shortcode($atts, $content=null, $code="")
{
	global $current_user, $pmpro_level, $username, $email;

	// $atts    ::= array of attributes
	// $content ::= text within enclosing form of shortcode element
	// $code    ::= the shortcode found, when == callback name
	// examples: [pmpro_signup level="3" short="1" intro="0" submit_button="Signup Now"]

	//make sure PMPro is activated
	if(!function_exists('pmpro_getLevel'))
		return __( "Paid Memberships Pro must be installed to use the pmpro_signup shortcode.", "pmpro-signup-shortcode" );

	//set defaults
	extract(shortcode_atts(array(
		'intro' => "0",
		'hidelabels' => NULL,
		'level' => NULL,
		'login' => true,
		'redirect' => NULL,
		'short' => NULL,
		'submit_button' => __( "Sign Up Now", "pmpro-signup-shortcode" ),
		'title' => NULL,
		'custom_fields' => true,
		'confirm_email' => true,
		'confirm_password' => true
	), $atts));

	// If there is a current level in global, save it to a backup variable.
	$pmpro_level_backup = $pmpro_level;

	// try to get the Terms of Service page settings
	$tospage = get_option( 'pmpro_tospage' );

	// set title
	if($title === "1" || $title === "true" || $title === "yes")
		$title_display = true;

	if(isset($title_display))
		if(!empty($level))
			$title = 'Register For ' . pmpro_getLevel($level)->name;
		else
			$title = 'Register For ' . get_option('blogname');

	//turn 0's into falses
	if($login === "0" || $login === "false" || $login === "no")
		$login = false;
	else
		$login = true;

	//turn 0's into falses
	if( $custom_fields === "0" || $custom_fields === "false" || $custom_fields === "no") {
		$custom_fields = false;
	}

	//the default checkout boxes location is loaded only if custom_fields is specifically "1" or "true"
	if( $custom_fields === "1" || $custom_fields === "true" ) {
		$checkout_boxes = true;

		// Set the level for this signup shortcode so level-specific checkout fields appear.
		if ( ! empty( $level ) ) {
			$pmpro_level = pmpro_getLevel( $level );
		}

	} else {
		$checkout_boxes = false;
	}

	//check which form format is specified
	if( ! empty( $hidelabels ) ) {
		$hidelabels = true;
	}

	if( $confirm_email === "0" || $confirm_email === "false" || $confirm_email === "no" ){
		$confirm_email = false;
	}

	if( $confirm_password === "0" || $confirm_password === "false" || $confirm_password === "no" ){
		$confirm_password = false;
	}

	//turn 0's into falses
	if($intro === "0" || $intro === "false" || $intro === "no")
		$intro = false;

	//turn 1's and 'yes' into true
	if($short === "1" || $short === "true" || $short === "yes")
		$short = true;
	elseif($short === "emailonly")
		$short = "emailonly";
	else
		$short = false;

	// Get field values from URL or user.
	if ( isset( $_REQUEST['username'] ) ) {
		$username = sanitize_user( stripslashes( $_REQUEST['username'] ) );
	} elseif ( is_user_logged_in() ) {
		$username = $current_user->user_login;
	} else {
		$username = '';
	}
	if ( isset ( $_REQUEST['email'] ) ) {
		$bemail = sanitize_email( stripslashes( $_REQUEST['email'] ) );
	} elseif ( is_user_logged_in() ) {
		$bemail = $current_user->user_email;
	} else {
		$bemail = '';
	}

	// treat this page load as a checkout
	add_filter( 'pmpro_is_checkout', '__return_true' );

	// load recaptcha if needed
	if ( ! function_exists( 'pmpro_recaptcha_get_html' ) ) {
		pmpro_init_recaptcha();
	}

	global $current_user, $membership_levels, $pmpro_pages;

	ob_start();
	?>
		<?php if(!empty($current_user->ID) && pmpro_hasMembershipLevel($level,$current_user->ID)) { ?>
			<?php
				if(current_user_can("manage_options") )
				{
					?>
					<div class="pmpro_message pmpro_alert"><?php esc_html_e('&#91;pmpro_signup&#93; Admin Only Shortcode Alert: You are logged in as an administrator and already have the membership level specified.', 'pmpro-signup-shortcode'); ?></div>
					<?php
				}
			?>
		<?php } else { ?>
		<style>
			.pmpro_signup_form-hidelabels .pmpro_checkout-field label:first-child {
				clip: rect(1px, 1px, 1px, 1px);
				position: absolute;
				height: 1px;
				width: 1px;
				overflow: hidden
			}
			
		</style>
		<form id="pmpro_form" class="pmpro_form pmpro_signup_form<?php if( ! empty( $hidelabels ) ) { ?> pmpro_signup_form-hidelabels<?php } ?>" action="<?php echo esc_url( pmpro_url("checkout") ); ?>" method="post">
			<?php
				if(!empty($title))
					echo '<h2>' . esc_html( $title ) . '</h2>';
			?>
			<?php
				if(!empty($intro))
					echo wp_kses_post( wpautop($intro) );
			?>
			<div class="pmpro_checkout">
				<div class="pmpro_checkout-fields">

					<input type="hidden" id="level" name="level" value="<?php echo intval( $level ); ?>" />
					<input type="hidden" id="pmpro_signup_shortcode" name="pmpro_signup_shortcode" value="1" />
					<?php do_action( 'pmpro_signup_form_before_fields' ); ?>

					<?php if ( ! empty( $current_user->ID ) ) { ?>
						<p id="pmpro_account_loggedin">
							<?php
								$allowed_html = array(
									'a' => array(
										'href' => array(),
										'target' => array(),
										'title' => array(),
									),
									'strong' => array(),
								);
								echo wp_kses( sprintf( __('You are logged in as <strong>%s</strong>. If you would like to use a different account for this membership, <a href="%s">log out now</a>.', 'pmpro-signup-shortcode'), $current_user->user_login, esc_url( wp_logout_url( $_SERVER['REQUEST_URI'] ) ) ), $allowed_html );
							?>
						</p>
						<?php
					} else { ?>

						<?php if ( $short !== 'emailonly') { ?>
							<div class="pmpro_checkout-field pmpro_checkout-field-username">
								<label for="username"><?php _e('Username', 'pmpro-signup-shortcode');?></label>
								<input id="username" name="username" type="text" class="input" size="30" value="<?php echo esc_attr( $username ); ?>" <?php if( ! empty( $hidelabels ) ) { ?>placeholder="<?php esc_attr_e('Username', 'pmpro-signup-shortcode'); ?>"<?php } ?> />
							</div>
						<?php } ?>

						<?php if( !empty( $custom_fields ) ) { do_action("pmpro_checkout_after_username"); } ?>

						<?php if ( $short !== 'emailonly') { ?>
							<div class="pmpro_checkout-field pmpro_checkout-field-password">
								<label for="password"><?php esc_html_e('Password', 'pmpro-signup-shortcode');?></label>
								<input id="password" name="password" type="password" class="input" size="30" value="" <?php if( ! empty( $hidelabels ) ) { ?> placeholder="<?php esc_attr_e('Password', 'pmpro-signup-shortcode');?>"<?php } ?> />
							</div>
						<?php } ?>

						<?php if( ! empty( $short ) || ! $confirm_password ) { ?>
							<input type="hidden" name="password2_copy" value="1" />
						<?php } else { ?>
							<div class="pmpro_checkout-field pmpro_checkout-field-password2">
								<label for="password2"><?php esc_html_e('Confirm Password', 'pmpro-signup-shortcode');?></label>
								<input id="password2" name="password2" type="password" class="input" size="30" value="" <?php if( ! empty( $hidelabels ) ) { ?>placeholder="<?php esc_attr_e('Confirm Password', 'pmpro-signup-shortcode');?>"<?php } ?> />
							</div>
						<?php } ?>

						<?php if( !empty( $custom_fields ) ) { do_action("pmpro_checkout_after_password"); } ?>

						<div class="pmpro_checkout-field pmpro_checkout-field-bemail">
							<label for="bemail"><?php esc_html_e('E-mail Address', 'pmpro-signup-shortcode');?></label>
							<input id="bemail" name="bemail" type="email" class="input" size="30" value="<?php echo esc_attr( $bemail ); ?>" <?php if( ! empty( $hidelabels ) ) { ?> placeholder="<?php esc_attr_e('E-mail Address', 'pmpro-signup-shortcode');?>"<?php } ?> />
						</div>

						<?php if( ! empty( $short ) || ! $confirm_email ) { ?>
							<input type="hidden" name="bconfirmemail_copy" value="1" />
						<?php } else { ?>
							<div class="pmpro_checkout-field pmpro_checkout-field-bconfirmemail">
								<label for="bconfirmemail"><?php esc_html_e('Confirm E-mail', 'pmpro-signup-shortcode');?></label>
								<input id="bconfirmemail" name="bconfirmemail" type="email" class="input" size="30" value="" <?php if( ! empty( $hidelabels ) ) { ?>placeholder="<?php esc_attr_e('Confirm E-mail', 'pmpro-signup-shortcode');?>"<?php } ?> />
							</div>
						<?php } ?>

						<input type="hidden" name="pmprosus_referrer" value="<?php echo esc_attr($_SERVER['REQUEST_URI']);?>" />

						<?php
							if ( $redirect == 'referrer' ) {
								$redirect_to = $_SERVER['REQUEST_URI'];
							} elseif ( $redirect == 'account' ) {
								$redirect_to = get_permalink( $pmpro_pages['account'] );
							} elseif ( empty( $redirect ) ) {
								$redirect_to = '';
							} else {
								$redirect_to = $redirect;
							}
						?>

						<input type="hidden" name="redirect_to" value="<?php echo esc_url($redirect_to);?>" />

						<?php if( !empty( $custom_fields ) ) { do_action("pmpro_checkout_after_email"); } ?>

						<div class="pmpro_hidden">
							<label for="fullname"><?php esc_html_e('Full Name', 'pmpro-signup-shortcode');?></label>
							<input id="fullname" name="fullname" type="text" class="input" size="30" value="" /> <strong><?php esc_html_e('LEAVE THIS BLANK', 'pmpro-signup-shortcode');?></strong>
						</div>

						<?php
							global $recaptcha, $recaptcha_publickey;
							if( $recaptcha == 2 || ( ! empty( $level ) && $recaptcha == 1 && pmpro_isLevelFree( pmpro_getLevel( $level ) ) ) ) { ?>
								<div class="pmpro_checkout-field pmpro_captcha">
									<?php echo pmpro_recaptcha_get_html( $recaptcha_publickey, NULL, true ); ?>
								</div> <!-- end pmpro_captcha -->
							<?php } ?>

					<?php } ?>

					<?php do_action('pmpro_checkout_after_user_fields'); ?>

					<?php if( $checkout_boxes && function_exists('pmprorh_pmpro_checkout_boxes') ) { pmprorh_pmpro_checkout_boxes(); } ?>

					<?php
					if( !empty( $tospage ) ){
						$tospage = get_post( $tospage );
						$allowed_html = array (
							'a' => array (
								'href' => array(),
								'target' => array(),
								'title' => array(),
							),
						);
						?>
						<input type="checkbox" name="tos" value="1" id="tos" /> <label class="pmpro_label-inline pmpro_clickable" for="tos"><?php echo wp_kses( sprintf( __('I agree to the <a href="%s" target="_blank">%s</a>', 'pmpro-signup-shortcode' ), get_permalink( $tospage ), $tospage->post_title ), $allowed_html );?></label>
						<?php
					} ?>

					<?php if( !empty( $custom_fields ) ) { do_action( 'pmpro_signup_form_before_submit' ); } ?>
					
					<?php 

					if( ! empty( $custom_fields ) ) {
						//Adds support for User Fields
						global $pmpro_user_fields;
						foreach( $pmpro_user_fields as $group ) {
							foreach( $group as $field ) {
								if ( ! pmpro_is_field( $field ) ) {
									continue;
								}
								
								if ( ! pmpro_check_field_for_level( $field ) ) {
									continue;
								}
																	
								if( ! isset( $field->profile ) || $field->profile !== 'only' && $field->profile !== 'only_admin' ) {
									if ( ! empty( $field->required ) ) {
										$field->showrequired = 'label';
									} else {
										$field->showrequired = '';
									}
									$field->displayAtCheckout();
								}									
							}
						}
					}
					?>

					<div class="pmpro_submit">
						<span id="pmpro_submit_span">
							<input type="hidden" name="submit-checkout" value="1" />
							<input id="pmpro_btn-submit" type="submit" class="pmpro_btn pmpro_btn-submit-checkout" value="<?php echo esc_attr( $submit_button ); ?>" />
						</span>
					</div>
					<?php do_action( 'pmpro_signup_form_after_submit' ); ?>
					<?php if ( ! empty( $login ) && empty( $current_user->ID ) ) { ?>
						<div class="login-link" style="text-align:center;">
							<a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>"><?php esc_html_e( 'Log In','pmpro-signup-shortcode' ); ?></a>
						</div>
					<?php } ?>
				</div> <!-- end pmpro_checkout-fields -->
			</div> <!-- end pmpro_checkout -->
		</form>
		<?php do_action( 'pmpro_signup_form_after_form' ); ?>
		<?php } ?>
	<?php
	$temp_content = ob_get_contents();
	ob_end_clean();

	// Set the global level back to the correct object.
	$pmpro_level = $pmpro_level_backup;

	return $temp_content;
}

/*
Function to add links to the plugin row meta
*/
function pmprosus_plugin_row_meta($links, $file) {
	if(strpos($file, 'pmpro-signup-shortcode.php') !== false)
	{
		$new_links = array(
			'<a href="' . esc_url('https://www.paidmembershipspro.com/add-ons/pmpro-signup-shortcode/')  . '" title="' . esc_attr( __( 'View Documentation', 'pmpro-signup-shortcode' ) ) . '">' . esc_html__( 'Docs', 'pmpro-signup-shortcode' ) . '</a>',
			'<a href="' . esc_url('http://paidmembershipspro.com/support/') . '" title="' . esc_attr( __( 'Visit Customer Support Forum', 'pmpro-signup-shortcode' ) ) . '">' . esc_html__( 'Support', 'pmpro-signup-shortcode' ) . '</a>',
		);
		$links = array_merge($links, $new_links);
	}
	return $links;
}
add_filter('plugin_row_meta', 'pmprosus_plugin_row_meta', 10, 2);
