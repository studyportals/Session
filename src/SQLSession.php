<?php

/**
 * @file Session/Engines/SQL.php
 *
 * @author Thijs Putman <thijs@studyportals.com>
 * @copyright © 2008-2009 Thijs Putman, all rights reserved.
 * @copyright © 2010-2015 StudyPortals B.V., all rights reserved.
 * @version 3.0.0
 */

namespace StudyPortals\Session;

use StudyPortals\SQL\SQLEngine;
use StudyPortals\SQL\SQLException;
use StudyPortals\SQL\SQLite;
use StudyPortals\SQL\SQLResultRow;

/**
 * @class SQLSession
 *
 * @package StudyPortals.Framework
 * @subpackage Session
 */

class SQLSession extends SessionEngine{

	protected $_SQL;

	/**
	 * Lock-file to prevent trashing SQLite-backed sessions.
	 *
	 * @var resource
	 */

	protected $_lock_fp;

	/**
	 * Create or resume a SQL-based Session.
	 *
	 * @param SQLEngine $SQL
	 * @param string $session_name
	 * @param integer $session_ttl
	 * @param boolean $implicit_save
	 * @throws SessionException
	 */

	public function __construct(SQLEngine $SQL, $session_name,
		$session_ttl = 3600, $implicit_save = true){

		$this->_SQL = $SQL;

		// Open the lock-file

		if($this->_SQL instanceof SQLite){

			$lock_file = dirname($this->_SQL->database);
			$lock_file = "{$lock_file}/{$session_name}.lock";

			$this->_lock_fp = @fopen($lock_file, 'ab');
		}

		// Clean expired sessions

		if(rand(0, 99) == 50){

			$this->_cleanExpiredSessions($session_ttl);
		}

		// Create or resume the session

		parent::__construct($session_name, $session_ttl, $implicit_save);
	}

	/**
	 * Clean all expired sessions from the database.
	 *
	 * @param integer $session_ttl
	 * @return void
	 * @throws SessionException
	 */

	protected function _cleanExpiredSessions($session_ttl){

		$time = time() - (int) $session_ttl;

		try{

			if(is_resource($this->_lock_fp)){

				// Block till we acquire an exclusive lock

				flock($this->_lock_fp, LOCK_EX);
			}

			$this->_SQL->Query("DELETE FROM sessions WHERE timestamp < $time");

			// Compact SQLite databases

			if($this->_SQL instanceof SQLite) $this->_SQL->Query('VACUUM');
		}
		catch(SQLException $e){

			try{

				$this->_createSessionTable();
			}
			catch(SessionException $e){

				throw new SessionException('Failed to clean expired sessions
					from database: ' . $e->getMessage());
			}
		}
		finally{

			if(is_resource($this->_lock_fp)){

				flock($this->_lock_fp, LOCK_UN);
			}
		}
	}

	/**
	 * Attempt to create the "sessions" table in the database.
	 *
	 * @return void
	 * @throws SessionException
	 */

	protected function _createSessionTable(){

		try{

			if(is_resource($this->_lock_fp)){

				// Block till we acquire an exclusive lock

				flock($this->_lock_fp, LOCK_EX);
			}

			$this->_SQL->Query('
				CREATE TABLE sessions (
				id char(32) NOT NULL default \'\',
				ip varchar(32) NOT NULL default 0,
				timestamp int(11) NOT NULL default 0,
				data text NOT NULL default \'\');');
		}
		catch(SQLException $e){

			throw new SessionException('Unable to create sessions table');
		}
		finally{

			if(is_resource($this->_lock_fp)){

				flock($this->_lock_fp, LOCK_UN);
			}
		}
	}

	/**
	 * Create a new session-state.
	 *
	 * @param string $session_id
	 *
	 * @throws SessionException
	 * @throws SQLException
	 * @return void
	 * @see SessionEngine::_createSession()
	 */

	protected function _createSessionState($session_id){

		$timestamp = time();
		$iphex = bin2hex($this->_ip);

		try{

			if(is_resource($this->_lock_fp)){

				// Block till we acquire an exclusive lock

				flock($this->_lock_fp, LOCK_EX);
			}

			$this->_SQL->Query("INSERT INTO sessions (id, timestamp, ip)
				VALUES('$session_id', $timestamp, '$iphex')");
		}
		catch(SQLException $e){

			$this->_createSessionTable();

			$this->_SQL->Query("INSERT INTO sessions (id, timestamp, ip)
				VALUES('$session_id', $timestamp, '$iphex')");
		}
		finally{

			if(is_resource($this->_lock_fp)){

				flock($this->_lock_fp, LOCK_UN);
			}
		}
	}

	/**
	 * Load a session-state from the SQL database.
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

		$Result = null;

		try{

			$Result = $this->_SQL->Query("
				SELECT timestamp, ip, data
				FROM sessions
				WHERE id = '$session_id'
			");
		}
		catch(SQLException $e){

			$this->_createSessionTable();
		}

		if($Result instanceof SQLResultRow){

			$timestamp = $Result->timestamp;
			$ip = pack('H*', $Result->ip);

			return (array) unserialize($Result->data);
		}
		else{

			throw new SessionException("No session found in database
				for ID \"$session_id\"");
		}
	}

	/**
	 * Touch the session-state to prevent it from expiring.
	 *
	 * @param string $session_id
	 * @throws SQLException
	 * @see SessionEngine::_touchSessionState()
	 */

	protected function _touchSessionState($session_id){

		$timestamp = time();

		try{

			if(is_resource($this->_lock_fp)){

				// Block till we acquire an exclusive lock

				flock($this->_lock_fp, LOCK_EX);
			}

			$this->_SQL->Query("
				UPDATE sessions
				SET timestamp = $timestamp
				WHERE id = '$session_id'
			");
		}
		catch(SQLException $SQLException){

			throw $SQLException;
		}
		finally{

			if(is_resource($this->_lock_fp)){

				flock($this->_lock_fp, LOCK_UN);
			}
		}
	}

	/**
	 * Store the session-state to the SQL database.
	 *
	 * @param string $session_id
	 * @param array $session_state
	 * @return int
	 * @throws SQLException
	 * @see SessionEngine::_storeSessionState()
	 */

	protected function _storeSessionState($session_id, array $session_state){

		$timestamp = time();
		$session_state = $this->_SQL->escapeString(serialize($session_state));

		try{

			if(is_resource($this->_lock_fp)){

				// Block till we acquire an exclusive lock

				flock($this->_lock_fp, LOCK_EX);
			}

			$this->_SQL->Query("
				UPDATE sessions
				SET data = '$session_state',
					timestamp = $timestamp
				WHERE id = '$session_id'
			");
		}
		catch(SQLException $SQLException){

			throw $SQLException;
		}
		finally{

			if(is_resource($this->_lock_fp)){

				flock($this->_lock_fp, LOCK_UN);
			}
		}

		return strlen($session_state);
	}

	/**
	 * Delete the session-state from the SQL database.
	 *
	 * @param string $session_id
	 * @throws SQLException
	 * @see SessionEngine::_deleteSessionState()
	 */

	protected function _deleteSessionState($session_id){

		try{

			if(is_resource($this->_lock_fp)){

				// Block till we acquire an exclusive lock

				flock($this->_lock_fp, LOCK_EX);
			}

			$this->_SQL->Query("DELETE FROM sessions WHERE id = '$session_id'");
		}
		catch(SQLException $SQLException){

			throw $SQLException;
		}
		finally{

			if(is_resource($this->_lock_fp)){

				flock($this->_lock_fp, LOCK_UN);
			}
		}
	}
}