<?php

/**
 * @file Session.php
 *
 * @author Thijs Putman <thijs@studyportals.eu>
 * @copyright © 2008-2009 Thijs Putman, all rights reserved.
 * @copyright © 2010-2013 StudyPortals B.V., all rights reserved.
 * @version 2.0.4
 */

namespace StudyPortals\Session;

use StudyPortals\Exception\Silenced;

/**
 * SessionEngine.
 *
 * @package StudyPortals.Framework
 * @subpackage Session
 */

abstract class SessionEngine implements Silenced{

	/**
	 * List of excluded user-agents.
	 *
	 * <p>When (part of) the user-agent string matches any of the elements in
	 * this array, a session-cookie is <strong>not</strong> stored for the
	 * session. This allow the session-engine to be (transparently) disabled
	 * for certain user-agents.
	 *
	 * <p>This is mostly useful to prevent seach-engine crawlers (who we want
	 * to present with a static state, one note influenced by the session) from
	 * getting a cookie. As most crawler ignore the cookie by default, only some
	 * exceptions need to be defined here.<br>
	 * Baidu's crawler is a notable one as it cope very badly with our session-
	 * engine and is thus not only disabled for the above reason, but also to
	 * prevent it from getting "stuck".</p>
	 *
	 * @var array
	 */

	protected static $_excluded_agents = [
		'Baiduspider/2.0;'
	];

	private $_id;
	private $_session_name;

	private $_implicit_save = true;
	private $_aborted = false;

	private $_session_state = [];

	protected $_ip;

	/**
	 * Create or resume a Session.
	 *
	 * <p>The {@see $implicit_save} argument allows implicit saving of the
	 * session-state to be disabled. With implicit saving disabled, the
	 * session-state needs to be stored explicitely by calling {@link
	 * Session::storeSession()} and is not automatically saved upon request
	 * termination.</p>
	 *
	 * @param string $session_name
	 * @param integer $session_ttl
	 * @param boolean $implicit_save
	 *
	 * @return SessionEngine|void
	 */

	public function __construct($session_name, $session_ttl = 3600, $implicit_save = true){

		$this->_implicit_save = (bool) $implicit_save;
		$this->_session_name = (string) $session_name;

		$this->_ip = inet_pton($_SERVER['REMOTE_ADDR']);

		// Continue Session

		if(isset($_COOKIE[$this->_session_name . '_SID'])
			&& preg_match('/^[a-f0-9]{40}$/i', $_COOKIE[$this->_session_name . '_SID'])){

			$this->_id = $_COOKIE[$this->_session_name . '_SID'];

			$timestamp = 0;
			$ip = 0;

			try{

				$session_state = $this->_loadSessionState($this->_id, $timestamp, $ip);
			}

			// Unknown (c.q. previously expired and deleted) session-state

			catch(SessionException $e){

				$this->_startNewSession();
				return;
			}

			// Expired session-state

			if($timestamp + $session_ttl <= time() || inet_pton($_SERVER['REMOTE_ADDR']) != $ip){

				$this->_startNewSession();
				return;
			}

			// Active session-state

			else{

				$this->_session_state = $session_state;
			}
		}

		// Start new Session

		else{

			$this->_startNewSession();
		}
	}

	/**
	 * Attempt to store the Session if implicit saving is enabled.
	 *
	 * @return void
	 */

	public function __destruct(){

		if($this->_implicit_save && !$this->_aborted){

			$this->_storeSessionState($this->_id, $this->_session_state);
		}
	}

	/**
	 * Prevent the Session from being cloned.
	 *
	 * @return void
	 * @throws SessionException
	 */

	public final function __clone(){

		throw new SessionException('Session cannot be cloned');
	}

	/**
	 * Starts a new session by assigning a SID and creating a new session-state.
	 *
	 * @return void
	 */

	private function _startNewSession(){

		$this->_id = $this->_generateSID();

		$this->_createSessionState($this->_id);
		$this->_setSessionCookie();
	}

	/**
	 * Generate a Session Identifier (SID).
	 *
	 * @return string
	 */

	private function _generateSID(){

		return sha1(uniqid($_SERVER['REMOTE_ADDR'], true));
	}

	/**
	 * Stores a Session Identifier (SID) cookie under the current path and domain.
	 *
	 * @param boolean $delete Delete a previously stored cookie
	 * @return void
	 */

	private function _setSessionCookie($delete = false){

		// Check client against list of excluded user-agents

		if(!empty($_SERVER['HTTP_USER_AGENT'])){

			foreach(self::$_excluded_agents as $agent){

				if(strpos($_SERVER['HTTP_USER_AGENT'], $agent) !== false){

					return;
				}
			}
		}

		if($delete){

			$value = '';
			$time = time() - 3600;
		}
		else{

			$value = $this->_id;
			$time = 0;
		}

		$path = trim(dirname($_SERVER['PHP_SELF']), '/\\');
		$path = ($path == '' ? '/' : "/$path/");

		/*
		 * Ensure the "domain" parameter contains an actual domain; it doesn't
		 * work properly if it's an IP-address (or "localhost")
		 */

		$in_addr = @inet_pton($_SERVER['HTTP_HOST']);

		if($in_addr !== false || strtolower($_SERVER['HTTP_HOST']) == 'localhost'){

			$domain = null;
		}
		else{

			$domain = $_SERVER['HTTP_HOST'];
		}

		if(isset($_SERVER['HTTPS'])){

			$secure = filter_var($_SERVER['HTTPS'], FILTER_VALIDATE_BOOLEAN);
		}
		else{

			$secure = false;
		}

		setcookie("{$this->_session_name}_SID", $value, $time, $path, $domain, $secure, true);
	}

