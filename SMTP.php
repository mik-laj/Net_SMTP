<?php
//
// +----------------------------------------------------------------------+
// | PHP Version 4                                                        |
// +----------------------------------------------------------------------+
// | Copyright (c) 1997-2003 The PHP Group                                |
// +----------------------------------------------------------------------+
// | This source file is subject to version 2.02 of the PHP license,      |
// | that is bundled with this package in the file LICENSE, and is        |
// | available at through the world-wide-web at                           |
// | http://www.php.net/license/2_02.txt.                                 |
// | If you did not receive a copy of the PHP license and are unable to   |
// | obtain it through the world-wide-web, please send a note to          |
// | license@php.net so we can mail you a copy immediately.               |
// +----------------------------------------------------------------------+
// | Author: Chuck Hagenbuch <chuck@horde.org>                            |
// |         Jon Parise <jon@php.net>                                     |
// +----------------------------------------------------------------------+

require_once 'PEAR.php';
require_once 'Net/Socket.php';

/**
 * Provides an implementation of the SMTP protocol using PEAR's
 * Net_Socket:: class.
 *
 * @package Net_SMTP
 * @author  Chuck Hagenbuch <chuck@horde.org>
 * @author  Jon Parise <jon@php.net>
 */
class Net_SMTP extends PEAR {

    /**
     * The server to connect to.
     * @var string
     */
    var $host = 'localhost';

    /**
     * The port to connect to.
     * @var int
     */
    var $port = 25;

    /**
     * The value to give when sending EHLO or HELO.
     * @var string
     */
    var $localhost = 'localhost';

    /**
     * The socket resource being used to connect to the SMTP server.
     * @var resource
     */
    var $_socket = null;

    /**
     * The most recent reply code
     * @var int
     */
    var $code;

    /**
     * Stores detected features of the SMTP server.
     * @var array
     */
    var $esmtp = array();

    /**
     * The last line read from the server.
     * @var string
     */
    var $lastline;

    /**
     * The list of supported authentication methods, ordered by preference.
     * @var string
     */
    var $_auth_methods = array('LOGIN', 'PLAIN');

    /**
     * Constructor
     *
     * Instantiates a new Net_SMTP object, overriding any defaults
     * with parameters that are passed in.
     *
     * @param string The server to connect to.
     * @param int The port to connect to.
     * @param string The value to give when sending EHLO or HELO.
     */
    function Net_SMTP($host = null, $port = null, $localhost = null)
    {
        if (isset($host)) $this->host = $host;
        if (isset($port)) $this->port = $port;
        if (isset($localhost)) $this->localhost = $localhost;

        $this->_socket = new Net_Socket();
    }

    /**
     * Attempt to connect to the SMTP server.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access public
     */
    function connect()
    {
        if (PEAR::isError($this->_socket->connect($this->host, $this->port))) {
            return new PEAR_Error('unable to open socket');
        }

        if (PEAR::isError($this->validateResponse('220'))) {
            return new PEAR_Error('smtp server not 220 ready');
        }
        if (!$this->identifySender()) {
            return new PEAR_Error('unable to identify smtp server');
        }

        return true;
    }

    /**
     * Attempt to disconnect from the SMTP server.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access public
     */
    function disconnect()
    {
        if (PEAR::isError($this->_send("QUIT\r\n"))) {
            return new PEAR_Error('write to socket failed');
        }
        if (!$this->validateResponse('221')) {
            return new PEAR_Error('221 Bye not received');
        }
        if (PEAR::isError($this->_socket->disconnect())) {
            return new PEAR_Error('socket disconnect failed');
        }

        return true;
    }

    /**
     * Send the given string of data to the server.
     *
     * @param   string  $data       The string of data to send.
     *
     * @return  mixed   True on success or a PEAR_Error object on failure.
     *
     * @access  private
     */
    function _send($data)
    {
        if (PEAR::isError($error = $this->_socket->write($data))) {
            echo 'Failed to write to socket: ' .  $error->getMessage() . "\n";
            return new PEAR_Error('Failed to write to socket: ' .
                                  $error->getMessage());
        }

        return true;
    }

    /**
     * Returns the name of the best authentication method that the server
     * has advertised.
     *
     * @return mixed    Returns a string containing the name of the best
     *                  supported authentication method or a PEAR_Error object
     *                  if a failure condition is encountered.
     * @access private
     */
    function _getBestAuthMethod()
    {
        $available_methods = explode(' ', $this->esmtp['AUTH']);

        foreach ($this->_auth_methods as $method) {
            if (in_array($method, $available_methods)) {
                return $method;
            }
        }

        return new PEAR_Error('No supported authentication methods');
    }

