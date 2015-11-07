<?php
if (! class_exists ( "PostmanAdminController" )) {
	
	require_once 'PostmanOptions.php';
	require_once 'PostmanState.php';
	require_once 'PostmanState.php';
	require_once 'PostmanOAuthToken.php';
	require_once 'Postman-Wizard/Postman-PortTest.php';
	require_once 'Postman-Wizard/PostmanSmtpDiscovery.php';
	require_once 'PostmanInputSanitizer.php';
	require_once 'Postman-Connectors/PostmanImportableConfiguration.php';
	require_once 'PostmanConfigTextHelper.php';
	require_once 'PostmanAjaxController.php';
	require_once 'PostmanViewController.php';
	require_once 'PostmanPreRequisitesCheck.php';
	require_once 'Postman-Auth/PostmanAuthenticationManagerFactory.php';
	
	//
	class PostmanAdminController {
		
		// this is the slug used in the URL
		const MANAGE_OPTIONS_PAGE_SLUG = 'postman/manage-options';
		
		// NONCE NAMES
		const PURGE_DATA_SLUG = 'postman_purge_data';
		const IMPORT_SETTINGS_SLUG = 'postman_import_settings';
		
		// The Postman Group is used for saving data, make sure it is globally unique
		const SETTINGS_GROUP_NAME = 'postman_group';
		
		// a database entry specifically for the form that sends test e-mail
		const TEST_OPTIONS = 'postman_test_options';
		const SMTP_OPTIONS = 'postman_smtp_options';
		const SMTP_SECTION = 'postman_smtp_section';
		const BASIC_AUTH_OPTIONS = 'postman_basic_auth_options';
		const BASIC_AUTH_SECTION = 'postman_basic_auth_section';
		const OAUTH_AUTH_OPTIONS = 'postman_oauth_options';
		const OAUTH_SECTION = 'postman_oauth_section';
		const MESSAGE_SENDER_OPTIONS = 'postman_message_sender_options';
		const MESSAGE_SENDER_SECTION = 'postman_message_sender_section';
		const MESSAGE_FROM_OPTIONS = 'postman_message_from_options';
		const MESSAGE_FROM_SECTION = 'postman_message_from_section';
		const MESSAGE_OPTIONS = 'postman_message_options';
		const MESSAGE_SECTION = 'postman_message_section';
		const MESSAGE_HEADERS_OPTIONS = 'postman_message_headers_options';
		const MESSAGE_HEADERS_SECTION = 'postman_message_headers_section';
		const NETWORK_OPTIONS = 'postman_network_options';
		const NETWORK_SECTION = 'postman_network_section';
		const LOGGING_OPTIONS = 'postman_logging_options';
		const LOGGING_SECTION = 'postman_logging_section';
		const MULTISITE_OPTIONS = 'postman_multisite_options';
		const MULTISITE_SECTION = 'postman_multisite_section';
		const ADVANCED_OPTIONS = 'postman_advanced_options';
		const ADVANCED_SECTION = 'postman_advanced_section';
		const EMAIL_VALIDATION_SECTION = 'postman_email_validation_section';
		const EMAIL_VALIDATION_OPTIONS = 'postman_email_validation_options';
		
		// slugs
		const POSTMAN_TEST_SLUG = 'postman-test';
		
		// logging
		private $logger;
		
		// Holds the values to be used in the fields callbacks
		private $rootPluginFilenameAndPath;
		private $options;
		private $authorizationToken;
		private $importableConfiguration;
		
		// helpers
		private $messageHandler;
		private $oauthScribe;
		private $wpMailBinder;
		
		/**
		 * Constructor
		 *
		 * @param unknown $rootPluginFilenameAndPath        	
		 * @param PostmanOptions $options        	
		 * @param PostmanOAuthToken $authorizationToken        	
		 * @param PostmanMessageHandler $messageHandler        	
		 * @param PostmanWpMailBinder $binder        	
		 */
		public function __construct($rootPluginFilenameAndPath, PostmanOptions $options, PostmanOAuthToken $authorizationToken, PostmanMessageHandler $messageHandler, PostmanWpMailBinder $binder) {
			assert ( ! empty ( $rootPluginFilenameAndPath ) );
			assert ( ! empty ( $options ) );
			assert ( ! empty ( $authorizationToken ) );
			assert ( ! empty ( $messageHandler ) );
			assert ( ! empty ( $binder ) );
			assert ( PostmanUtils::isAdmin () );
			assert ( is_admin () );
			
			$this->logger = new PostmanLogger ( get_class ( $this ) );
			$this->options = $options;
			$this->authorizationToken = $authorizationToken;
			$this->messageHandler = $messageHandler;
			$this->rootPluginFilenameAndPath = $rootPluginFilenameAndPath;
			$this->wpMailBinder = $binder;
			
			// check if the user saved data, and if validation was successful
			$session = PostmanSession::getInstance ();
			if ($session->isSetAction ()) {
				$this->logger->debug ( sprintf ( 'session action: %s', $session->getAction () ) );
			}
			if ($session->getAction () == PostmanInputSanitizer::VALIDATION_SUCCESS) {
				// unset the action
				$session->unsetAction ();
				// do a redirect on the init hook
				$this->registerInitFunction ( 'handleSuccessfulSave' );
				// add a saved message to be shown after the redirect
				$this->messageHandler->addMessage ( _x ( 'Settings saved.', 'The plugin successfully saved new settings.', 'postman-smtp' ) );
				return;
			} else {
				// unset the action in the failed case as well
				$session->unsetAction ();
			}
			
			// test to see if an OAuth authentication is in progress
			if ($session->isSetOauthInProgress ()) {
				// there is only a three minute window that Postman will expect a Grant Code, once Grant is clicked by the user
				$this->logger->debug ( 'Looking for grant code' );
				if (isset ( $_GET ['code'] )) {
					$this->logger->debug ( 'Found authorization grant code' );
					// queue the function that processes the incoming grant code
					$this->registerInitFunction ( 'handleAuthorizationGrant' );
					return;
				}
			}
			
			// continue to initialize the AdminController
			add_action ( 'init', array (
					$this,
					'on_init' 
			) );
			
			// Adds "Settings" link to the plugin action page
			add_filter ( 'plugin_action_links_' . plugin_basename ( $this->rootPluginFilenameAndPath ), array (
					$this,
					'postmanModifyLinksOnPluginsListPage' 
			) );
			
			// initialize the scripts, stylesheets and form fields
			add_action ( 'admin_init', array (
					$this,
					'on_admin_init' 
			) );
		}
		
		/**
		 * Functions to execute on the init event
		 *
		 * "Typically used by plugins to initialize. The current user is already authenticated by this time."
		 * ref: http://codex.wordpress.org/Plugin_API/Action_Reference#Actions_Run_During_a_Typical_Request
		 */
		public function on_init() {
			// only administrators should be able to trigger this
			if (PostmanUtils::isAdmin ()) {
				//
				$transport = PostmanTransportRegistry::getInstance ()->getCurrentTransport ();
				$this->oauthScribe = $transport->getScribe ();
				
				// register Ajax handlers
				new PostmanManageConfigurationAjaxHandler ();
				new PostmanGetHostnameByEmailAjaxController ();
				new PostmanGetPortsToTestViaAjax ();
				new PostmanPortTestAjaxController ( $this->options );
				new PostmanImportConfigurationAjaxController ( $this->options );
				new PostmanGetDiagnosticsViaAjax ( $this->options, $this->authorizationToken );
				new PostmanSendTestEmailAjaxController ();
				
				// register content handlers
				$viewController = new PostmanViewController ( $this->rootPluginFilenameAndPath, $this->options, $this->authorizationToken, $this->oauthScribe, $this );
				
				// register action handlers
				$this->registerAdminPostAction ( self::PURGE_DATA_SLUG, 'handlePurgeDataAction' );
				$this->registerAdminPostAction ( self::IMPORT_SETTINGS_SLUG, 'importSettingsAction' );
				$this->registerAdminPostAction ( PostmanUtils::REQUEST_OAUTH2_GRANT_SLUG, 'handleOAuthPermissionRequestAction' );
				
				if (PostmanUtils::isCurrentPagePostmanAdmin ()) {
					$this->checkPreRequisites ();
				}
			}
		}
		private function checkPreRequisites() {
			$states = PostmanPreRequisitesCheck::getState ();
			foreach ( $states as $state ) {
				if (! $state ['ready']) {
					/* Translators: where %1$s is the name of the library */
					$message = sprintf ( __ ( 'This PHP installation requires the <b>%1$s</b> library.', 'postman-smtp' ), $state ['name'] );
					if ($state ['required']) {
						$this->messageHandler->addError ( $message );
					} else {
						// $this->messageHandler->addWarning ( $message );
					}
				}
			}
		}
		
		/**
		 *
		 * @param unknown $actionName        	
		 * @param unknown $callbackName        	
		 */
		private function registerInitFunction($callbackName) {
			$this->logger->debug ( 'Registering init function ' . $callbackName );
			add_action ( 'init', array (
					$this,
					$callbackName 
			) );
		}
		
		/**
		 * Registers actions posted by am HTML FORM with the WordPress 'action' parameter
		 *
		 * @param unknown $actionName        	
		 * @param unknown $callbankName        	
		 */
		private function registerAdminPostAction($actionName, $callbankName) {
			// $this->logger->debug ( 'Registering ' . $actionName . ' Action Post handler' );
			add_action ( 'admin_post_' . $actionName, array (
					$this,
					$callbankName 
			) );
		}
		
		/**
		 * Add "Settings" link to the plugin action page
		 *
		 * @param unknown $links        	
		 * @return multitype:
		 */
		public function postmanModifyLinksOnPluginsListPage($links) {
			// only administrators should be able to trigger this
			if (PostmanUtils::isAdmin ()) {
				$mylinks = array (
						sprintf ( '<a href="%s" class="postman_settings">%s</a>', PostmanUtils::getSettingsPageUrl (), _x ( 'Settings', 'The configuration page of the plugin', 'postman-smtp' ) ) 
				);
				return array_merge ( $mylinks, $links );
			}
		}
		
		/**
		 * This function runs after a successful, error-free save
		 */
		public function handleSuccessfulSave() {
			// WordPress likes to keep GET parameters around for a long time
			// (something in the call to settings_fields() does this)
			// here we redirect after a successful save to clear those parameters
			PostmanUtils::redirect ( PostmanUtils::POSTMAN_HOME_PAGE_RELATIVE_URL );
		}
		
		/**
		 * This function handle the request to import plugin data
		 */
		public function importSettingsAction() {
			$this->logger->debug ( 'is wpnonce import-settings?' );
			if (wp_verify_nonce ( $_REQUEST ['_wpnonce'], PostmanAdminController::IMPORT_SETTINGS_SLUG )) {
				$this->logger->debug ( 'Importing Settings' );
				$base64 = $_POST ['settings'];
				$this->logger->trace ( $base64 );
				$gz = base64_decode ( $base64 );
				$this->logger->trace ( $gz );
				$json = @gzuncompress ( $gz );
				$this->logger->trace ( $json );
				if (! empty ( $json )) {
					$data = json_decode ( $json, true );
					$this->logger->trace ( $data );
					{
						// overwrite the current version with the version from the imported options
						// this way database upgrading can occur
						$postmanState = get_option ( 'postman_state' );
						$postmanState ['version'] = $data ['version'];
						$this->logger->trace ( sprintf ( 'Setting Postman version to %s', $postmanState ['version'] ) );
						assert ( $postmanState ['version'] == $data ['version'] );
						update_option ( 'postman_state', $postmanState );
					}
					PostmanOptions::getInstance ()->options = $data;
					PostmanOptions::getInstance ()->save ();
				} else {
					$this->messageHandler->addError ( __ ( 'There was an error importing the data.', 'postman-smtp' ) );
					$this->logger->error ( 'There was an error importing the data' );
				}
				PostmanUtils::redirect ( PostmanUtils::POSTMAN_HOME_PAGE_RELATIVE_URL );
			}
		}
		/**
		 * This function handle the request to purge plugin data
		 */
		public function handlePurgeDataAction() {
			$this->logger->debug ( 'is wpnonce purge-data?' );
			if (wp_verify_nonce ( $_REQUEST ['_wpnonce'], PostmanAdminController::PURGE_DATA_SLUG )) {
				$this->logger->debug ( 'Purging stored data' );
				delete_option ( PostmanOptions::POSTMAN_OPTIONS );
				delete_option ( PostmanOAuthToken::OPTIONS_NAME );
				delete_option ( PostmanAdminController::TEST_OPTIONS );
				$logPurger = new PostmanEmailLogPurger ();
				$logPurger->removeAll ();
				$this->messageHandler->addMessage ( __ ( 'Plugin data was removed.', 'postman-smtp' ) );
				PostmanUtils::redirect ( PostmanUtils::POSTMAN_HOME_PAGE_RELATIVE_URL );
			}
		}
		
		/**
		 * Handles the authorization grant
		 */
		function handleAuthorizationGrant() {
			$logger = $this->logger;
			$options = $this->options;
			$authorizationToken = $this->authorizationToken;
			$logger->debug ( 'Authorization in progress' );
			$transactionId = PostmanSession::getInstance ()->getOauthInProgress ();
			
			// begin transaction
			PostmanUtils::lock ();
			
			$authenticationManager = PostmanAuthenticationManagerFactory::getInstance ()->createAuthenticationManager ();
			try {
				if ($authenticationManager->processAuthorizationGrantCode ( $transactionId )) {
					$logger->debug ( 'Authorization successful' );
					// save to database
					$authorizationToken->save ();
					$this->messageHandler->addMessage ( __ ( 'The OAuth 2.0 authorization was successful. Ready to send e-mail.', 'postman-smtp' ) );
				} else {
					$this->messageHandler->addError ( __ ( 'Your email provider did not grant Postman permission. Try again.', 'postman-smtp' ) );
				}
			} catch ( PostmanStateIdMissingException $e ) {
				$this->messageHandler->addError ( __ ( 'The grant code from Google had no accompanying state and may be a forgery', 'postman-smtp' ) );
			} catch ( Exception $e ) {
				$logger->error ( 'Error: ' . get_class ( $e ) . ' code=' . $e->getCode () . ' message=' . $e->getMessage () );
				/* translators: %s is the error message */
				$this->messageHandler->addError ( sprintf ( __ ( 'Error authenticating with this Client ID. [%s]', 'postman-smtp' ), '<em>' . $e->getMessage () . '</em>' ) );
			}
			
			// clean-up
			PostmanUtils::unlock ();
			PostmanSession::getInstance ()->unsetOauthInProgress ();
			
			// redirect home
			PostmanUtils::redirect ( PostmanUtils::POSTMAN_HOME_PAGE_RELATIVE_URL );
		}
		
		/**
		 * This method is called when a user clicks on a "Request Permission from Google" link.
		 * This link will create a remote API call for Google and redirect the user from WordPress to Google.
		 * Google will redirect back to WordPress after the user responds.
		 */
		public function handleOAuthPermissionRequestAction() {
			$this->logger->debug ( 'handling OAuth Permission request' );
			$authenticationManager = PostmanAuthenticationManagerFactory::getInstance ()->createAuthenticationManager ();
			$transactionId = $authenticationManager->generateRequestTransactionId ();
			PostmanSession::getInstance ()->setOauthInProgress ( $transactionId );
			$authenticationManager->requestVerificationCode ( $transactionId );
		}
		
		/**
		 * Register and add settings
		 */
		public function on_admin_init() {
			
			// only administrators should be able to trigger this
			if (PostmanUtils::isAdmin ()) {
				//
				$sanitizer = new PostmanInputSanitizer ( $this->options );
				register_setting ( PostmanAdminController::SETTINGS_GROUP_NAME, PostmanOptions::POSTMAN_OPTIONS, array (
						$sanitizer,
						'sanitize' 
				) );
				
				// Sanitize
				add_settings_section ( 'transport_section', _x ( 'Transport', 'Transport is the method for sending mail, (e.g. SMTP or Gmail API)', 'postman-smtp' ), array (
						$this,
						'printTransportSectionInfo' 
				), 'transport_options' );
				
				add_settings_field ( PostmanOptions::TRANSPORT_TYPE, _x ( 'Type', 'The Transport method menu', 'postman-smtp' ), array (
						$this,
						'transport_type_callback' 
				), 'transport_options', 'transport_section' );
				
				// the Message From section
				add_settings_section ( PostmanAdminController::MESSAGE_FROM_SECTION, _x ( 'From Address', 'The Message Sender Email Address', 'postman-smtp' ), array (
						$this,
						'printMessageFromSectionInfo' 
				), PostmanAdminController::MESSAGE_FROM_OPTIONS );
				
				add_settings_field ( PostmanOptions::MESSAGE_SENDER_EMAIL, __ ( 'Email Address', 'postman-smtp' ), array (
						$this,
						'from_email_callback' 
				), PostmanAdminController::MESSAGE_FROM_OPTIONS, PostmanAdminController::MESSAGE_FROM_SECTION );
				
				add_settings_field ( PostmanOptions::PREVENT_MESSAGE_SENDER_EMAIL_OVERRIDE, '', array (
						$this,
						'prevent_from_email_override_callback' 
				), PostmanAdminController::MESSAGE_FROM_OPTIONS, PostmanAdminController::MESSAGE_FROM_SECTION );
				
				add_settings_field ( PostmanOptions::MESSAGE_SENDER_NAME, __ ( 'Name', 'postman-smtp' ), array (
						$this,
						'sender_name_callback' 
				), PostmanAdminController::MESSAGE_FROM_OPTIONS, PostmanAdminController::MESSAGE_FROM_SECTION );
				
				add_settings_field ( PostmanOptions::PREVENT_MESSAGE_SENDER_NAME_OVERRIDE, '', array (
						$this,
						'prevent_from_name_override_callback' 
				), PostmanAdminController::MESSAGE_FROM_OPTIONS, PostmanAdminController::MESSAGE_FROM_SECTION );
				
				// the Additional Addresses section
				add_settings_section ( PostmanAdminController::MESSAGE_SECTION, __ ( 'Additional Email Addresses', 'postman-smtp' ), array (
						$this,
						'printMessageSectionInfo' 
				), PostmanAdminController::MESSAGE_OPTIONS );
				
				add_settings_field ( PostmanOptions::REPLY_TO, _x ( 'Reply-To', 'The return email address', 'postman-smtp' ), array (
						$this,
						'reply_to_callback' 
				), PostmanAdminController::MESSAGE_OPTIONS, PostmanAdminController::MESSAGE_SECTION );
				
				add_settings_field ( PostmanOptions::FORCED_TO_RECIPIENTS, __ ( 'To Recipient(s)', 'postman-smtp' ), array (
						$this,
						'to_callback' 
				), PostmanAdminController::MESSAGE_OPTIONS, PostmanAdminController::MESSAGE_SECTION );
				
				add_settings_field ( PostmanOptions::FORCED_CC_RECIPIENTS, __ ( 'Carbon Copy Recipient(s)', 'postman-smtp' ), array (
						$this,
						'cc_callback' 
				), PostmanAdminController::MESSAGE_OPTIONS, PostmanAdminController::MESSAGE_SECTION );
				
				add_settings_field ( PostmanOptions::FORCED_BCC_RECIPIENTS, __ ( 'Blind Carbon Copy Recipient(s)', 'postman-smtp' ), array (
						$this,
						'bcc_callback' 
				), PostmanAdminController::MESSAGE_OPTIONS, PostmanAdminController::MESSAGE_SECTION );
				
				// the Additional Headers section
				add_settings_section ( PostmanAdminController::MESSAGE_HEADERS_SECTION, __ ( 'Additional Headers', 'postman-smtp' ), array (
						$this,
						'printAdditionalHeadersSectionInfo' 
				), PostmanAdminController::MESSAGE_HEADERS_OPTIONS );
				
				add_settings_field ( PostmanOptions::ADDITIONAL_HEADERS, __ ( 'Custom Headers', 'postman-smtp' ), array (
						$this,
						'headers_callback' 
				), PostmanAdminController::MESSAGE_HEADERS_OPTIONS, PostmanAdminController::MESSAGE_HEADERS_SECTION );
				
				// the Email Validation section
				add_settings_section ( PostmanAdminController::EMAIL_VALIDATION_SECTION, __ ( 'Validation', 'postman-smtp' ), array (
						$this,
						'printEmailValidationSectionInfo' 
				), PostmanAdminController::EMAIL_VALIDATION_OPTIONS );
				
				add_settings_field ( PostmanOptions::ENVELOPE_SENDER, __ ( 'Email Address', 'postman-smtp' ), array (
						$this,
						'disable_email_validation_callback' 
				), PostmanAdminController::EMAIL_VALIDATION_OPTIONS, PostmanAdminController::EMAIL_VALIDATION_SECTION );
				
				// the Logging section
				add_settings_section ( PostmanAdminController::LOGGING_SECTION, __ ( 'Email Log Settings', 'postman-smtp' ), array (
						$this,
						'printLoggingSectionInfo' 
				), PostmanAdminController::LOGGING_OPTIONS );
				
				add_settings_field ( 'logging_status', __ ( 'Enable Logging', 'postman-smtp' ), array (
						$this,
						'loggingStatusInputField' 
				), PostmanAdminController::LOGGING_OPTIONS, PostmanAdminController::LOGGING_SECTION );
				
				add_settings_field ( 'logging_max_entries', __ ( 'Maximum Log Entries', 'Configuration Input Field', 'postman-smtp' ), array (
						$this,
						'loggingMaxEntriesInputField' 
				), PostmanAdminController::LOGGING_OPTIONS, PostmanAdminController::LOGGING_SECTION );
				
				add_settings_field ( PostmanOptions::TRANSCRIPT_SIZE, __ ( 'Maximum Transcript Size', 'postman-smtp' ), array (
						$this,
						'transcriptSizeInputField' 
				), PostmanAdminController::LOGGING_OPTIONS, PostmanAdminController::LOGGING_SECTION );
				
				// the Network section
				add_settings_section ( PostmanAdminController::NETWORK_SECTION, __ ( 'Network Settings', 'postman-smtp' ), array (
						$this,
						'printNetworkSectionInfo' 
				), PostmanAdminController::NETWORK_OPTIONS );
				
				add_settings_field ( 'connection_timeout', _x ( 'TCP Connection Timeout (sec)', 'Configuration Input Field', 'postman-smtp' ), array (
						$this,
						'connection_timeout_callback' 
				), PostmanAdminController::NETWORK_OPTIONS, PostmanAdminController::NETWORK_SECTION );
				
				add_settings_field ( 'read_timeout', _x ( 'TCP Read Timeout (sec)', 'Configuration Input Field', 'postman-smtp' ), array (
						$this,
						'read_timeout_callback' 
				), PostmanAdminController::NETWORK_OPTIONS, PostmanAdminController::NETWORK_SECTION );
				
				// the Advanced section
				add_settings_section ( PostmanAdminController::ADVANCED_SECTION, _x ( 'Miscellaneous Settings', 'Configuration Section Title', 'postman-smtp' ), array (
						$this,
						'printAdvancedSectionInfo' 
				), PostmanAdminController::ADVANCED_OPTIONS );
				
				add_settings_field ( PostmanOptions::LOG_LEVEL, _x ( 'PHP Log Level', 'Configuration Input Field', 'postman-smtp' ), array (
						$this,
						'log_level_callback' 
				), PostmanAdminController::ADVANCED_OPTIONS, PostmanAdminController::ADVANCED_SECTION );
				
				add_settings_field ( PostmanOptions::RUN_MODE, _x ( 'Delivery Mode', 'Configuration Input Field', 'postman-smtp' ), array (
						$this,
						'runModeCallback' 
				), PostmanAdminController::ADVANCED_OPTIONS, PostmanAdminController::ADVANCED_SECTION );
				
				add_settings_field ( PostmanOptions::STEALTH_MODE, _x ( 'Stealth Mode', 'This mode removes the Postman X-Mailer signature from emails', 'postman-smtp' ), array (
						$this,
						'stealthModeCallback' 
				), PostmanAdminController::ADVANCED_OPTIONS, PostmanAdminController::ADVANCED_SECTION );
				
				add_settings_field ( PostmanOptions::TEMPORARY_DIRECTORY, __ ( 'Temporary Directory', 'postman-smtp' ), array (
						$this,
						'temporaryDirectoryCallback' 
				), PostmanAdminController::ADVANCED_OPTIONS, PostmanAdminController::ADVANCED_SECTION );
				
				// the Test Email section
				register_setting ( 'email_group', PostmanAdminController::TEST_OPTIONS );
				
				add_settings_section ( 'TEST_EMAIL', _x ( 'Test Your Setup', 'Configuration Section Title', 'postman-smtp' ), array (
						$this,
						'printTestEmailSectionInfo' 
				), PostmanAdminController::POSTMAN_TEST_SLUG );
				
				add_settings_field ( 'test_email', _x ( 'Recipient Email Address', 'Configuration Input Field', 'postman-smtp' ), array (
						$this,
						'test_email_callback' 
				), PostmanAdminController::POSTMAN_TEST_SLUG, 'TEST_EMAIL' );
			}
		}
		
		/**
		 * Print the Transport section info
		 */
		public function printTransportSectionInfo() {
			print __ ( 'Choose SMTP or a vendor-specific API:', 'postman-smtp' );
		}
		
		/**
		 * Print the Port Test text
		 */
		public function printPortTestSectionInfo() {
		}
		public function printLoggingSectionInfo() {
			print __ ( 'Configure the delivery audit log:', 'postman-smtp' );
		}
		
		/**
		 * Print the Section text
		 *
		 * @deprecated
		 *
		 */
		public function printTestEmailSectionInfo() {
			// no-op
		}
		
		/**
		 * Print the Section text
		 */
		public function printMessageFromSectionInfo() {
			print sprintf ( __ ( 'This address, like the <b>letterhead</b> printed on a letter, identifies the sender to the recipient. Change this when you are sending on behalf of someone else, for example to use Google\'s <a href="%s">Send Mail As</a> feature. Other plugins, especially Contact Forms, may override this field to be your visitor\'s address.', 'postman-smtp' ), 'https://support.google.com/mail/answer/22370?hl=en' );
		}
		
		/**
		 * Print the Section text
		 */
		public function printMessageSectionInfo() {
			print __ ( 'Separate multiple <b>to</b>/<b>cc</b>/<b>bcc</b> recipients with commas.', 'postman-smtp' );
		}
		
		/**
		 * Print the Section text
		 */
		public function printNetworkSectionInfo() {
			print __ ( 'Increase the timeouts if your host is intermittenly failing to send mail. Be careful, this also correlates to how long your user must wait if the mail server is unreachable.', 'postman-smtp' );
		}
		/**
		 * Print the Section text
		 */
		public function printAdvancedSectionInfo() {
		}
		/**
		 * Print the Section text
		 */
		public function printAdditionalHeadersSectionInfo() {
			print __ ( 'Specify custom headers (e.g. <code>X-MC-Tags: wordpress-site-A</code>), one per line. Use custom headers with caution as they can negatively affect your Spam score.', 'postman-smtp' );
		}
		
		/**
		 * Print the Email Validation Description
		 */
		public function printEmailValidationSectionInfo() {
			print __ ( 'Postman can validate e-mail addresses before sending e-mail, however it may have trouble with some newer domains.', 'postman-smtp' );
		}
		
		/**
		 * Get the settings option array and print one of its values
		 */
		public function transport_type_callback() {
			$transportType = $this->options->getTransportType ();
			printf ( '<select id="input_%2$s" class="input_%2$s" name="%1$s[%2$s]">', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::TRANSPORT_TYPE );
			foreach ( PostmanTransportRegistry::getInstance ()->getTransports () as $transport ) {
				printf ( '<option class="input_tx_type_%1$s" value="%1$s" %3$s>%2$s</option>', $transport->getSlug (), $transport->getName (), $transportType == $transport->getSlug () ? 'selected="selected"' : '' );
			}
			print '</select>';
		}
		
		/**
		 * Get the settings option array and print one of its values
		 */
		public function port_test_hostname_callback() {
			$hostname = PostmanTransportRegistry::getInstance ()->getSelectedTransport ()->getHostname ();
			if (empty ( $hostname )) {
				$hostname = PostmanTransportRegistry::getInstance ()->getActiveTransport ()->getHostname ();
			}
			printf ( '<input type="text" id="input_hostname" name="postman_options[hostname]" value="%s" size="40" class="required"/>', $hostname );
		}
		
		/**
		 * Get the settings option array and print one of its values
		 */
		public function sender_name_callback() {
			printf ( '<input type="text" id="input_sender_name" name="postman_options[sender_name]" value="%s" size="40" />', null !== $this->options->getMessageSenderName () ? esc_attr ( $this->options->getMessageSenderName () ) : '' );
		}
		
		/**
		 */
		public function prevent_from_name_override_callback() {
			$enforced = $this->options->isPluginSenderNameEnforced ();
			printf ( '<input type="checkbox" id="input_prevent_sender_name_override" name="postman_options[prevent_sender_name_override]" %s /> %s', $enforced ? 'checked="checked"' : '', __ ( 'Prevent <b>plugins</b> and <b>themes</b> from changing this', 'postman-smtp' ) );
		}
		
		/**
		 * Get the settings option array and print one of its values
		 */
		public function from_email_callback() {
			printf ( '<input type="email" id="input_sender_email" name="postman_options[sender_email]" value="%s" size="40" class="required" placeholder="%s"/>', null !== $this->options->getMessageSenderEmail () ? esc_attr ( $this->options->getMessageSenderEmail () ) : '', __ ( 'Required', 'postman-smtp' ) );
		}
		
		/**
		 * Print the Section text
		 */
		public function printMessageSenderSectionInfo() {
			print sprintf ( __ ( 'This address, like the <b>return address</b> printed on an envelope, identifies the account owner to the SMTP server.', 'postman-smtp' ), 'https://support.google.com/mail/answer/22370?hl=en' );
		}
		
		/**
		 * Get the settings option array and print one of its values
		 */
		public function prevent_from_email_override_callback() {
			$enforced = $this->options->isPluginSenderEmailEnforced ();
			printf ( '<input type="checkbox" id="input_prevent_sender_email_override" name="postman_options[prevent_sender_email_override]" %s /> %s', $enforced ? 'checked="checked"' : '', __ ( 'Prevent <b>plugins</b> and <b>themes</b> from changing this', 'postman-smtp' ) );
		}
		
		/**
		 * Shows the Mail Logging enable/disabled option
		 */
		public function loggingStatusInputField() {
			// isMailLoggingAllowed
			$disabled = "";
			if (! $this->options->isMailLoggingAllowed ()) {
				$disabled = 'disabled="disabled" ';
			}
			printf ( '<select ' . $disabled . 'id="input_%2$s" class="input_%2$s" name="%1$s[%2$s]">', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::MAIL_LOG_ENABLED_OPTION );
			printf ( '<option value="%s" %s>%s</option>', PostmanOptions::MAIL_LOG_ENABLED_OPTION_YES, $this->options->isMailLoggingEnabled () ? 'selected="selected"' : '', __ ( 'Yes', 'postman-smtp' ) );
			printf ( '<option value="%s" %s>%s</option>', PostmanOptions::MAIL_LOG_ENABLED_OPTION_NO, ! $this->options->isMailLoggingEnabled () ? 'selected="selected"' : '', __ ( 'No', 'postman-smtp' ) );
			printf ( '</select>' );
		}
		public function loggingMaxEntriesInputField() {
			printf ( '<input type="text" id="input_logging_max_entries" name="postman_options[%s]" value="%s"/>', PostmanOptions::MAIL_LOG_MAX_ENTRIES, $this->options->getMailLoggingMaxEntries () );
		}
		public function transcriptSizeInputField() {
			$inputOptionsSlug = PostmanOptions::POSTMAN_OPTIONS;
			$inputTranscriptSlug = PostmanOptions::TRANSCRIPT_SIZE;
			$inputValue = $this->options->getTranscriptSize ();
			$inputDescription = __ ( 'Change this value if you can\'t see the beginning of the transcript because your messages are too big.', 'postman-smtp' );
			printf ( '<input type="text" id="input%2$s" name="%1$s[%2$s]" value="%3$s"/><br/><span class="postman_input_description">%4$s</span>', $inputOptionsSlug, $inputTranscriptSlug, $inputValue, $inputDescription );
		}
		
		/**
		 * Get the settings option array and print one of its values
		 */
		public function reply_to_callback() {
			printf ( '<input type="text" id="input_reply_to" name="%s[%s]" value="%s" size="40" />', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::REPLY_TO, null !== $this->options->getReplyTo () ? esc_attr ( $this->options->getReplyTo () ) : '' );
		}
		
		/**
		 * Get the settings option array and print one of its values
		 */
		public function to_callback() {
			printf ( '<input type="text" id="input_to" name="%s[%s]" value="%s" size="60" />', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::FORCED_TO_RECIPIENTS, null !== $this->options->getForcedToRecipients () ? esc_attr ( $this->options->getForcedToRecipients () ) : '' );
		}
		
		/**
		 * Get the settings option array and print one of its values
		 */
		public function cc_callback() {
			printf ( '<input type="text" id="input_cc" name="%s[%s]" value="%s" size="60" />', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::FORCED_CC_RECIPIENTS, null !== $this->options->getForcedCcRecipients () ? esc_attr ( $this->options->getForcedCcRecipients () ) : '' );
		}
		
		/**
		 * Get the settings option array and print one of its values
		 */
		public function bcc_callback() {
			printf ( '<input type="text" id="input_bcc" name="%s[%s]" value="%s" size="60" />', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::FORCED_BCC_RECIPIENTS, null !== $this->options->getForcedBccRecipients () ? esc_attr ( $this->options->getForcedBccRecipients () ) : '' );
		}
		
		/**
		 * Get the settings option array and print one of its values
		 */
		public function headers_callback() {
			printf ( '<textarea id="input_headers" name="%s[%s]" cols="60" rows="5" >%s</textarea>', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::ADDITIONAL_HEADERS, null !== $this->options->getAdditionalHeaders () ? esc_attr ( $this->options->getAdditionalHeaders () ) : '' );
		}
		
		/**
		 */
		public function disable_email_validation_callback() {
			$disabled = $this->options->isEmailValidationDisabled ();
			printf ( '<input type="checkbox" id="%2$s" name="%1$s[%2$s]" %3$s /> %4$s', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::DISABLE_EMAIL_VALIDAITON, $disabled ? 'checked="checked"' : '', __ ( 'Disable e-mail validation', 'postman-smtp' ) );
		}
		
		/**
		 * Get the settings option array and print one of its values
		 */
		public function log_level_callback() {
			$inputDescription = sprintf ( __ ( 'Log Level specifies the level of detail written to the <a target="_new" href="%s">WordPress Debug log</a> - view the log with <a target-"_new" href="%s">Debug</a>.', 'postman-smtp' ), 'https://codex.wordpress.org/Debugging_in_WordPress', 'https://wordpress.org/plugins/debug/' );
			printf ( '<select id="input_%2$s" class="input_%2$s" name="%1$s[%2$s]">', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::LOG_LEVEL );
			$currentKey = $this->options->getLogLevel ();
			$this->printSelectOption ( _x ( 'Off', 'Log Level', 'postman-smtp' ), PostmanLogger::OFF_INT, $currentKey );
			$this->printSelectOption ( _x ( 'Trace', 'Log Level', 'postman-smtp' ), PostmanLogger::TRACE_INT, $currentKey );
			$this->printSelectOption ( _x ( 'Debug', 'Log Level', 'postman-smtp' ), PostmanLogger::DEBUG_INT, $currentKey );
			$this->printSelectOption ( _x ( 'Info', 'Log Level', 'postman-smtp' ), PostmanLogger::INFO_INT, $currentKey );
			$this->printSelectOption ( _x ( 'Warning', 'Log Level', 'postman-smtp' ), PostmanLogger::WARN_INT, $currentKey );
			$this->printSelectOption ( _x ( 'Error', 'Log Level', 'postman-smtp' ), PostmanLogger::ERROR_INT, $currentKey );
			printf ( '</select><br/><span class="postman_input_description">%s</span>', $inputDescription );
		}
		private function printSelectOption($label, $optionKey, $currentKey) {
			$optionPattern = '<option value="%1$s" %2$s>%3$s</option>';
			printf ( $optionPattern, $optionKey, $optionKey == $currentKey ? 'selected="selected"' : '', $label );
		}
		public function runModeCallback() {
			$inputDescription = __ ( 'Delivery mode offers options useful for developing or testing.', 'postman-smtp' );
			printf ( '<select id="input_%2$s" class="input_%2$s" name="%1$s[%2$s]">', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::RUN_MODE );
			$currentKey = $this->options->getRunMode ();
			$this->printSelectOption ( _x ( 'Log Email and Send', 'When the server is online to the public, this is "Production" mode', 'postman-smtp' ), PostmanOptions::RUN_MODE_PRODUCTION, $currentKey );
			$this->printSelectOption ( __ ( 'Log Email and Delete', 'postman-smtp' ), PostmanOptions::RUN_MODE_LOG_ONLY, $currentKey );
			$this->printSelectOption ( __ ( 'Delete All Emails', 'postman-smtp' ), PostmanOptions::RUN_MODE_IGNORE, $currentKey );
			printf ( '</select><br/><span class="postman_input_description">%s</span>', $inputDescription );
		}
		public function stealthModeCallback() {
			printf ( '<input type="checkbox" id="input_%2$s" class="input_%2$s" name="%1$s[%2$s]" %3$s /> %4$s', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::STEALTH_MODE, $this->options->isStealthModeEnabled () ? 'checked="checked"' : '', __ ( 'Remove the Postman X-Header signature from messages', 'postman-smtp' ) );
		}
		public function temporaryDirectoryCallback() {
			$inputDescription = __ ( 'Lockfiles are written here to prevent users from triggering an OAuth 2.0 token refresh at the same time.' );
			printf ( '<input type="text" id="input_%2$s" name="%1$s[%2$s]" value="%3$s" />', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::TEMPORARY_DIRECTORY, $this->options->getTempDirectory () );
			if (PostmanState::getInstance ()->isFileLockingEnabled ()) {
				printf ( ' <span style="color:green">Valid</span></br><span class="postman_input_description">%s</span>', $inputDescription );
			} else {
				printf ( ' <span style="color:red">Invalid</span></br><span class="postman_input_description">%s</span>', $inputDescription );
			}
		}
		
		/**
		 * Get the settings option array and print one of its values
		 */
		public function connection_timeout_callback() {
			printf ( '<input type="text" id="input_connection_timeout" name="%s[%s]" value="%s" />', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::CONNECTION_TIMEOUT, $this->options->getConnectionTimeout () );
		}
		
		/**
		 * Get the settings option array and print one of its values
		 */
		public function read_timeout_callback() {
			printf ( '<input type="text" id="input_read_timeout" name="%s[%s]" value="%s" />', PostmanOptions::POSTMAN_OPTIONS, PostmanOptions::READ_TIMEOUT, $this->options->getReadTimeout () );
		}
		
		/**
		 * Get the settings option array and print one of its values
		 */
		public function test_email_callback() {
			printf ( '<input type="text" id="input_test_email" name="postman_test_options[test_email]" value="%s" class="required email" size="40"/>', wp_get_current_user ()->user_email );
		}
		
		/**
		 * Get the settings option array and print one of its values
		 */
		public function port_callback($args) {
			printf ( '<input type="text" id="input_port" name="postman_options[port]" value="%s" %s placeholder="%s"/>', null !== $this->options->getPort () ? esc_attr ( $this->options->getPort () ) : '', isset ( $args ['style'] ) ? $args ['style'] : '', __ ( 'Required', 'postman-smtp' ) );
		}
	}
}