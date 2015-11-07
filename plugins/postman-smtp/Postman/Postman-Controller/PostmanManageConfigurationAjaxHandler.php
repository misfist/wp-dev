<?php
class PostmanWizardSocket {
	
	// these variables are populated by the Port Test
	public $hostname;
	public $hostnameDomainOnly;
	public $port;
	public $protocol;
	public $secure;
	public $mitm;
	public $reportedHostname;
	public $reportedHostnameDomainOnly;
	public $message;
	public $startTls;
	public $authPlain;
	public $auth_login;
	public $auth_crammd5;
	public $auth_xoauth;
	public $auth_none;
	public $try_smtps;
	public $success;
	public $transport;
	
	// these variables are populated by The Transport Recommenders
	public $label;
	public $id;
	
	/**
	 *
	 * @param unknown $queryHostData        	
	 */
	function __construct($queryHostData) {
		$this->hostname = $queryHostData ['hostname'];
		$this->hostnameDomainOnly = $queryHostData ['hostname_domain_only'];
		$this->port = $queryHostData ['port'];
		$this->protocol = $queryHostData ['protocol'];
		$this->secure = PostmanUtils::parseBoolean ( $queryHostData ['secure'] );
		$this->mitm = PostmanUtils::parseBoolean ( $queryHostData ['mitm'] );
		$this->reportedHostname = $queryHostData ['reported_hostname'];
		$this->reportedHostnameDomainOnly = $queryHostData ['reported_hostname_domain_only'];
		$this->message = $queryHostData ['message'];
		$this->startTls = PostmanUtils::parseBoolean ( $queryHostData ['start_tls'] );
		$this->authPlain = PostmanUtils::parseBoolean ( $queryHostData ['auth_plain'] );
		$this->auth_login = PostmanUtils::parseBoolean ( $queryHostData ['auth_login'] );
		$this->auth_crammd5 = PostmanUtils::parseBoolean ( $queryHostData ['auth_crammd5'] );
		$this->auth_xoauth = PostmanUtils::parseBoolean ( $queryHostData ['auth_xoauth'] );
		$this->auth_none = PostmanUtils::parseBoolean ( $queryHostData ['auth_none'] );
		$this->try_smtps = PostmanUtils::parseBoolean ( $queryHostData ['try_smtps'] );
		$this->success = PostmanUtils::parseBoolean ( $queryHostData ['success'] );
		$this->transport = $queryHostData ['transport'];
		assert ( ! empty ( $this->transport ) );
		$this->id = sprintf ( '%s_%s', $this->hostname, $this->port );
	}
}
class PostmanManageConfigurationAjaxHandler extends PostmanAbstractAjaxHandler {
	function __construct() {
		parent::__construct ();
		$this->registerAjaxHandler ( 'manual_config', $this, 'getManualConfigurationViaAjax' );
		$this->registerAjaxHandler ( 'get_wizard_configuration_options', $this, 'getWizardConfigurationViaAjax' );
	}
	
	/**
	 * Handle a Advanced Configuration request with Ajax
	 *
	 * @throws Exception
	 */
	function getManualConfigurationViaAjax() {
		$queryTransportType = $this->getTransportTypeFromRequest ();
		$queryAuthType = $this->getAuthenticationTypeFromRequest ();
		$queryHostname = $this->getHostnameFromRequest ();
		
		// the outgoing server hostname is only required for the SMTP Transport
		// the Gmail API transport doesn't use an SMTP server
		$transport = PostmanTransportRegistry::getInstance ()->getTransport ( $queryTransportType );
		if (! $transport) {
			throw new Exception ( 'Unable to find transport ' . $queryTransportType );
		}
		
		// create the response
		$response = $transport->populateConfiguration ( $queryHostname );
		$response ['referer'] = 'manual_config';
		
		// set the display_auth to oauth2 if the transport needs it
		if ($transport->isOAuthUsed ( $queryAuthType )) {
			$response ['display_auth'] = 'oauth2';
			$this->logger->debug ( 'ajaxRedirectUrl answer display_auth:' . $response ['display_auth'] );
		}
		$this->logger->trace ( $response );
		wp_send_json_success ( $response );
	}
	
