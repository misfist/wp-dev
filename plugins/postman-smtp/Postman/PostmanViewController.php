<?php
if (! class_exists ( 'PostmanViewController' )) {
	class PostmanViewController {
		private $logger;
		private $rootPluginFilenameAndPath;
		private $options;
		private $authorizationToken;
		private $oauthScribe;
		private $importableConfiguration;
		private $adminController;
		const POSTMAN_MENU_SLUG = 'postman';
		const CONFIGURATION_SLUG = 'postman/configuration';
		const CONFIGURATION_WIZARD_SLUG = 'postman/configuration_wizard';
		const EMAIL_TEST_SLUG = 'postman/email_test';
		const PORT_TEST_SLUG = 'postman/port_test';
		const DIAGNOSTICS_SLUG = 'postman/diagnostics';
		
		// style sheets and scripts
		const POSTMAN_STYLE = 'postman_style';
		const JQUERY_SCRIPT = 'jquery';
		const POSTMAN_SCRIPT = 'postman_script';
		
		//
		const BACK_ARROW_SYMBOL = '&#11013;';
		
		/**
		 * Constructor
		 *
		 * @param PostmanOptions $options        	
		 * @param PostmanOAuthToken $authorizationToken        	
		 * @param PostmanConfigTextHelper $oauthScribe        	
		 */
		function __construct($rootPluginFilenameAndPath, PostmanOptions $options, PostmanOAuthToken $authorizationToken, PostmanConfigTextHelper $oauthScribe, PostmanAdminController $adminController) {
			$this->options = $options;
			$this->rootPluginFilenameAndPath = $rootPluginFilenameAndPath;
			$this->authorizationToken = $authorizationToken;
			$this->oauthScribe = $oauthScribe;
			$this->adminController = $adminController;
			$this->logger = new PostmanLogger ( get_class ( $this ) );
			$this->registerAdminMenu ( $this, 'generateDefaultContent' );
			$this->registerAdminMenu ( $this, 'addSetupWizardSubmenu' );
			$this->registerAdminMenu ( $this, 'addConfigurationSubmenu' );
			$this->registerAdminMenu ( $this, 'addEmailTestSubmenu' );
			$this->registerAdminMenu ( $this, 'addPortTestSubmenu' );
			$this->registerAdminMenu ( $this, 'addPurgeDataSubmenu' );
			$this->registerAdminMenu ( $this, 'addDiagnosticsSubmenu' );
			
			// initialize the scripts, stylesheets and form fields
			add_action ( 'admin_init', array (
					$this,
					'registerStylesAndScripts' 
			) );
		}
		public static function getPageUrl($slug) {
			return PostmanUtils::getPageUrl ( $slug );
		}
		/**
		 *
		 * @param unknown $actionName        	
		 * @param unknown $callbackName        	
		 */
		private function registerAdminMenu($viewController, $callbackName) {
			// $this->logger->debug ( 'Registering admin menu ' . $callbackName );
			add_action ( 'admin_menu', array (
					$viewController,
					$callbackName 
			) );
		}
		
		/**
		 * Add options page
		 */
		public function generateDefaultContent() {
			// This page will be under "Settings"
			$pageTitle = _x ( 'Postman Setup', 'Page Title', 'postman-smtp' );
			$pluginName = __ ( 'Postman SMTP', 'postman-smtp' );
			$uniqueId = self::POSTMAN_MENU_SLUG;
			$pageOptions = array (
					$this,
					'outputDefaultContent' 
			);
			$mainPostmanSettingsPage = add_options_page ( $pageTitle, $pluginName, Postman::MANAGE_POSTMAN_CAPABILITY_NAME, $uniqueId, $pageOptions );
			// When the plugin options page is loaded, also load the stylesheet
			add_action ( 'admin_print_styles-' . $mainPostmanSettingsPage, array (
					$this,
					'enqueueHomeScreenStylesheet' 
			) );
		}
		function enqueueHomeScreenStylesheet() {
			wp_enqueue_style ( self::POSTMAN_STYLE );
			wp_enqueue_script ( 'postman_script' );
		}
		
		/**
		 * Register the Setup Wizard screen
		 */
		public function addSetupWizardSubmenu() {
			$page = add_submenu_page ( null, _x ( 'Postman Setup', 'Page Title', 'postman-smtp' ), __ ( 'Postman SMTP', 'postman-smtp' ), Postman::MANAGE_POSTMAN_CAPABILITY_NAME, self::CONFIGURATION_WIZARD_SLUG, array (
					$this,
					'outputWizardContent' 
			) );
			// When the plugin options page is loaded, also load the stylesheet
			add_action ( 'admin_print_styles-' . $page, array (
					$this,
					'enqueueWizardResources' 
			) );
		}
		function enqueueWizardResources() {
			$this->importableConfiguration = new PostmanImportableConfiguration ();
			$startPage = 1;
			if ($this->importableConfiguration->isImportAvailable ()) {
				$startPage = 0;
			}
			wp_localize_script ( self::POSTMAN_SCRIPT, 'postman_setup_wizard', array (
					'start_page' => $startPage 
			) );
			wp_enqueue_style ( 'jquery_steps_style' );
			wp_enqueue_style ( self::POSTMAN_STYLE );
			wp_enqueue_script ( 'postman_wizard_script' );
			if (PostmanUtils::startsWith ( get_locale (), 'fr' )) {
				wp_enqueue_script ( 'jquery_validation_fr' );
			} elseif (PostmanUtils::startsWith ( get_locale (), 'it' )) {
				wp_enqueue_script ( 'jquery_validation_it' );
			} elseif (PostmanUtils::startsWith ( get_locale (), 'tr' )) {
				wp_enqueue_script ( 'jquery_validation_tr' );
			}
			foreach ( PostmanTransportRegistry::getInstance ()->getTransports () as $transport ) {
				$transport->enqueueScript ();
			}
		}
		
		/**
		 * Register the Configuration screen
		 */
		public function addConfigurationSubmenu() {
			$page = add_submenu_page ( null, _x ( 'Postman Setup', 'Page Title', 'postman-smtp' ), __ ( 'Postman SMTP', 'postman-smtp' ), Postman::MANAGE_POSTMAN_CAPABILITY_NAME, self::CONFIGURATION_SLUG, array (
					$this,
					'outputManualConfigurationContent' 
			) );
			// When the plugin options page is loaded, also load the stylesheet
			add_action ( 'admin_print_styles-' . $page, array (
					$this,
					'enqueueConfigurationResources' 
			) );
		}
		function enqueueConfigurationResources() {
			wp_enqueue_style ( self::POSTMAN_STYLE );
			wp_enqueue_style ( 'jquery_ui_style' );
			wp_enqueue_script ( 'postman_manual_config_script' );
			wp_enqueue_script ( 'jquery-ui-tabs' );
			foreach ( PostmanTransportRegistry::getInstance ()->getTransports () as $transport ) {
				$transport->enqueueScript ();
			}
		}
		
		/**
		 * Register the Email Test screen
		 */
		public function addEmailTestSubmenu() {
			$page = add_submenu_page ( null, _x ( 'Postman Setup', 'Page Title', 'postman-smtp' ), __ ( 'Postman SMTP', 'postman-smtp' ), Postman::MANAGE_POSTMAN_CAPABILITY_NAME, self::EMAIL_TEST_SLUG, array (
					$this,
					'outputTestEmailWizardContent' 
			) );
			// When the plugin options page is loaded, also load the stylesheet
			add_action ( 'admin_print_styles-' . $page, array (
					$this,
					'enqueueEmailTestResources' 
			) );
		}
		function enqueueEmailTestResources() {
			wp_enqueue_style ( 'jquery_steps_style' );
			wp_enqueue_style ( self::POSTMAN_STYLE );
			wp_enqueue_style ( 'postman_send_test_email' );
			wp_enqueue_script ( 'postman_test_email_wizard_script' );
		}
		
		/**
		 * Register the Diagnostics screen
		 */
		public function addDiagnosticsSubmenu() {
			$page = add_submenu_page ( null, _x ( 'Postman Setup', 'Page Title', 'postman-smtp' ), __ ( 'Postman SMTP', 'postman-smtp' ), Postman::MANAGE_POSTMAN_CAPABILITY_NAME, self::DIAGNOSTICS_SLUG, array (
					$this,
					'outputDiagnosticsContent' 
			) );
			// When the plugin options page is loaded, also load the stylesheet
			add_action ( 'admin_print_styles-' . $page, array (
					$this,
					'enqueueDiagnosticsScreenStylesheet' 
			) );
		}
		function enqueueDiagnosticsScreenStylesheet() {
			wp_enqueue_style ( self::POSTMAN_STYLE );
			wp_enqueue_script ( 'postman_diagnostics_script' );
		}
		
		/**
		 * Register the Email Test screen
		 */
		public function addPortTestSubmenu() {
			$page = add_submenu_page ( null, _x ( 'Postman Setup', 'Page Title', 'postman-smtp' ), __ ( 'Postman SMTP', 'postman-smtp' ), Postman::MANAGE_POSTMAN_CAPABILITY_NAME, self::PORT_TEST_SLUG, array (
					$this,
					'outputPortTestContent' 
			) );
			// When the plugin options page is loaded, also load the stylesheet
			add_action ( 'admin_print_styles-' . $page, array (
					$this,
					'enqueuePortTestResources' 
			) );
		}
		function enqueuePortTestResources() {
			wp_enqueue_style ( self::POSTMAN_STYLE );
			wp_enqueue_script ( 'postman_port_test_script' );
		}
		
		/**
		 * Register the Email Test screen
		 */
		public function addPurgeDataSubmenu() {
			$page = add_submenu_page ( null, _x ( 'Postman Setup', 'Page Title', 'postman-smtp' ), __ ( 'Postman SMTP', 'postman-smtp' ), Postman::MANAGE_POSTMAN_CAPABILITY_NAME, PostmanAdminController::MANAGE_OPTIONS_PAGE_SLUG, array (
					$this,
					'outputPurgeDataContent' 
			) );
			// When the plugin options page is loaded, also load the stylesheet
			add_action ( 'admin_print_styles-' . $page, array (
					$this,
					'enqueueHomeScreenStylesheet' 
			) );
		}
		
		/**
		 * Register and add settings
		 */
		public function registerStylesAndScripts() {
			// register the stylesheet and javascript external resources
			$pluginData = apply_filters ( 'postman_get_plugin_metadata', null );
			wp_register_style ( self::POSTMAN_STYLE, plugins_url ( 'style/postman.css', $this->rootPluginFilenameAndPath ), null, $pluginData ['version'] );
			wp_register_style ( 'jquery_ui_style', plugins_url ( 'style/jquery-steps/jquery-ui.css', $this->rootPluginFilenameAndPath ), self::POSTMAN_STYLE, '1.1.0' );
			wp_register_style ( 'jquery_steps_style', plugins_url ( 'style/jquery-steps/jquery.steps.css', $this->rootPluginFilenameAndPath ), self::POSTMAN_STYLE, '1.1.0' );
			wp_register_style ( 'postman_send_test_email', plugins_url ( 'style/postman_send_test_email.css', $this->rootPluginFilenameAndPath ), self::POSTMAN_STYLE, $pluginData ['version'] );
			
			wp_register_script ( self::POSTMAN_SCRIPT, plugins_url ( 'script/postman.js', $this->rootPluginFilenameAndPath ), array (
					self::JQUERY_SCRIPT 
			), $pluginData ['version'] );
			wp_register_script ( 'sprintf', plugins_url ( 'script/sprintf/sprintf.min.js', $this->rootPluginFilenameAndPath ), null, '1.0.2' );
			wp_register_script ( 'jquery_steps_script', plugins_url ( 'script/jquery-steps/jquery.steps.min.js', $this->rootPluginFilenameAndPath ), array (
					self::JQUERY_SCRIPT 
			), '1.1.0' );
			wp_register_script ( 'jquery_validation', plugins_url ( 'script/jquery-validate/jquery.validate.min.js', $this->rootPluginFilenameAndPath ), array (
					self::JQUERY_SCRIPT 
			), '1.13.1' );
			wp_register_script ( 'jquery_validation_fr', plugins_url ( 'script/jquery-validate/messages_fr.js', $this->rootPluginFilenameAndPath ), array (
					'jquery_validation' 
			), '1.13.1' );
			wp_register_script ( 'jquery_validation_it', plugins_url ( 'script/jquery-validate/messages_it.js', $this->rootPluginFilenameAndPath ), array (
					'jquery_validation' 
			), '1.13.1' );
			wp_register_script ( 'jquery_validation_tr', plugins_url ( 'script/jquery-validate/messages_tr.js', $this->rootPluginFilenameAndPath ), array (
					'jquery_validation' 
			), '1.13.1' );
			wp_register_script ( 'postman_wizard_script', plugins_url ( 'script/postman_wizard.js', $this->rootPluginFilenameAndPath ), array (
					self::JQUERY_SCRIPT,
					'jquery_validation',
					'jquery_steps_script',
					self::POSTMAN_SCRIPT,
					'sprintf' 
			), $pluginData ['version'] );
			wp_register_script ( 'postman_test_email_wizard_script', plugins_url ( 'script/postman_test_email_wizard.js', $this->rootPluginFilenameAndPath ), array (
					self::JQUERY_SCRIPT,
					'jquery_validation',
					'jquery_steps_script',
					self::POSTMAN_SCRIPT 
			), $pluginData ['version'] );
			wp_register_script ( 'postman_manual_config_script', plugins_url ( 'script/postman_manual_config.js', $this->rootPluginFilenameAndPath ), array (
					self::JQUERY_SCRIPT,
					'jquery_validation',
					self::POSTMAN_SCRIPT 
			), $pluginData ['version'] );
			wp_register_script ( 'postman_port_test_script', plugins_url ( 'script/postman_port_test.js', $this->rootPluginFilenameAndPath ), array (
					self::JQUERY_SCRIPT,
					'jquery_validation',
					self::POSTMAN_SCRIPT,
					'sprintf' 
			), $pluginData ['version'] );
			wp_register_script ( 'postman_diagnostics_script', plugins_url ( 'script/postman_diagnostics.js', $this->rootPluginFilenameAndPath ), array (
					self::JQUERY_SCRIPT,
					self::POSTMAN_SCRIPT 
			), $pluginData ['version'] );
			
			wp_localize_script ( self::POSTMAN_SCRIPT, 'postman_test_in_progress', _x ( 'Checking..', 'The "please wait" message', 'postman-smtp' ) );
			wp_localize_script ( self::POSTMAN_SCRIPT, 'postman_port_test_open', _x ( 'Open', 'The port is open', 'postman-smtp' ) );
			wp_localize_script ( self::POSTMAN_SCRIPT, 'postman_port_test_closed', _x ( 'Closed', 'The port is closed', 'postman-smtp' ) );
			wp_localize_script ( self::POSTMAN_SCRIPT, 'postman_yes', __ ( 'Yes', 'postman-smtp' ) );
			wp_localize_script ( self::POSTMAN_SCRIPT, 'postman_no', __ ( 'No', 'postman-smtp' ) );
			wp_localize_script ( self::POSTMAN_SCRIPT, 'postman_port', _x ( 'Port', 'eg. TCP Port 25', 'postman-smtp' ) );
			wp_localize_script ( self::POSTMAN_SCRIPT, 'postman_email_test', array (
					'not_started' => _x ( 'In Outbox', 'Email Test Status', 'postman-smtp' ),
					'sending' => _x ( 'Sending...', 'Email Test Status', 'postman-smtp' ),
					'success' => _x ( 'Success', 'Email Test Status', 'postman-smtp' ),
					'failed' => _x ( 'Failed', 'Email Test Status', 'postman-smtp' ),
					'ajax_error' => _x ( 'Ajax Error', 'Email Test Status', 'postman-smtp' ) 
			) );
			wp_localize_script ( self::POSTMAN_SCRIPT, 'postman_ajax_msg', array (
					'bad_response' => __ ( 'An unexpected error occurred', 'postman-smtp' ),
					'corrupt_response' => __ ( 'Unexpected PHP messages corrupted the Ajax response', 'postman-smtp' ) 
			) );
			/* translators: where %d is a port number */
			wp_localize_script ( 'postman_port_test_script', 'postman_port_blocked', __ ( 'No outbound route between this site and the Internet on Port %d.', 'postman-smtp' ) );
			/* translators: where %d is a port number and %s is a hostname */
			wp_localize_script ( 'postman_port_test_script', 'postman_try_dif_smtp', __ ( 'Port %d is open, but %s has no service there.', 'postman-smtp' ) );
			/* translators: where %d is a port number and %s is a hostname */
			wp_localize_script ( 'postman_port_test_script', 'postman_smtp_success', __ ( 'Port %d can be used for SMTP to %s.', 'postman-smtp' ) );
			/* translators: where %s is the name of the SMTP server */
			wp_localize_script ( 'postman_port_test_script', 'postman_smtp_mitm', __ ( 'Warning: connected to %1$s instead of %2$s.', 'postman-smtp' ) );
			/* translators: where %s is the name of the SMTP server */
			wp_localize_script ( 'postman_wizard_script', 'postman_smtp_mitm', __ ( 'Warning: connected to %1$s instead of %2$s.', 'postman-smtp' ) );
			/* translators: where %d is a port number and %s is the URL for the Postman Gmail Extension */
			wp_localize_script ( 'postman_port_test_script', 'postman_https_success', __ ( 'Port %d can be used to send email with the %s.', 'postman-smtp' ) );
			/* translators: where %d is a port number */
			wp_localize_script ( 'postman_wizard_script', 'postman_wizard_bad_redirect_url', __ ( 'You are about to configure OAuth 2.0 with an IP address instead of a domain name. This is not permitted. Either assign a real domain name to your site or add a fake one in your local host file.', 'postman-smtp' ) );
			
			wp_localize_script ( 'jquery_steps_script', 'steps_current_step', 'steps_current_step' );
			wp_localize_script ( 'jquery_steps_script', 'steps_pagination', 'steps_pagination' );
			wp_localize_script ( 'jquery_steps_script', 'steps_finish', _x ( 'Finish', 'Press this button to Finish this task', 'postman-smtp' ) );
			wp_localize_script ( 'jquery_steps_script', 'steps_next', _x ( 'Next', 'Press this button to go to the next step', 'postman-smtp' ) );
			wp_localize_script ( 'jquery_steps_script', 'steps_previous', _x ( 'Previous', 'Press this button to go to the previous step', 'postman-smtp' ) );
			wp_localize_script ( 'jquery_steps_script', 'steps_loading', 'steps_loading' );
			
			// user input
			wp_localize_script ( self::POSTMAN_SCRIPT, 'postman_input_sender_email', '#input_' . PostmanOptions::MESSAGE_SENDER_EMAIL );
			wp_localize_script ( self::POSTMAN_SCRIPT, 'postman_input_sender_name', '#input_' . PostmanOptions::MESSAGE_SENDER_NAME );
			wp_localize_script ( self::POSTMAN_SCRIPT, 'postman_port_element_name', '#input_' . PostmanOptions::PORT );
			wp_localize_script ( self::POSTMAN_SCRIPT, 'postman_hostname_element_name', '#input_' . PostmanOptions::HOSTNAME );
			
			// the enc input
			wp_localize_script ( self::POSTMAN_SCRIPT, 'postman_enc_for_password_el', '#input_enc_type_password' );
			// these are the ids for the <option>s in the encryption <select>
			
			// the password inputs
			wp_localize_script ( self::POSTMAN_SCRIPT, 'postman_input_basic_username', '#input_' . PostmanOptions::BASIC_AUTH_USERNAME );
			wp_localize_script ( self::POSTMAN_SCRIPT, 'postman_input_basic_password', '#input_' . PostmanOptions::BASIC_AUTH_PASSWORD );
			
			// the auth input
			wp_localize_script ( self::POSTMAN_SCRIPT, 'postman_redirect_url_el', '#input_oauth_redirect_url' );
			wp_localize_script ( self::POSTMAN_SCRIPT, 'postman_input_auth_type', '#input_' . PostmanOptions::AUTHENTICATION_TYPE );
		}
		
		/**
		 * Options page callback
		 */
		public function outputDefaultContent() {
			// Set class property
			print '<div class="wrap">';
			$this->displayTopNavigation ();
			if (! PostmanPreRequisitesCheck::isReady ()) {
				printf ( '<p><span style="color:red; padding:2px 0; font-size:1.1em">%s</span></p>', __ ( 'Postman is unable to run. Email delivery is being handled by WordPress (or another plugin).', 'postman-smtp' ) );
			} else {
				$statusMessage = PostmanTransportRegistry::getInstance ()->getReadyMessage ();
				if (PostmanTransportRegistry::getInstance ()->getActiveTransport ()->isConfiguredAndReady ()) {
					if ($this->options->getRunMode () != PostmanOptions::RUN_MODE_PRODUCTION) {
						printf ( '<p><span style="background-color:yellow">%s</span></p>', $statusMessage );
					} else {
						printf ( '<p><span style="color:green;padding:2px 0; font-size:1.1em">%s</span></p>', $statusMessage );
					}
				} else {
					printf ( '<p><span style="color:red; padding:2px 0; font-size:1.1em">%s</span></p>', $statusMessage );
				}
				$this->printDeliveryDetails ();
				/* translators: where %d is the number of emails delivered */
				print '<p style="margin:10px 10px"><span>';
				printf ( _n ( 'Postman has delivered <span style="color:green">%d</span> email.', 'Postman has delivered <span style="color:green">%d</span> emails.', PostmanState::getInstance ()->getSuccessfulDeliveries (), 'postman-smtp' ), PostmanState::getInstance ()->getSuccessfulDeliveries () );
				if ($this->options->isMailLoggingEnabled ()) {
					print ' ';
					printf ( __ ( 'The last %d email attempts are recorded <a href="%s">in the log</a>.', 'postman-smtp' ), PostmanOptions::getInstance ()->getMailLoggingMaxEntries (), PostmanUtils::getEmailLogPageUrl () );
				}
				print '</span></p>';
			}
			if ($this->options->isNew ()) {
				printf ( '<h3 style="padding-top:10px">%s</h3>', __ ( 'Thank-you for choosing Postman!', 'postman-smtp' ) );
				/* translators: where %s is the URL of the Setup Wizard */
				printf ( '<p><span>%s</span></p>', sprintf ( __ ( 'Let\'s get started! All users are strongly encouraged to <a href="%s">run the Setup Wizard</a>.', 'postman-smtp' ), $this->getPageUrl ( self::CONFIGURATION_WIZARD_SLUG ) ) );
				printf ( '<p><span>%s</span></p>', sprintf ( __ ( 'Alternately, <a href="%s">manually configure</a> your own settings and/or modify advanced options.', 'postman-smtp' ), $this->getPageUrl ( self::CONFIGURATION_SLUG ) ) );
			} else {
				if (PostmanState::getInstance ()->isTimeToReviewPostman () && ! PostmanOptions::getInstance ()->isNew ()) {
					print '</br><hr width="70%"></br>';
					/* translators: where %s is the URL to the WordPress.org review and ratings page */
					printf ( '%s</span></p>', sprintf ( __ ( 'Please consider <a href="%s">leaving a review</a> to help spread the word! :D', 'postman-smtp' ), 'https://wordpress.org/support/view/plugin-reviews/postman-smtp?filter=5' ) );
				}
				printf ( '<p><span>%s :-)</span></p>', sprintf ( __ ( 'Postman needs translators! Please take a moment to <a href="%s">translate a few sentences on-line</a>', 'postman-smtp' ), 'https://translate.wordpress.org/projects/wp-plugins/postman-smtp/stable' ) );
			}
			printf ( '<p><span>%s</span></p>', __ ( '<b style="background-color:yellow">New for v1.7!</style></b> Send mail with the Mandrill or SendGrid APIs.', 'postman-smtp' ) );
		}
		
		/**
		 */
		private function printDeliveryDetails() {
			$currentTransport = PostmanTransportRegistry::getInstance ()->getActiveTransport ();
			$deliveryDetails = $currentTransport->getDeliveryDetails ( $this->options );
			printf ( '<p style="margin:0 10px"><span>%s</span></p>', $deliveryDetails );
		}
		
		/**
		 *
		 * @param unknown $title        	
		 * @param string $slug        	
		 */
		private function outputChildPageHeader($title, $slug = '') {
			printf ( '<h2>%s</h2>', _x ( 'Postman Setup', 'Page Title', 'postman-smtp' ) );
			printf ( '<div id="postman-main-menu" class="welcome-panel %s">', $slug );
			print '<div class="welcome-panel-content">';
			print '<div class="welcome-panel-column-container">';
			print '<div class="welcome-panel-column welcome-panel-last">';
			printf ( '<h4>%s</h4>', $title );
			print '</div>';
			printf ( '<p id="back_to_main_menu">%s <a id="back_to_menu_link" href="%s">%s</a></p>', self::BACK_ARROW_SYMBOL, PostmanUtils::getSettingsPageUrl (), _x ( 'Back To Main Menu', 'Return to main menu link', 'postman-smtp' ) );
			print '</div></div></div>';
		}
		
		/**
		 */
		public function outputManualConfigurationContent() {
			print '<div class="wrap">';
			
			$this->outputChildPageHeader ( __ ( 'Settings', 'postman-smtp' ), 'advanced_config' );
			print '<div id="config_tabs"><ul>';
			print sprintf ( '<li><a href="#account_config">%s</a></li>', _x ( 'Account', 'Advanced Configuration Tab Label', 'postman-smtp' ) );
			print sprintf ( '<li><a href="#message_config">%s</a></li>', _x ( 'Message', 'Advanced Configuration Tab Label', 'postman-smtp' ) );
			print sprintf ( '<li><a href="#logging_config">%s</a></li>', _x ( 'Logging', 'Advanced Configuration Tab Label', 'postman-smtp' ) );
			print sprintf ( '<li><a href="#advanced_options_config">%s</a></li>', _x ( 'Advanced', 'Advanced Configuration Tab Label', 'postman-smtp' ) );
			print '</ul>';
			print '<form method="post" action="options.php">';
			// This prints out all hidden setting fields
			settings_fields ( PostmanAdminController::SETTINGS_GROUP_NAME );
			print '<section id="account_config">';
			if (sizeof ( PostmanTransportRegistry::getInstance ()->getTransports () ) > 1) {
				do_settings_sections ( 'transport_options' );
			} else {
				printf ( '<input id="input_%2$s" type="hidden" name="%1$s[%2$s]" value="%3$s"/>', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::TRANSPORT_TYPE, PostmanSmtpModuleTransport::SLUG );
			}
			print '<div id="smtp_config" class="transport_setting">';
			do_settings_sections ( PostmanAdminController::SMTP_OPTIONS );
			print '</div>';
			print '<div id="password_settings" class="authentication_setting non-oauth2">';
			do_settings_sections ( PostmanAdminController::BASIC_AUTH_OPTIONS );
			print '</div>';
			print '<div id="oauth_settings" class="authentication_setting non-basic">';
			do_settings_sections ( PostmanAdminController::OAUTH_AUTH_OPTIONS );
			print '</div>';
			print '<div id="mandrill_settings" class="authentication_setting non-basic non-oauth2">';
			do_settings_sections ( PostmanMandrillTransport::MANDRILL_AUTH_OPTIONS );
			print '</div>';
			print '<div id="sendgrid_settings" class="authentication_setting non-basic non-oauth2">';
			do_settings_sections ( PostmanSendGridTransport::SENDGRID_AUTH_OPTIONS );
			print '</div>';
			print '</section>';
			print '<section id="message_config">';
			do_settings_sections ( PostmanAdminController::MESSAGE_SENDER_OPTIONS );
			do_settings_sections ( PostmanAdminController::MESSAGE_FROM_OPTIONS );
			do_settings_sections ( PostmanAdminController::EMAIL_VALIDATION_OPTIONS );
			do_settings_sections ( PostmanAdminController::MESSAGE_OPTIONS );
			do_settings_sections ( PostmanAdminController::MESSAGE_HEADERS_OPTIONS );
			print '</section>';
			print '<section id="logging_config">';
			do_settings_sections ( PostmanAdminController::LOGGING_OPTIONS );
			print '</section>';
			/*
			 * print '<section id="logging_config">';
			 * do_settings_sections ( PostmanAdminController::MULTISITE_OPTIONS );
			 * print '</section>';
			 */
			print '<section id="advanced_options_config">';
			do_settings_sections ( PostmanAdminController::NETWORK_OPTIONS );
			do_settings_sections ( PostmanAdminController::ADVANCED_OPTIONS );
			print '</section>';
			submit_button ();
			print '</form>';
			print '</div>';
			print '</div>';
		}
		
		/**
		 */
		public function outputPurgeDataContent() {
			$importTitle = __ ( 'Import', 'postman-smtp' );
			$exportTile = __ ( 'Export', 'postman-smtp' );
			$resetTitle = __ ( 'Reset Plugin', 'postman-smtp' );
			$options = $this->options;
			print '<div class="wrap">';
			$this->outputChildPageHeader ( sprintf ( '%s/%s/%s', $importTitle, $exportTile, $resetTitle ) );
			print '<section id="export_settings">';
			printf ( '<h3><span>%s<span></h3>', $exportTile );
			printf ( '<p><span>%s</span></p>', __ ( 'Copy this data into another instance of Postman to duplicate the configuration.', 'postman-smtp' ) );
			$data = '';
			if (! $options->isNew ()) {
				$data = $options->options;
				$data ['version'] = PostmanState::getInstance ()->getVersion ();
				foreach ( PostmanTransportRegistry::getInstance ()->getTransports () as $transport ) {
					$data = $transport->prepareOptionsForExport ( $data );
				}
				$data = base64_encode ( gzcompress ( json_encode ( $data ), 9, ZLIB_ENCODING_DEFLATE ) );
			}
			printf ( '<textarea cols="80" rows="5" readonly="true" name="settings">%s</textarea>', $data );
			print '</section>';
			print '<section id="import_settings">';
			printf ( '<h3><span>%s<span></h3>', $importTitle );
			print '<form method="POST" action="' . get_admin_url () . 'admin-post.php">';
			wp_nonce_field ( PostmanAdminController::IMPORT_SETTINGS_SLUG );
			printf ( '<input type="hidden" name="action" value="%s" />', PostmanAdminController::IMPORT_SETTINGS_SLUG );
			print '<p>';
			printf ( '<span>%s</span>', __ ( 'Paste data from another instance of Postman here to duplicate the configuration.', 'postman-smtp' ) );
			if (PostmanTransportRegistry::getInstance ()->getSelectedTransport ()->isOAuthUsed ( PostmanOptions::getInstance ()->getAuthenticationType () )) {
				printf ( ' <span>%s</span>', __ ( '<b>Warning</b>: Using the same OAuth 2.0 Client ID and Client Secret from this site at the same time as another site will cause failures.', 'postman-smtp' ) );
			}
			print '</p>';
			print ('<textarea cols="80" rows="5" name="settings"></textarea>') ;
			submit_button ( _x ( 'Import', 'Button Label', 'postman-smtp' ) );
			print '</form>';
			print '</section>';
			print '<section id="delete_settings">';
			printf ( '<h3><span>%s<span></h3>', $resetTitle );
			print '<form method="POST" action="' . get_admin_url () . 'admin-post.php">';
			wp_nonce_field ( PostmanAdminController::PURGE_DATA_SLUG );
			printf ( '<input type="hidden" name="action" value="%s" />', PostmanAdminController::PURGE_DATA_SLUG );
			printf ( '<p><span>%s</span></p><p><span>%s</span></p>', __ ( 'This will purge all of Postman\'s settings, including account credentials and the email log.', 'postman-smtp' ), __ ( 'Are you sure?', 'postman-smtp' ) );
			$extraDeleteButtonAttributes = 'style="background-color:red;color:white"';
			if ($this->options->isNew ()) {
				$extraDeleteButtonAttributes .= ' disabled="true"';
			}
			submit_button ( $resetTitle, 'delete', 'submit', true, $extraDeleteButtonAttributes );
			print '</form>';
			print '</section>';
			print '</div>';
		}
		
		/**
		 */
		public function outputPortTestContent() {
			print '<div class="wrap">';
			
			$this->outputChildPageHeader ( __ ( 'Connectivity Test', 'postman-smtp' ) );
			
			print '<p>';
			print __ ( 'This test determines which well-known ports are available for Postman to use.', 'postman-smtp' );
			print '<form id="port_test_form_id" method="post">';
			printf ( '<label for="hostname">%s</label>', __ ( 'Outgoing Mail Server Hostname', 'postman-smtp' ) );
			$this->adminController->port_test_hostname_callback ();
			submit_button ( _x ( 'Begin Test', 'Button Label', 'postman-smtp' ), 'primary', 'begin-port-test', true );
			print '</form>';
			print '<table id="connectivity_test_table">';
			print sprintf ( '<tr><th rowspan="2">%s</th><th rowspan="2">%s</th><th rowspan="2">%s</th><th rowspan="2">%s</th><th rowspan="2">%s</th><th colspan="5">%s</th></tr>', __ ( 'Transport', 'postman-smtp' ), _x ( 'Socket', 'A socket is the network term for host and port together', 'postman-smtp' ), __ ( 'Port Status', 'postman-smtp' ), __ ( 'Service Available', 'postman-smtp' ), __ ( 'Server ID', 'postman-smtp' ), __ ( 'Authentication', 'postman-smtp' ) );
			print sprintf ( '<tr><th>%s</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th></tr>', 'None', 'Login', 'Plain', 'CRAM-MD5', 'OAuth 2.0' );
			$sockets = PostmanTransportRegistry::getInstance ()->getSocketsForSetupWizardToProbe ();
			foreach ( $sockets as $socket ) {
				if ($socket ['smtp']) {
					print sprintf ( '<tr id="%s"><th class="name">%s</th><td class="socket">%s:%s</td><td class="firewall resettable">-</td><td class="service resettable">-</td><td class="reported_id resettable">-</td><td class="auth_none resettable">-</td><td class="auth_login resettable">-</td><td class="auth_plain resettable">-</td><td class="auth_crammd5 resettable">-</td><td class="auth_xoauth2 resettable">-</td></tr>', $socket ['id'], $socket ['transport_name'], $socket ['host'], $socket ['port'] );
				} else {
					print sprintf ( '<tr id="%s"><th class="name">%s</th><td class="socket">%s:%s</td><td class="firewall resettable">-</td><td class="service resettable">-</td><td class="reported_id resettable">-</td><td colspan="5">%s</td></tr>', $socket ['id'], $socket ['transport_name'], $socket ['host'], $socket ['port'], __ ( 'n/a', 'postman-smtp' ) );
				}
			}
			print '</table>';
			printf ( '<p class="ajax-loader" style="display:none"><img src="%s"/></p>', plugins_url ( 'postman-smtp/style/ajax-loader.gif' ) );
			print '<section id="conclusion" style="display:none">';
			print sprintf ( '<h3>%s:</h3>', __ ( 'Summary', 'postman-smtp' ) );
			print '<ol class="conclusion">';
			print '</ol>';
			print '</section>';
			print '<section id="blocked-port-help" style="display:none">';
			print sprintf ( '<p><b>%s</b></p>', __ ( 'A test with <span style="color:red">"No"</span> Service Available indicates one or more of these issues:', 'postman-smtp' ) );
			print '<ol>';
			printf ( '<li>%s</li>', __ ( 'Your web host has placed a firewall between this site and the Internet', 'postman-smtp' ) );
			printf ( '<li>%s</li>', __ ( 'The SMTP hostname is wrong or the mail server does not provide service on this port', 'postman-smtp' ) );
			/* translators: where %s is the URL to the PHP documentation on 'allow-url-fopen' */
			printf ( '<li>%s</li>', sprintf ( __ ( 'Your <a href="%s">PHP configuration</a> is preventing outbound connections', 'postman-smtp' ), 'http://php.net/manual/en/filesystem.configuration.php#ini.allow-url-fopen' ) );
			/* translators: where %s is the URL to an article on disabling external requests in WordPress */
			printf ( '<li>%s</li>', sprintf ( __ ( 'Your <a href="%s">WordPress configuration</a> is preventing outbound connections', 'postman-smtp' ), 'http://wp-mix.com/disable-external-url-requests/' ) );
			print '</ol></p>';
			print sprintf ( '<p><b>%s</b></p>', __ ( 'If the issues above can not be resolved, your last option is to configure Postman to use an email account managed by your web host with an SMTP server managed by your web host.', 'postman-smtp' ) );
			print '</section>';
			print '</div>';
		}
		
		/**
		 */
		public function outputDiagnosticsContent() {
			// test features
			print '<div class="wrap">';
			
			$this->outputChildPageHeader ( __ ( 'Diagnostic Test', 'postman-smtp' ) );
			
			printf ( '<h4>%s</h4>', __ ( 'Are you having issues with Postman?', 'postman-smtp' ) );
			/* translators: where %1$s and %2$s are the URLs to the Troubleshooting and Support Forums on WordPress.org */
			printf ( '<p style="margin:0 10px">%s</p>', sprintf ( __ ( 'Please check the <a href="%1$s">troubleshooting and error messages</a> page and the <a href="%2$s">support forum</a>.', 'postman-smtp' ), 'https://wordpress.org/plugins/postman-smtp/other_notes/', 'https://wordpress.org/support/plugin/postman-smtp' ) );
			printf ( '<h4>%s</h4>', __ ( 'Diagnostic Test', 'postman-smtp' ) );
			printf ( '<p style="margin:0 10px">%s</p><br/>', sprintf ( __ ( 'If you write for help, please include the following:', 'postman-smtp' ), 'https://wordpress.org/plugins/postman-smtp/other_notes/', 'https://wordpress.org/support/plugin/postman-smtp' ) );
			printf ( '<textarea readonly="readonly" id="diagnostic-text" cols="80" rows="15">%s</textarea>', _x ( 'Checking..', 'The "please wait" message', 'postman-smtp' ) );
			print '</div>';
		}
		
		/**
		 */
		private function displayTopNavigation() {
			screen_icon ();
			printf ( '<h2>%s</h2>', _x ( 'Postman Setup', 'Page Title', 'postman-smtp' ) );
			print '<div id="postman-main-menu" class="welcome-panel">';
			print '<div class="welcome-panel-content">';
			print '<div class="welcome-panel-column-container">';
			print '<div class="welcome-panel-column">';
			printf ( '<h4>%s</h4>', _x ( 'Configuration', 'The configuration page of the plugin', 'postman-smtp' ) );
			printf ( '<a class="button button-primary button-hero" href="%s">%s</a>', $this->getPageUrl ( self::CONFIGURATION_WIZARD_SLUG ), __ ( 'Start the Wizard', 'postman-smtp' ) );
			printf ( '<p class="">or <a href="%s" class="configure_manually">%s</a></p>', $this->getPageUrl ( self::CONFIGURATION_SLUG ), __ ( 'Show All Settings', 'postman-smtp' ) );
			print '</div>';
			print '<div class="welcome-panel-column">';
			printf ( '<h4>%s</h4>', _x ( 'Actions', 'Main Menu', 'postman-smtp' ) );
			print '<ul>';
			
			// Grant permission with Google
			PostmanTransportRegistry::getInstance ()->getSelectedTransport ()->printActionMenuItem ();
			
			if (PostmanWpMailBinder::getInstance ()->isBound ()) {
				printf ( '<li><a href="%s" class="welcome-icon send_test_email">%s</a></li>', $this->getPageUrl ( self::EMAIL_TEST_SLUG ), __ ( 'Send a Test Email', 'postman-smtp' ) );
			} else {
				printf ( '<li><div class="welcome-icon send_test_email">%s</div></li>', __ ( 'Send a Test Email', 'postman-smtp' ) );
			}
			
			// import-export-reset menu item
			if (! $this->options->isNew () || true) {
				$purgeLinkPattern = '<li><a href="%1$s" class="welcome-icon oauth-authorize">%2$s</a></li>';
			} else {
				$purgeLinkPattern = '<li>%2$s</li>';
			}
			$importTitle = __ ( 'Import', 'postman-smtp' );
			$exportTile = __ ( 'Export', 'postman-smtp' );
			$resetTitle = __ ( 'Reset Plugin', 'postman-smtp' );
			$importExportReset = sprintf ( '%s/%s/%s', $importTitle, $exportTile, $resetTitle );
			printf ( $purgeLinkPattern, $this->getPageUrl ( PostmanAdminController::MANAGE_OPTIONS_PAGE_SLUG ), sprintf ( '%s', $importExportReset ) );
			print '</ul>';
			print '</div>';
			print '<div class="welcome-panel-column welcome-panel-last">';
			printf ( '<h4>%s</h4>', _x ( 'Troubleshooting', 'Main Menu', 'postman-smtp' ) );
			print '<ul>';
			printf ( '<li><a href="%s" class="welcome-icon run-port-test">%s</a></li>', $this->getPageUrl ( self::PORT_TEST_SLUG ), __ ( 'Connectivity Test', 'postman-smtp' ) );
			printf ( '<li><a href="%s" class="welcome-icon run-port-test">%s</a></li>', $this->getPageUrl ( self::DIAGNOSTICS_SLUG ), __ ( 'Diagnostic Test', 'postman-smtp' ) );
			printf ( '<li><a href="https://wordpress.org/support/plugin/postman-smtp" class="welcome-icon postman_support">%s</a></li>', __ ( 'Online Support', 'postman-smtp' ) );
			print '</ul></div></div></div></div>';
		}
		
		/**
		 */
		public function outputWizardContent() {
			// Set default values for input fields
			$this->options->setMessageSenderEmailIfEmpty ( wp_get_current_user ()->user_email );
			$this->options->setMessageSenderNameIfEmpty ( wp_get_current_user ()->display_name );
			
			// construct Wizard
			print '<div class="wrap">';
			
			$this->outputChildPageHeader ( __ ( 'Postman Setup Wizard', 'postman-smtp' ) );
			
			print '<form id="postman_wizard" method="post" action="options.php">';
			
			// account tab
			
			// message tab
			printf ( '<input type="hidden" id="input_%2$s" name="%1$s[%2$s]" value="%3$s" />', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::PREVENT_MESSAGE_SENDER_EMAIL_OVERRIDE, $this->options->isPluginSenderEmailEnforced () );
			printf ( '<input type="hidden" id="input_%2$s" name="%1$s[%2$s]" value="%3$s" />', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::PREVENT_MESSAGE_SENDER_NAME_OVERRIDE, $this->options->isPluginSenderNameEnforced () );
			printf ( '<input type="hidden" id="input_%2$s" name="%1$s[%2$s]" value="%3$s" />', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::REPLY_TO, $this->options->getReplyTo () );
			printf ( '<input type="hidden" id="input_%2$s" name="%1$s[%2$s]" value="%3$s" />', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::FORCED_TO_RECIPIENTS, $this->options->getForcedToRecipients () );
			printf ( '<input type="hidden" id="input_%2$s" name="%1$s[%2$s]" value="%3$s" />', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::FORCED_CC_RECIPIENTS, $this->options->getForcedCcRecipients () );
			printf ( '<input type="hidden" id="input_%2$s" name="%1$s[%2$s]" value="%3$s" />', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::FORCED_BCC_RECIPIENTS, $this->options->getForcedBccRecipients () );
			printf ( '<input type="hidden" id="input_%2$s" name="%1$s[%2$s]" value="%3$s" />', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::ADDITIONAL_HEADERS, $this->options->getAdditionalHeaders () );
			printf ( '<input type="hidden" id="input_%2$s" name="%1$s[%2$s]" value="%3$s" />', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::DISABLE_EMAIL_VALIDAITON, $this->options->isEmailValidationDisabled () );
			
			// logging tab
			printf ( '<input type="hidden" id="input_%2$s" name="%1$s[%2$s]" value="%3$s" />', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::MAIL_LOG_ENABLED_OPTION, $this->options->getMailLoggingEnabled () );
			printf ( '<input type="hidden" id="input_%2$s" name="%1$s[%2$s]" value="%3$s" />', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::MAIL_LOG_MAX_ENTRIES, $this->options->getMailLoggingMaxEntries () );
			printf ( '<input type="hidden" id="input_%2$s" name="%1$s[%2$s]" value="%3$s" />', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::TRANSCRIPT_SIZE, $this->options->getTranscriptSize () );
			
			// advanced tab
			printf ( '<input type="hidden" id="input_%2$s" name="%1$s[%2$s]" value="%3$s" />', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::CONNECTION_TIMEOUT, $this->options->getConnectionTimeout () );
			printf ( '<input type="hidden" id="input_%2$s" name="%1$s[%2$s]" value="%3$s" />', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::READ_TIMEOUT, $this->options->getReadTimeout () );
			printf ( '<input type="hidden" id="input_%2$s" name="%1$s[%2$s]" value="%3$s" />', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::LOG_LEVEL, $this->options->getLogLevel () );
			printf ( '<input type="hidden" id="input_%2$s" name="%1$s[%2$s]" value="%3$s" />', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::RUN_MODE, $this->options->getRunMode () );
			printf ( '<input type="hidden" id="input_%2$s" name="%1$s[%2$s]" value="%3$s" />', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::STEALTH_MODE, $this->options->isStealthModeEnabled () );
			printf ( '<input type="hidden" id="input_%2$s" name="%1$s[%2$s]" value="%3$s" />', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::TEMPORARY_DIRECTORY, $this->options->getTempDirectory () );
			
			// display the setting text
			settings_fields ( PostmanAdminController::SETTINGS_GROUP_NAME );
			
			// Wizard Step 0
			printf ( '<h5>%s</h5>', _x ( 'Import Configuration', 'Wizard Step Title', 'postman-smtp' ) );
			print '<fieldset>';
			printf ( '<legend>%s</legend>', _x ( 'Import configuration from another plugin?', 'Wizard Step Title', 'postman-smtp' ) );
			printf ( '<p>%s</p>', __ ( 'If you had a working configuration with another Plugin, the Setup Wizard can begin with those settings.', 'postman-smtp' ) );
			print '<table class="input_auth_type">';
			printf ( '<tr><td><input type="radio" id="import_none" name="input_plugin" value="%s" checked="checked"></input></td><td><label> %s</label></td></tr>', 'none', __ ( 'None', 'postman-smtp' ) );
			
			if ($this->importableConfiguration->isImportAvailable ()) {
				foreach ( $this->importableConfiguration->getAvailableOptions () as $options ) {
					printf ( '<tr><td><input type="radio" name="input_plugin" value="%s"/></td><td><label> %s</label></td></tr>', $options->getPluginSlug (), $options->getPluginName () );
				}
			}
			print '</table>';
			print '</fieldset>';
			
			// Wizard Step 1
			printf ( '<h5>%s</h5>', _x ( 'Sender Details', 'Wizard Step Title', 'postman-smtp' ) );
			print '<fieldset>';
			printf ( '<legend>%s</legend>', _x ( 'Who is the mail coming from?', 'Wizard Step Title', 'postman-smtp' ) );
			printf ( '<p>%s</p>', __ ( 'Enter the email address and name you\'d like to send mail as.', 'postman-smtp' ) );
			printf ( '<p>%s</p>', __ ( 'Please note that to prevent abuse, many email services will <em>not</em> let you send from an email address other than the one you authenticate with.', 'postman-smtp' ) );
			printf ( '<label for="postman_options[sender_email]">%s</label>', __ ( 'Email Address', 'postman-smtp' ) );
			print $this->adminController->from_email_callback ();
			print '<br/>';
			printf ( '<label for="postman_options[sender_name]">%s</label>', __ ( 'Name', 'postman-smtp' ) );
			print $this->adminController->sender_name_callback ();
			print '</fieldset>';
			
			// Wizard Step 2
			printf ( '<h5>%s</h5>', __ ( 'Outgoing Mail Server Hostname', 'postman-smtp' ) );
			print '<fieldset>';
			foreach ( PostmanTransportRegistry::getInstance ()->getTransports () as $transport ) {
				$transport->printWizardMailServerHostnameStep ();
			}
			print '</fieldset>';
			
			// Wizard Step 3
			printf ( '<h5>%s</h5>', _x ( 'Connectivity Test', 'A testing tool which determines connectivity to the Internet', 'postman-smtp' ) );
			print '<fieldset>';
			printf ( '<legend>%s</legend>', __ ( 'How will the connection to the mail server be established?', 'postman-smtp' ) );
			printf ( '<p>%s</p>', __ ( 'Your connection settings depend on what your email service provider offers, and what your WordPress host allows.', 'postman-smtp' ) );
			printf ( '<p id="connectivity_test_status">%s: <span id="port_test_status">%s</span></p>', _x ( 'Connectivity Test', 'A testing tool which determines connectivity to the Internet', 'postman-smtp' ), _x ( 'Ready', 'TCP Port Test Status', 'postman-smtp' ) );
			printf ( '<p class="ajax-loader" style="display:none"><img src="%s"/></p>', plugins_url ( 'postman-smtp/style/ajax-loader.gif' ) );
			printf ( '<input type="hidden" id="input_%2$s" name="%1$s[%2$s]">', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::TRANSPORT_TYPE );
			printf ( '<input type="hidden" id="input_%2$s" name="%1$s[%2$s]">', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::PORT );
			printf ( '<input type="hidden" id="input_%2$s" name="%1$s[%2$s]">', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::SECURITY_TYPE );
			printf ( '<input type="hidden" id="input_%2$s" name="%1$s[%2$s]">', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::AUTHENTICATION_TYPE );
			print '<p id="wizard_recommendation"></p>';
			/* Translators: Where %1$s is the socket identifier and %2$s is the authentication type */
			printf ( '<p class="user_override" style="display:none"><label><span>%s:</span></label> <table id="user_socket_override" class="user_override"></table></p>', _x ( 'Socket', 'A socket is the network term for host and port together', 'postman-smtp' ) );
			printf ( '<p class="user_override" style="display:none"><label><span>%s:</span></label> <table id="user_auth_override" class="user_override"></table></p>', __ ( 'Authentication', 'postman-smtp' ) );
			print ('<p><span id="smtp_mitm" style="display:none; background-color:yellow"></span></p>') ;
			printf ( '<p id="smtp_not_secure" style="display:none"><span style="background-color:yellow">%s</span></p>', __ ( 'Warning: This configuration option will send your authorization credentials in the clear.', 'postman-smtp' ) );
			print '</fieldset>';
			
			// Wizard Step 4
			printf ( '<h5>%s</h5>', __ ( 'Authentication', 'postman-smtp' ) );
			print '<fieldset>';
			printf ( '<legend>%s</legend>', __ ( 'How will you prove your identity to the mail server?', 'postman-smtp' ) );
			foreach ( PostmanTransportRegistry::getInstance ()->getTransports () as $transport ) {
				$transport->printWizardAuthenticationStep ();
			}
			print '</fieldset>';
			
			// Wizard Step 5
			printf ( '<h5>%s</h5>', _x ( 'Finish', 'The final step of the Wizard', 'postman-smtp' ) );
			print '<fieldset>';
			printf ( '<legend>%s</legend>', _x ( 'You\'re Done!', 'Wizard Step Title', 'postman-smtp' ) );
			print '<section>';
			printf ( '<p>%s</p>', __ ( 'Click Finish to save these settings, then:', 'postman-smtp' ) );
			print '<ul style="margin-left: 20px">';
			printf ( '<li class="wizard-auth-oauth2">%s</li>', __ ( 'Grant permission with the Email Provider for Postman to send email and', 'postman-smtp' ) );
			printf ( '<li>%s</li>', __ ( 'Send yourself a Test Email to make sure everything is working!', 'postman-smtp' ) );
			print '</ul>';
			print '</section>';
			print '</fieldset>';
			print '</form>';
			print '</div>';
		}
		
		/**
		 */
		public function outputTestEmailWizardContent() {
			print '<div class="wrap">';
			
			$this->outputChildPageHeader ( __ ( 'Send a Test Email', 'postman-smtp' ) );
			
			printf ( '<form id="postman_test_email_wizard" method="post" action="%s">', PostmanUtils::getSettingsPageUrl () );
			
			// Step 1
			printf ( '<h5>%s</h5>', __ ( 'Specify the Recipient', 'postman-smtp' ) );
			print '<fieldset>';
			printf ( '<legend>%s</legend>', __ ( 'Who is this message going to?', 'postman-smtp' ) );
			printf ( '<p>%s', __ ( 'This utility allows you to send an email message for testing.', 'postman-smtp' ) );
			print ' ';
			/* translators: where %d is an amount of time, in seconds */
			printf ( '%s</p>', sprintf ( _n ( 'If there is a problem, Postman will give up after %d second.', 'If there is a problem, Postman will give up after %d seconds.', $this->options->getReadTimeout (), 'postman-smtp' ), $this->options->getReadTimeout () ) );
			printf ( '<label for="postman_test_options[test_email]">%s</label>', _x ( 'Recipient Email Address', 'Configuration Input Field', 'postman-smtp' ) );
			print $this->adminController->test_email_callback ();
			print '</fieldset>';
			
			// Step 2
			printf ( '<h5>%s</h5>', __ ( 'Send The Message', 'postman-smtp' ) );
			print '<fieldset>';
			print '<legend>';
			print __ ( 'Sending the message:', 'postman-smtp' );
			printf ( ' <span id="postman_test_message_status">%s</span>', _x ( 'In Outbox', 'Email Test Status', 'postman-smtp' ) );
			print '</legend>';
			print '<section>';
			printf ( '<p><label>%s</label></p>', __ ( 'Status', 'postman-smtp' ) );
			print '<textarea id="postman_test_message_error_message" readonly="readonly" cols="65" rows="4"></textarea>';
			print '</section>';
			print '</fieldset>';
			
			// Step 3
			printf ( '<h5>%s</h5>', __ ( 'Session Transcript', 'postman-smtp' ) );
			print '<fieldset>';
			printf ( '<legend>%s</legend>', __ ( 'Examine the Session Transcript if you need to.', 'postman-smtp' ) );
			printf ( '<p>%s</p>', __ ( 'This is the conversation between Postman and the mail server. It can be useful for diagnosing problems. <b>DO NOT</b> post it on-line, it may contain your account password.', 'postman-smtp' ) );
			print '<section>';
			printf ( '<p><label for="postman_test_message_transcript">%s</label></p>', __ ( 'Session Transcript', 'postman-smtp' ) );
			print '<textarea readonly="readonly" id="postman_test_message_transcript" cols="65" rows="8"></textarea>';
			print '</section>';
			print '</fieldset>';
			
			print '</form>';
			print '</div>';
		}
	}
}
		