    /**
     * Attempt to do SMTP authentication.
     *
     * @param string The userid to authenticate as.
     * @param string The password to authenticate with.
     * @param string The requested authentication method.  If none is
     *               specified, the best supported method will be used.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access public
     */
    function auth($uid, $pwd , $method = '')
    {
        if (!array_key_exists('AUTH', $this->esmtp)) {
            return new PEAR_Error('SMTP server does no support authentication');
        }

        /*
         * If no method has been specified, get the name of the best supported
         * method advertised by the SMTP server.
         */
        if (empty($method)) {
            if (PEAR::isError($method = $this->_getBestAuthMethod())) {
                /* Return the PEAR_Error object from _getBestAuthMethod() */
                return $method;
            } 
        } else {
            $method = strtoupper($method);
        }

        switch ($method) {
            case 'LOGIN':
                $result = $this->_authLogin($uid, $pwd);
                break;
            case 'PLAIN':
                $result = $this->_authPlain($uid, $pwd);
                break;
            default : 
                $result = new PEAR_Error("$method is not a supported authentication method");
                break;
        }

        /* If an error was encountered, return the PEAR_Error object. */
        if (PEAR::isError($result)) {
            return $result;
        }

        return true;
    }

    /**
     * Authenticates the user using the LOGIN method.
     *
     * @param string The userid to authenticate as.
     * @param string The password to authenticate with.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access private 
     */
    function _authLogin($uid, $pwd)
    {
        if (PEAR::isError($this->_send("AUTH LOGIN\r\n"))) { 
            return new PEAR_Error('write to socket failed');
        }
        if (!$this->validateResponse('334')) {
            return new PEAR_Error('AUTH LOGIN not recognized');
        }

        if (PEAR::isError($this->_send(base64_encode($uid) . "\r\n"))) {
            return new PEAR_Error('write to socket failed');
        }
        if (!$this->validateResponse('334')) {
            return new PEAR_Error('354 not received');
        }

        if (PEAR::isError($this->_send(base64_encode($pwd) . "\r\n"))) {
            return new PEAR_Error('write to socket failed');
        }
        if (!$this->validateResponse('235')) {
            return new PEAR_Error('235 not received');
        }

        return true;
    }

    /**
     * Authenticates the user using the PLAIN method.
     *
     * @param string The userid to authenticate as.
     * @param string The password to authenticate with.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access private 
     */
    function _authPlain($uid, $pwd)
    {
        if (PEAR::isError($this->_send("AUTH PLAIN\r\n"))) {
            return new PEAR_Error('write to socket failed');
        }
        if (!$this->validateResponse('334')) { 
            return new PEAR_Error('AUTH LOGIN not recognized'); 
        }

        $auth_str = base64_encode(chr(0) . $uid . chr(0) . $pwd);
        if (PEAR::isError($this->_send($auth_str . "\r\n"))) {
            return new PEAR_Error('write to socket failed');
        }
        if (!$this->validateResponse('235')) { 
            return new PEAR_Error('235 not received');
        }

        return true;
    }

    /**
     * Send the HELO command.
     *
     * @param string The domain name to say we are.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access public
     */
    function helo($domain)
    {
        if (PEAR::isError($this->_send("HELO $domain\r\n"))) {
            return new PEAR_Error('write to socket failed');
        }
        if (!($this->validateResponse('250'))) {
            return new PEAR_Error('250 OK not received');
        }

        return true;
    }

    /**
     * Send the MAIL FROM: command.
     *
     * @param string The sender (reverse path) to set.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access public
     */
    function mailFrom($reverse_path)
    {
        if (PEAR::isError($this->_send("MAIL FROM:<$reverse_path>\r\n"))) {
            return new PEAR_Error('write to socket failed');
        }
        if (!($this->validateResponse('250'))) {
            return new PEAR_Error('250 OK not received');
        }

        return true;
    }

    /**
     * Send the RCPT TO: command.
     *
     * @param string The recipient (forward path) to add.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access public
     */
    function rcptTo($forward_path)
    {
        /* Note: 251 is also a valid response code */

        if (PEAR::isError($this->_send("RCPT TO: <$forward_path>\r\n"))) {
            return new PEAR_Error('write to socket failed');
        }
        if (!($this->validateResponse('250'))) {
            return new PEAR_Error($this->lastline);
        }

        return true;
    }

    /**
     * Send the DATA command.
     *
     * @param string The message body to send.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access public
     */
    function data($data)
    {
        if (isset($this->esmtp['SIZE'])) {
            if (strlen($data) >= $this->esmtp['SIZE']) {
                $this->disconnect();
                return new PEAR_Error('Message size excedes the server limit');
            }
        }

        $data = preg_replace("/([^\r]{1})\n/", "\\1\r\n", $data);
        $data = preg_replace("/\n\n/", "\n\r\n", $data);
        $data = preg_replace("/\n\./", "\n..", $data);

        if (PEAR::isError($this->_send("DATA\r\n"))) {
            return new PEAR_Error('write to socket failed');
        }
        if (!($this->validateResponse('354'))) {
            return new PEAR_Error('354 not received');
        }

        if (PEAR::isError($this->_send($data . "\r\n.\r\n"))) {
            return new PEAR_Error('write to socket failed');
        }
        if (!($this->validateResponse('250'))) {
            return new PEAR_Error('250 OK not received');
        }

        return true;
    }