	/**
	 * Once the Port Tests have run, the results are analyzed.
	 * The Transport place bids on the sockets and highest bid becomes the recommended
	 * The UI response is built so the user may choose a different socket with different options.
	 */
	function getWizardConfigurationViaAjax() {
		$this->logger->debug ( 'in getWizardConfiguration' );
		$originalSmtpServer = $this->getRequestParameter ( 'original_smtp_server' );
		$queryHostData = $this->getHostDataFromRequest ();
		$sockets = array ();
		foreach ( $queryHostData as $id => $datum ) {
			array_push ( $sockets, new PostmanWizardSocket ( $datum ) );
		}
		$this->logger->error ( $sockets );
		$userPortOverride = $this->getUserPortOverride ();
		$userAuthOverride = $this->getUserAuthOverride ();
		
		// determine a configuration recommendation
		$winningRecommendation = $this->getWinningRecommendation ( $sockets, $userPortOverride, $userAuthOverride, $originalSmtpServer );
		if ($this->logger->isTrace ()) {
			$this->logger->trace ( 'winning recommendation:' );
			$this->logger->trace ( $winningRecommendation );
		}
		
		// create the reponse
		$response = array ();
		$configuration = array ();
		$response ['referer'] = 'wizard';
		if (isset ( $userPortOverride ) || isset ( $userAuthOverride )) {
			$configuration ['user_override'] = true;
		}
		
		if (isset ( $winningRecommendation )) {
			
			// create an appropriate (theoretical) transport
			$transport = PostmanTransportRegistry::getInstance ()->getTransport ( $winningRecommendation ['transport'] );
			
			// create user override menu
			$overrideMenu = $this->createOverrideMenus ( $sockets, $winningRecommendation, $userPortOverride, $userAuthOverride );
			if ($this->logger->isTrace ()) {
				$this->logger->trace ( 'override menu:' );
				$this->logger->trace ( $overrideMenu );
			}
			
			$queryHostName = $winningRecommendation ['hostname'];
			if ($this->logger->isDebug ()) {
				$this->logger->debug ( 'Getting scribe for ' . $queryHostName );
			}
			$generalConfig1 = $transport->populateConfiguration ( $queryHostName );
			$generalConfig2 = $transport->populateConfigurationFromRecommendation ( $winningRecommendation );
			$configuration = array_merge ( $configuration, $generalConfig1, $generalConfig2 );
			$response ['override_menu'] = $overrideMenu;
			$response ['configuration'] = $configuration;
			if ($this->logger->isTrace ()) {
				$this->logger->trace ( 'configuration:' );
				$this->logger->trace ( $configuration );
				$this->logger->trace ( 'response:' );
				$this->logger->trace ( $response );
			}
			wp_send_json_success ( $response );
		} else {
			/* translators: where %s is the URL to the Connectivity Test page */
			$configuration ['message'] = sprintf ( __ ( 'Postman can\'t find any way to send mail on your system. Run a <a href="%s">connectivity test</a>.', 'postman-smtp' ), PostmanViewController::getPageUrl ( PostmanViewController::PORT_TEST_SLUG ) );
			$response ['configuration'] = $configuration;
			if ($this->logger->isTrace ()) {
				$this->logger->trace ( 'configuration:' );
				$this->logger->trace ( $configuration );
			}
			wp_send_json_error ( $response );
		}
	}
	
	/**
	 * // for each successful host/port combination
	 * // ask a transport if they support it, and if they do at what priority is it
	 * // configure for the highest priority you find
	 *
	 * @param unknown $queryHostData        	
	 * @return unknown
	 */
	private function getWinningRecommendation($sockets, $userSocketOverride, $userAuthOverride, $originalSmtpServer) {
		foreach ( $sockets as $socket ) {
			$winningRecommendation = $this->getWin ( $socket, $userSocketOverride, $userAuthOverride, $originalSmtpServer );
			$this->logger->error ( $socket->label );
		}
		return $winningRecommendation;
	}
	
