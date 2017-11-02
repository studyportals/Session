<?php
/**
 * @file AbortedSessionException.php
 *
 * @author Rob van den Hout <vdhout@studyportals.com>
 * @version 1.0.0
 * @copyright © 2017 StudyPortals B.V., all rights reserved.
 */

namespace StudyPortals\Session;

/**
 * Gets thrown when any operation is attempted on an aborted Session.
 *
 * @package StudyPortals.Framework
 * @subpackage Session
 */
class AbortedSessionException extends SessionException{

}