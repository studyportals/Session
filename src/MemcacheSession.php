<?php

/**
 * @file Session/Engines/Memcached.php
 *
 * @author Thijs Putman <thijs@studyportals.eu>
 * @copyright © 2009 Thijs Putman, all rights reserved.
 * @copyright © 2010-2014 StudyPortals B.V., all rights reserved.
 * @version 1.2.0
 */

namespace StudyPortals\Session;

use StudyPortals\Cache\Memcache;

/**
 * Memcache (PECL/Memcache) based session-storage.
 *
 * @package StudyPortals.Framework
 * @subpackage Session
 */

class MemcacheSession extends SessionEngine{

	protected $_Memcache;

	protected $_session_ttl;

	/**
	 * Create or resume a Memcache Session.
	 *
	 * @param string $host
	 * @param integer $port
	 * @param string $session_name
	 * @param integer $session_ttl
	 * @param boolean $implicit_save
	 * @throws SessionException
	 */

	public function __construct(
		$host, $port, $session_name, $session_ttl = 3600, $implicit_save = true){

		$this->_Memcache = new \Memcache();

		if(!Memcache::connect($this->_Memcache, $host, $port)){

			throw new SessionException('Failed to connect to Memcache');
		}

		$this->_session_ttl = (int) $session_ttl;

		// Start or resume Session

		parent::__construct($session_name, $session_ttl, $implicit_save);
	}

	/**
	 * Create a new session-state.
	 *
	 * @param string $session_id
	 * @return void
	 * @throws SessionException
	 * @see SessionEngine::_createSession()
	 */

	protected function _createSessionState($session_id){

		$payload =	[
			'ip'	=> $this->_ip,
			'state'	=> []
		];

		$result = $this->_Memcache->add(
			$session_id, $payload, 0, $this->_session_ttl);

		if(!$result){

			throw new SessionException("Error while creating session-state
				with SID \"$session_id\"");
		}
	}

	/**
	 * Load a session-state from the Memcache instance.
	 *
	 * @param string $session_id
	 * @param integer $timestamp
	 * @param integer $ip
	 * @return array
	 * @throws SessionException
	 * @see SessionEngine::_loadSession()
	 */

	protected function _loadSessionState(
		$session_id, &$timestamp = null, &$ip = null){

		$session = $this->_Memcache->get($session_id);

		if($session === false){

			throw new SessionException("No session found in memcache for
				ID \"$session_id\"");
		}
		else{

			$timestamp = time();
			$ip = $session['ip'];

			return $session['state'];
		}
	}

	/**
	 * Touch the session-state to prevent it from expiring.
	 *
	 * <p>This method is a <strong>stub</strong> in the Memcache implementation.
	 * It is not possible to "touch" a memcache-entry natively. Furthermore, it
	 * is not feasible to either re-read the information from the memcache-store
	 * (this destroys the current session-state), or keep a clean copy
	 * specifically for touching the session.</p>
	 *
	 * @param string $session_id
	 * @return void
	 * @see SessionEngine::_touchSessionState()
	 */

	protected function _touchSessionState($session_id){}

	/**
	 * Store the session-state to Memcache-instance.
	 *
	 * <p>Due to the way the Memcache extension handles serialising, it is
	 * currently impossible to (easily) retrieve the size of the session
	 * payload. As such, this method will always return -1.</p>
	 *
	 * @param string $session_id
	 * @param array $session_state
	 * @return integer
	 * @throws SessionException
	 * @see SessionEngine::_storeSessionState()
	 */

	protected function _storeSessionState($session_id, array $session_state){

		$payload = [
			'ip'	=> $this->_ip,
			'state'	=> $session_state
		];

		/** @noinspection PhpVoidFunctionResultUsedInspection */
		$result = $this->_Memcache->replace(
			$session_id, $payload, 0, $this->_session_ttl);

		if(!$result){

			throw new SessionException("Error while storing session-state
				for session with SID \"$session_id\"");
		}

		return -1;
	}

	/**
	 * Delete the session-state from Memcache-instance.
	 *
	 * @param string $session_id
	 * @return void
	 * @see SessionEngine::_deleteSessionState()
	 */

	protected function _deleteSessionState($session_id){

		$this->_Memcache->delete($session_id);
	}
}