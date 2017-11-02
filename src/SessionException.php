<?php
/**
 * @file SessionException.php
 *
 * @author Rob van den Hout <vdhout@studyportals.com>
 * @version 1.0.0
 * @copyright © 2017 StudyPortals B.V., all rights reserved.
 */

namespace StudyPortals\Session;

use StudyPortals\Exception\BaseException;
use StudyPortals\Exception\Silenced;

/**
 * SessionException.
 *
 * @package StudyPortals.Framework
 * @subpackage Session
 */
class SessionException extends BaseException implements Silenced{

}