	/**
	 * Create a new session-state in the storage medium.
	 *
	 * @param string $session_id
	 * @return void
	 */

	abstract protected function _createSessionState($session_id);

	/**
	 * Load the session-state from the storage medium.
	 *
	 * <p>This method should return an array representing the session-state
	 * stored in the storage medium at the end of the previous request. In case
	 * the requested SID does not exist, a {@link SessionException} should be
	 * thrown.</p>
	 *
	 * <p>The optional, bassed-by-reference, arguments {@link $timestamp} and
	 * {@link $ip} will be set to the timestamp the Session was last "touched"
	 * and the IP address (as a float) to which this Session is locked.</p>
	 *
	 * @param string $session_id
	 * @param integer $timestamp
	 * @param integer $ip
	 * @return array
	 * @throws SessionException
	 */

	abstract protected function _loadSessionState($session_id, &$timestamp = null, &$ip = null);

	/**
	 * "Touch" the session-state to prevent it from expiring.
	 *
	 * @param integer $session_id
	 * @return void
	 */

	abstract protected function _touchSessionState($session_id);

	/**
	 * Store the current session-state.
	 *
	 * <p>Should restore the "amount" (c.q. bytes) of session-state stored If
	 * this information is not available, return <em>-1</em>.</p>
	 *
	 * @param string $session_id
	 * @param array $session_state
	 * @return int
	 */

	abstract protected function _storeSessionState($session_id, array $session_state);

	/**
	 * Delete the current session-state and all related information.
	 *
	 * @param string $session_id
	 * @return void
	 */

	abstract protected function _deleteSessionState($session_id);

	/**
	 * Store the current Session state in the database.
	 *
	 * <p>This method immediately updates the database with the new Session state.
	 * This method <strong>cannot</strong> be used when implicit saving is
	 * enabled.</p>
	 *
	 * @return void
	 * @throws SessionException
	 */

	public function storeSession(){

		if($this->_implicit_save){

			throw new SessionException('Cannot manually store a Session when
				implicit saving is enabled');
		}

		if($this->_aborted){

			throw new AbortedSessionException('Cannot store an aborted Session');
		}
	}

	/**
	 * Abort the current session, the next request will resume the previous session-state.
	 *
	 * <p>This method immediately updates the database with the new Session state.
	 * It is <strong>safe</strong> to use this method when implicit saving is
	 * enabled.</p>
	 *
	 * <p>Data in the Session is not destroyed and remains accessible after the
	 * Session has been aborted. It is only not possible add new data to Session
	 * and (of course) to store the Session.</p>
	 *
	 * @return void
	 * @throws SessionException
	 */

	public function abortSession(){

		if($this->_aborted){

			throw new AbortedSessionException('Cannot abort an already aborted Session');
		}

		$this->_aborted = true;

		$this->_touchSessionState($this->_id);
	}

	/**
	 * Drop the current session, clear all session data and set a new SID.
	 *
	 * <p>This method immediately updates the database with the new Session
	 * state. It is <strong>safe</strong> to use this method when implicit
	 * saving is enabled.</p>
	 *
	 * @return void
	 * @throws SessionException
	 */

	 public function dropSession(){

	 	if($this->_aborted){

			throw new AbortedSessionException('Cannot drop an already aborted Session');
	 	}

		 // Delete the current Session

		$this->_session_state = [];
		$this->_deleteSessionState($this->_id);

		// Start a new Session

		$this->_startNewSession();
	 }

	/**
	 * End the current session.
	 *
	 * <p>End (c.q. drop) the current Session, but do <b>not</b> set a new SID.
	 * Usefull if the user is redirected away and will not be starting a new
	 * Session within the current context.</p>
	 *
	 * @return void
	 * @throws SessionException
	 * @see Session::dropSession()
	 */

	public function endSession(){

		if($this->_aborted){

			throw new SessionException('Cannot end an already aborted Session');
		}

		 // Delete the current Session

		$this->_session_state = [];
		$this->_deleteSessionState($this->_id);

		// End the Session

		$this->_setSessionCookie(true);
		$this->_aborted = true;
	}

	/**
	 * Retrieve an element from the session-state.
	 *
	 * <p>Elements are <em>returned-by-reference</em>. This is done in order to
	 * allow complex  arrays to be stored and retrieved from the session-state.</p>
	 *
	 * <p>When assigning a session-state element to another variable the
	 * reference is broken, unless you assign it using <em>$var = &$Session->var
	 * </em>. This should be considered <strong>by design</strong>. The
	 * return-by-reference is solely meant to allow array operators to function
	 * "through" the Session::__get() method.</p>
	 *
	 * @param string $name
	 * @return mixed
	 * @throws SessionException
	 */

	public function &__get($name){

		if(!isset($this->_session_state[$name]) || isset($this->_session_state[$name])
			&& is_null($this->_session_state[$name])){

			throw new SessionException("Unable to return $name from Session,
				element not found in session-state");
		}

		return $this->_session_state[$name];
	}

	/**
	 * Store an element in the session-state.
	 *
	 * <p>Elements are restored in the order in which they were added. The first
	 * item to be added is the first item to be restored when the Session is
	 * continued. Keep this in mind when working with dependencies.</p>
	 *
	 * @param string $name
	 * @param mixed $value
	 *
	 * @throws SessionException
	 */

	public function __set($name, $value){

		if($this->_aborted){

			throw new SessionException('Cannot set Session variable,
				the Session has been aborted');
		}

		if(is_null($value)){

			unset($this->_session_state[$name]);
		}
		else{

			$this->_session_state[$name] = $value;
		}
	}
}