    /**
     * Send the SEND FROM: command.
     *
     * @param string The reverse path to send.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access public
     */
    function send_from($reverse_path)
    {
        if (PEAR::isError($this->_send("SEND FROM:<$reverse_path>\r\n"))) {
            return new PEAR_Error('write to socket failed');
        }
        if (!($this->validateResponse('250'))) {
            return new PEAR_Error('250 OK not received');
        }

        return true;
    }

    /**
     * Send the SOML FROM: command.
     *
     * @param string The reverse path to send.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access public
     */
    function soml_from($reverse_path)
    {
        if (PEAR::isError($this->_send("SOML FROM:<$reverse_path>\r\n"))) {
            return new PEAR_Error('write to socket failed');
        }
        if (!($this->validateResponse('250'))) {
            return new PEAR_Error('250 OK not received');
        }

        return true;
    }

    /**
     * Send the SAML FROM: command.
     *
     * @param string The reverse path to send.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access public
     */
    function saml_from($reverse_path)
    {
        if (PEAR::isError($this->_send("SAML FROM:<$reverse_path>\r\n"))) {
            return new PEAR_Error('write to socket failed');
        }
        if (!($this->validateResponse('250'))) {
            return new PEAR_Error('250 OK not received');
        }

        return true;
    }

    /**
     * Send the RSET command.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access public
     */
    function rset()
    {
        if (PEAR::isError($this->_send("RSET\r\n"))) {
            return new PEAR_Error('write to socket failed');
        }
        if (!($this->validateResponse('250'))) {
            return new PEAR_Error('250 OK not received');
        }

        return true;
    }

    /**
     * Send the VRFY command.
     *
     * @param string The string to verify
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access public
     */
    function vrfy($string)
    {
        /* Note: 251 is also a valid response code */
        if (PEAR::isError($this->_send("VRFY $string\r\n"))) {
            return new PEAR_Error('write to socket failed');
        }
        if (!($this->validateResponse('250'))) {
            return new PEAR_Error('250 OK not received');
        }

        return true;
    }

    /**
     * Send the NOOP command.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access public
     */
    function noop()
    {
        if (PEAR::isError($this->_send("NOOP\r\n"))) {
            return new PEAR_Error('write to socket failed');
        }
        if (!($this->validateResponse('250'))) {
            return new PEAR_Error('250 OK not received');
        }

        return true;
    }

    /**
     * Attempt to send the EHLO command and obtain a list of ESMTP
     * extensions available, and failing that just send HELO.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access private
     */
    function identifySender()
    {
        if (PEAR::isError($this->_send("EHLO $this->localhost\r\n"))) {
            return new PEAR_Error('write to socket failed');
        }

        $extensions = array();
        if (!($this->validateAndParseResponse('250', $extensions))) {
            if (PEAR::isError($this->_send("HELO $this->localhost\r\n"))) {
                return new PEAR_Error('write to socket failed');
            }
            if (!($this->validateResponse('250'))) {
                return new PEAR_Error('HELO not accepted', $this->code);
            }

            return true;
        }

        for ($i = 0; $i < count($extensions); $i++) {
            $verb = strtok($extensions[$i], ' ');
            $arguments = substr($extensions[$i], strlen($verb) + 1,
                                strlen($extensions[$i]) - strlen($verb) - 1);
            $this->esmtp[$verb] = $arguments;
        }

        return true;
    }

    /**
     * Read a response from the server and see if the response code
     * matches what we are expecting.
     *
     * @param int The response code we are expecting.
     *
     * @return boolean True if we get what we expect, false otherwise.
     * @access private
     */
    function validateResponse($code)
    {
        while ($this->lastline = $this->_socket->readLine()) {
            $reply_code = strtok($this->lastline, ' ');
            if (!(strcmp($code, $reply_code))) {
                $this->code = $reply_code;
                return true;
            } else {
                $reply_code = strtok($this->lastline, '-');
                if (strcmp($code, $reply_code)) {
                    $this->code = $reply_code;
                    return false;
                }
            }
        }

        return false;
    }

    /**
     * Read a response from the server and see if the response code
     * matches what we are expecting. Also save the rest of the
     * response in the array passed by reference as the second
     * argument.
     *
     * @param int The response code we are expecting.
     * @param array An array to dump the rest of the response into.
     *
     * @return boolean True if we get what we expect, false otherwise.
     * @access private
     */
    function validateAndParseResponse($code, &$arguments)
    {
        $arguments = array();

        while ($this->lastline = $this->_socket->readLine()) {
            $reply_code = strtok($this->lastline, ' ');
            if (!(strcmp($code, $reply_code))) {
                $arguments[] = substr($this->lastline, strlen($code) + 1,
                                      strlen($this->lastline) - strlen($code) - 1);
                $this->code = $reply_code;
                return true;
            } else {
                $reply_code = strtok($this->lastline, '-');
                if (strcmp($code, $reply_code)) {
                    $this->code = $reply_code;
                    return false;
                }
            }
            $arguments[] = substr($this->lastline, strlen($code) + 1,
                                  strlen($this->lastline) - strlen($code) - 1);
        }

        return false;
    }
}

?>