	/**
	 *
	 * @param PostmanSocket $socket        	
	 * @param unknown $userSocketOverride        	
	 * @param unknown $userAuthOverride        	
	 * @param unknown $originalSmtpServer        	
	 * @return Ambigous <NULL, unknown, string>
	 */
	private function getWin(PostmanWizardSocket $socket, $userSocketOverride, $userAuthOverride, $originalSmtpServer) {
		static $recommendationPriority = - 1;
		static $winningRecommendation = null;
		$available = $socket->success;
		if ($available) {
			$this->logger->debug ( sprintf ( 'Asking for judgement on %s:%s', $socket->hostname, $socket->port ) );
			$recommendation = PostmanTransportRegistry::getInstance ()->getRecommendation ( $socket, $userAuthOverride, $originalSmtpServer );
			$recommendationId = sprintf ( '%s_%s', $socket->hostname, $socket->port );
			$recommendation ['id'] = $recommendationId;
			$this->logger->debug ( sprintf ( 'Got a recommendation: [%d] %s', $recommendation ['priority'], $recommendationId ) );
			if (isset ( $userSocketOverride )) {
				if ($recommendationId == $userSocketOverride) {
					$winningRecommendation = $recommendation;
					$this->logger->debug ( sprintf ( 'User chosen socket %s is the winner', $recommendationId ) );
				}
			} elseif ($recommendation && $recommendation ['priority'] > $recommendationPriority) {
				$recommendationPriority = $recommendation ['priority'];
				$winningRecommendation = $recommendation;
			}
			$socket->label = $recommendation ['label'];
		}
		return $winningRecommendation;
	}
	
	/**
	 *
	 * @param unknown $queryHostData        	
	 * @return multitype:
	 */
	private function createOverrideMenus($sockets, $winningRecommendation, $userSocketOverride, $userAuthOverride) {
		$overrideMenu = array ();
		foreach ( $sockets as $socket ) {
			$overrideItem = $this->createOverrideMenu ( $socket, $winningRecommendation, $userSocketOverride, $userAuthOverride );
			if ($overrideItem != null) {
				$overrideMenu [$socket->id] = $overrideItem;
			}
		}
		
		// sort
		krsort ( $overrideMenu );
		$sortedMenu = array ();
		foreach ( $overrideMenu as $menu ) {
			array_push ( $sortedMenu, $menu );
		}
		
		return $sortedMenu;
	}
	
	/**
	 *
	 * @param PostmanWizardSocket $socket        	
	 * @param unknown $winningRecommendation        	
	 * @param unknown $userSocketOverride        	
	 * @param unknown $userAuthOverride        	
	 */
	private function createOverrideMenu(PostmanWizardSocket $socket, $winningRecommendation, $userSocketOverride, $userAuthOverride) {
		if ($socket->success) {
			$transport = PostmanTransportRegistry::getInstance ()->getTransport ( $socket->transport );
			$this->logger->debug ( sprintf ( 'Transport %s is building the override menu for socket', $transport->getSlug () ) );
			$overrideItem = $transport->createOverrideMenu ( $socket, $winningRecommendation, $userSocketOverride, $userAuthOverride );
			return $overrideItem;
		}
		return null;
	}
	
	/**
	 */
	private function getTransportTypeFromRequest() {
		return $this->getRequestParameter ( 'transport' );
	}
	
	/**
	 */
	private function getHostnameFromRequest() {
		return $this->getRequestParameter ( 'hostname' );
	}
	
	/**
	 */
	private function getAuthenticationTypeFromRequest() {
		return $this->getRequestParameter ( 'auth_type' );
	}
	
	/**
	 */
	private function getHostDataFromRequest() {
		return $this->getRequestParameter ( 'host_data' );
	}
	
	/**
	 */
	private function getUserPortOverride() {
		return $this->getRequestParameter ( 'user_port_override' );
	}
	
	/**
	 */
	private function getUserAuthOverride() {
		return $this->getRequestParameter ( 'user_auth_override' );
	}
}

