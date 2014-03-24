<?php
/*****************************************************************************
 *
 * live.php - Standalone PHP script to serve the unix socket of the
 *            MKLivestatus NEB module as webservice.
 *
 * Copyright (c) 2010,2011 Lars Michelsen <lm@larsmichelsen.com>
 *
 * License:
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2 as
 * published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 675 Mass Ave, Cambridge, MA 02139, USA.
 *
 * @AUTHOR   Lars Michelsen <lm@larsmichelsen.com>
 * @HOME     http://nagios.larsmichelsen.com/livestatusslave/
 * @VERSION  1.1
 *****************************************************************************/

/**
 * Script configuration.
 */

$conf = Array(
    // The socket type can be 'unix' for connecting with the unix socket or 'tcp'
    // to connect to a tcp socket.
    'socketType'       => 'unix',
    // When using a unix socket the path to the socket needs to be set
    'socketPath'       => '/var/lib/nagios/rw/live',
    // When using a tcp socket the address and port needs to be set
    'socketAddress'    => '',
    'socketPort'       => '',
    // Modify the default allowed query type match regex
    'queryTypes'       => '(GET|LOGROTATE|COMMAND)',
    // Modify the matchig regex for the allowed tables
    'queryTables'      => '([a-z]+)',
);


###############################################################################
# Don't modify the code below when you're not aware of what you are doing...
###############################################################################

# Include optional configuration to override the builtin configuration
if(file_exists('./live_config.php'))
    include('./live_config.php');

class LiveException extends Exception {}

$LIVE = null;

// Start the main function
livestatusSlave();

function livestatusSlave() {
    global $conf;

    try {
        verifyConfig();
    
        // Run preflight checks
        if($conf['socketType'] == 'unix') {
            checkSocketExists();
        }
        
        checkSocketSupport();
    
        connectSocket();

        // Get the query
        $query = getQuery();

        // Handle the query now
        response(Array(0, 'OK'), queryLivestatus($query));

        closeSocket();
        exit(0);
    } catch(LiveException $e) {
        response(Array(1, 'ERROR: '.$e->getMessage()), Array()); 
        closeSocket();
        exit(1);
    }
}

function readQuery() {
    global $argv;
    if(isset($_REQUEST['q']) && $_REQUEST['q'] !== '')
        return str_replace('\\\\n', "\n", $_REQUEST['q']);
    elseif(isset($argv[1]) && $argv[1] !== '')
        return str_replace('\\n', "\n", $argv[1]);
    else
        throw new LiveException('No query given in "q" Attribute nor argv[0].');
}

function getQuery() {
    global $conf;
    $query = readQuery();

    // Validate the query
    if(!preg_match("/^".$conf['queryTypes']."\s".$conf['queryTables']."\n/", $query))
        throw new LiveException('Invalid livestatus query.');

    return $query;
}

function response($head, $body) {
    header('Content-type: application/json');
    $json_result = json_encode(Array($head, $body));

    // Support jsonp when requested by client (see http://en.wikipedia.org/wiki/JSONP).
    if(isset($_REQUEST['callback']) && $_REQUEST['callback'] != '')
        $json_result = $_REQUEST['callback']."(".$json_result.")";

    echo $json_result;
}

function verifyConfig() {
    global $conf;

    if($conf['socketType'] != 'tcp' && $conf['socketType'] != 'unix')
        throw new LiveException('Socket Type is invalid. Need to be "unix" or "tcp".');
    
    if($conf['socketType'] == 'unix') {
        if($conf['socketPath'] == '')
            throw new LiveException('The option socketPath is empty.');
    } elseif($conf['socketType'] == 'tcp') {
        if($conf['socketAddress'] == '')
            throw new LiveException('The option socketAddress is empty.');
        if($conf['socketPort'] == '')
            throw new LiveException('The option socketPort is empty.');
    }
}

function closeSocket() {
    global $LIVE;
    @socket_close($LIVE);
    $LIVE = null;
}

function readSocket($len) {
    global $LIVE;
    $offset = 0;
    $socketData = '';
    
    while($offset < $len) {
        if(($data = @socket_read($LIVE, $len - $offset)) === false)
            return false;
    
        $dataLen = strlen ($data);
        $offset += $dataLen;
        $socketData .= $data;
        
        if($dataLen == 0)
            break;
    }
    
    return $socketData;
}

function queryLivestatus($query) {
    global $LIVE;
    
    // Query to get a json formated array back
    // Use fixed16 header
    socket_write($LIVE, $query . "OutputFormat:json\nResponseHeader: fixed16\n\n");
    
    // Read 16 bytes to get the status code and body size
    $read = readSocket(16);
    
    if($read === false)
        throw new LiveException('Problem while reading from socket: '.socket_strerror(socket_last_error($LIVE)));
    
    // Extract status code
    $status = substr($read, 0, 3);
    
    // Extract content length
    $len = intval(trim(substr($read, 4, 11)));
    
    // Read socket until end of data
    $read = readSocket($len);
    
    if($read === false)
        throw new LiveException('Problem while reading from socket: '.socket_strerror(socket_last_error($LIVE)));
    
    // Catch errors (Like HTTP 200 is OK)
    if($status != "200")
        throw new LiveException('Problem while reading from socket: '.$read);
    
    // Catch problems occured while reading? 104: Connection reset by peer
    if(socket_last_error($LIVE) == 104)
        throw new LiveException('Problem while reading from socket: '.socket_strerror(socket_last_error($LIVE)));
    
    // Decode the json response
    $obj = json_decode(utf8_encode($read));
    
    // json_decode returns null on syntax problems
    if($obj === null)
        throw new LiveException('The response has an invalid format');
    else
        return $obj;
}

function connectSocket() {
    global $conf, $LIVE;
    // Create socket connection
    if($conf['socketType'] === 'unix') {
        $LIVE = socket_create(AF_UNIX, SOCK_STREAM, 0);
    } elseif($conf['socketType'] === 'tcp') {
        $LIVE = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    }
    
    if($LIVE == false) {
        throw new LiveException('Could not create livestatus socket connection.');
    }
    
    // Connect to the socket
    if($conf['socketType'] === 'unix') {
        $result = socket_connect($LIVE, $conf['socketPath']);
    } elseif($conf['socketType'] === 'tcp') {
        $result = socket_connect($LIVE, $conf['socketAddress'], $conf['socketPort']);
    }
    
    if($result == false) {
        throw new LiveException('Unable to connect to livestatus socket.');
    }

    // Maybe set some socket options
    if($conf['socketType'] === 'tcp') {
        // Disable Nagle's Alogrithm - Nagle's Algorithm is bad for brief protocols
        if(defined('TCP_NODELAY')) {
            socket_set_option($LIVE, SOL_TCP, TCP_NODELAY, 1);
        } else {
            // See http://bugs.php.net/bug.php?id=46360
            socket_set_option($LIVE, SOL_TCP, 1, 1);
        }
    }
}

function checkSocketSupport() {
    if(!function_exists('socket_create'))
        throw new LiveException('The PHP function socket_create is not available. Maybe the sockets module is missing in your PHP installation.');
}

function checkSocketExists() {
    global $conf;
    if(!file_exists($conf['socketPath']))
        throw new LiveException('The configured livestatus socket does not exists');
}

###############################################################################
# Workarounds for older PHP versions. This should be removed in future!
###############################################################################

/**
 * Implements handling of PHP to JSON conversion for NagVis
 * (Needed for < PHP 5.2.0)
 *
 * Function taken from http://de.php.net/json_encode (Steve 01-May-2008 02:35)
 *
 * @param        String        Debug message
 * @author     Lars Michelsen <lars@vertical-visions.de>
 */
if (!function_exists('json_encode')) {
    function json_encode($a=false) {
        if (is_null($a)) return 'null';
        if ($a === false) return 'false';
        if ($a === true) return 'true';
        if (is_scalar($a)) {
            if (is_float($a)) {
                // Always use "." for floats.
                return floatval(str_replace(",", ".", strval($a)));
            }

            if (is_string($a)) {
                static $jsonReplaces = array(array("\\", "/", "\n", "\t", "\r", "\b", "\f", '"'), array('\\\\', '\\/', '\\n', '\\t', '\\r', '\\b', '\\f', '\"'));
                return '"' . str_replace($jsonReplaces[0], $jsonReplaces[1], $a) . '"';
            }
            else
                return $a;
        }
        $isList = true;
        for ($i = 0, reset($a); $i < count($a); $i++, next($a)) {
            if (key($a) !== $i) {
                $isList = false;
                break;
            }
        }
        $result = array();
        if ($isList) {
            foreach ($a as $v) $result[] = json_encode($v);
            return '[' . join(',', $result) . ']';
        } else {
            foreach ($a as $k => $v) $result[] = json_encode($k).':'.json_encode($v);
            return '{' . join(',', $result) . '}';
        }
    }
}

/**
 * Implements handling of PHP to JSON conversion for NagVis
 * (Needed for < PHP 5.2.0)
 *
 * Function taken from http://de.php.net/json_decode (www at walidator dot info 30-May-2009 02:16)
 * hope thats okay...
 *
 * @param   String    Debug message
 * @author  Lars Michelsen <lars@vertical-visions.de>
 */
if(!function_exists('json_decode')){

if(!class_exists('Services_JSON')) {
    /**
    * Converts to and from JSON format.
    *
    * JSON (JavaScript Object Notation) is a lightweight data-interchange
    * format. It is easy for humans to read and write. It is easy for machines
    * to parse and generate. It is based on a subset of the JavaScript
    * Programming Language, Standard ECMA-262 3rd Edition - December 1999.
    * This feature can also be found in  Python. JSON is a text format that is
    * completely language independent but uses conventions that are familiar
    * to programmers of the C-family of languages, including C, C++, C#, Java,
    * JavaScript, Perl, TCL, and many others. These properties make JSON an
    * ideal data-interchange language.
    *
    * This package provides a simple encoder and decoder for JSON notation. It
    * is intended for use with client-side Javascript applications that make
    * use of HTTPRequest to perform server communication functions - data can
    * be encoded into JSON notation for use in a client-side javascript, or
    * decoded from incoming Javascript requests. JSON format is native to
    * Javascript, and can be directly eval()'ed with no further parsing
    * overhead
    *
    * All strings should be in ASCII or UTF-8 format!
    *
    * LICENSE: Redistribution and use in source and binary forms, with or
    * without modification, are permitted provided that the following
    * conditions are met: Redistributions of source code must retain the
    * above copyright notice, this list of conditions and the following
    * disclaimer. Redistributions in binary form must reproduce the above
    * copyright notice, this list of conditions and the following disclaimer
    * in the documentation and/or other materials provided with the
    * distribution.
    *
    * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED
    * WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF
    * MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN
    * NO EVENT SHALL CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
    * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
    * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS
    * OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
    * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR
    * TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE
    * USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH
    * DAMAGE.
    *
    * @category
    * @package     Services_JSON
    * @author      Michal Migurski <mike-json@teczno.com>
    * @author      Matt Knapp <mdknapp[at]gmail[dot]com>
    * @author      Brett Stimmerman <brettstimmerman[at]gmail[dot]com>
    * @copyright   2005 Michal Migurski
    * @version     CVS: $Id: JSON.php,v 1.31 2006/06/28 05:54:17 migurski Exp $
    * @license     http://www.opensource.org/licenses/bsd-license.php
    * @link        http://pear.php.net/pepr/pepr-proposal-show.php?id=198
    */

    /**
    * Marker constant for Services_JSON::decode(), used to flag stack state
    */
    define('SERVICES_JSON_SLICE',   1);

    /**
    * Marker constant for Services_JSON::decode(), used to flag stack state
    */
    define('SERVICES_JSON_IN_STR',  2);

    /**
    * Marker constant for Services_JSON::decode(), used to flag stack state
    */
    define('SERVICES_JSON_IN_ARR',  3);

    /**
    * Marker constant for Services_JSON::decode(), used to flag stack state
    */
    define('SERVICES_JSON_IN_OBJ',  4);

    /**
    * Marker constant for Services_JSON::decode(), used to flag stack state
    */
    define('SERVICES_JSON_IN_CMT', 5);

    /**
    * Behavior switch for Services_JSON::decode()
    */
    define('SERVICES_JSON_LOOSE_TYPE', 16);

    /**
    * Behavior switch for Services_JSON::decode()
    */
    define('SERVICES_JSON_SUPPRESS_ERRORS', 32);

    /**
    * Converts to and from JSON format.
    *
    * Brief example of use:
    *
    * <code>
    * // create a new instance of Services_JSON
    * $json = new Services_JSON();
    *
    * // convert a complexe value to JSON notation, and send it to the browser
    * $value = array('foo', 'bar', array(1, 2, 'baz'), array(3, array(4)));
    * $output = $json->encode($value);
    *
    * print($output);
    * // prints: ["foo","bar",[1,2,"baz"],[3,[4]]]
    *
    * // accept incoming POST data, assumed to be in JSON notation
    * $input = file_get_contents('php://input', 1000000);
    * $value = $json->decode($input);
    * </code>
    */
    class Services_JSON
    {
        /**
            * constructs a new JSON instance
            *
            * @param    int     $use    object behavior flags; combine with boolean-OR
            *
            *                           possible values:
            *                           - SERVICES_JSON_LOOSE_TYPE:  loose typing.
            *                                   "{...}" syntax creates associative arrays
            *                                   instead of objects in decode().
            *                           - SERVICES_JSON_SUPPRESS_ERRORS:  error suppression.
            *                                   Values which can't be encoded (e.g. resources)
            *                                   appear as NULL instead of throwing errors.
            *                                   By default, a deeply-nested resource will
            *                                   bubble up with an error, so all return values
            *                                   from encode() should be checked with isError()
            */
            function Services_JSON($use = 0)
            {
                    $this->use = $use;
            }

        /**
            * convert a string from one UTF-16 char to one UTF-8 char
            *
            * Normally should be handled by mb_convert_encoding, but
            * provides a slower PHP-only method for installations
            * that lack the multibye string extension.
            *
            * @param    string  $utf16  UTF-16 character
            * @return   string  UTF-8 character
            * @access   private
            */
            function utf162utf8($utf16)
            {
                    // oh please oh please oh please oh please oh please
                    if(function_exists('mb_convert_encoding')) {
                            return mb_convert_encoding($utf16, 'UTF-8', 'UTF-16');
                    }

                    $bytes = (ord($utf16{0}) << 8) | ord($utf16{1});

                    switch(true) {
                            case ((0x7F & $bytes) == $bytes):
                                    // this case should never be reached, because we are in ASCII range
                                    // see: http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                    return chr(0x7F & $bytes);

                            case (0x07FF & $bytes) == $bytes:
                                    // return a 2-byte UTF-8 character
                                    // see: http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                    return chr(0xC0 | (($bytes >> 6) & 0x1F))
                                            . chr(0x80 | ($bytes & 0x3F));

                            case (0xFFFF & $bytes) == $bytes:
                                    // return a 3-byte UTF-8 character
                                    // see: http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                    return chr(0xE0 | (($bytes >> 12) & 0x0F))
                                            . chr(0x80 | (($bytes >> 6) & 0x3F))
                                            . chr(0x80 | ($bytes & 0x3F));
                    }

                    // ignoring UTF-32 for now, sorry
                    return '';
            }

        /**
            * convert a string from one UTF-8 char to one UTF-16 char
            *
            * Normally should be handled by mb_convert_encoding, but
            * provides a slower PHP-only method for installations
            * that lack the multibye string extension.
            *
            * @param    string  $utf8   UTF-8 character
            * @return   string  UTF-16 character
            * @access   private
            */
            function utf82utf16($utf8)
            {
                    // oh please oh please oh please oh please oh please
                    if(function_exists('mb_convert_encoding')) {
                            return mb_convert_encoding($utf8, 'UTF-16', 'UTF-8');
                    }

                    switch(strlen($utf8)) {
                            case 1:
                                    // this case should never be reached, because we are in ASCII range
                                    // see: http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                    return $utf8;

                            case 2:
                                    // return a UTF-16 character from a 2-byte UTF-8 char
                                    // see: http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                    return chr(0x07 & (ord($utf8{0}) >> 2))
                                            . chr((0xC0 & (ord($utf8{0}) << 6))
                                                    | (0x3F & ord($utf8{1})));

                            case 3:
                                    // return a UTF-16 character from a 3-byte UTF-8 char
                                    // see: http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                    return chr((0xF0 & (ord($utf8{0}) << 4))
                                                    | (0x0F & (ord($utf8{1}) >> 2)))
                                            . chr((0xC0 & (ord($utf8{1}) << 6))
                                                    | (0x7F & ord($utf8{2})));
                    }

                    // ignoring UTF-32 for now, sorry
                    return '';
            }

        /**
            * encodes an arbitrary variable into JSON format
            *
            * @param    mixed   $var    any number, boolean, string, array, or object to be encoded.
            *                           see argument 1 to Services_JSON() above for array-parsing behavior.
            *                           if var is a strng, note that encode() always expects it
            *                           to be in ASCII or UTF-8 format!
            *
            * @return   mixed   JSON string representation of input var or an error if a problem occurs
            * @access   public
            */
            function encode($var)
            {
                    switch (gettype($var)) {
                            case 'boolean':
                                    return $var ? 'true' : 'false';

                            case 'NULL':
                                    return 'null';

                            case 'integer':
                                    return (int) $var;

                            case 'double':
                            case 'float':
                                    return (float) $var;

                            case 'string':
                                    // STRINGS ARE EXPECTED TO BE IN ASCII OR UTF-8 FORMAT
                                    $ascii = '';
                                    $strlen_var = strlen($var);

                                /*
                                    * Iterate over every character in the string,
                                    * escaping with a slash or encoding to UTF-8 where necessary
                                    */
                                    for ($c = 0; $c < $strlen_var; ++$c) {

                                            $ord_var_c = ord($var{$c});

                                            switch (true) {
                                                    case $ord_var_c == 0x08:
                                                            $ascii .= '\b';
                                                            break;
                                                    case $ord_var_c == 0x09:
                                                            $ascii .= '\t';
                                                            break;
                                                    case $ord_var_c == 0x0A:
                                                            $ascii .= '\n';
                                                            break;
                                                    case $ord_var_c == 0x0C:
                                                            $ascii .= '\f';
                                                            break;
                                                    case $ord_var_c == 0x0D:
                                                            $ascii .= '\r';
                                                            break;

                                                    case $ord_var_c == 0x22:
                                                    case $ord_var_c == 0x2F:
                                                    case $ord_var_c == 0x5C:
                                                            // double quote, slash, slosh
                                                            $ascii .= '\\'.$var{$c};
                                                            break;

                                                    case (($ord_var_c >= 0x20) && ($ord_var_c <= 0x7F)):
                                                            // characters U-00000000 - U-0000007F (same as ASCII)
                                                            $ascii .= $var{$c};
                                                            break;

                                                    case (($ord_var_c & 0xE0) == 0xC0):
                                                            // characters U-00000080 - U-000007FF, mask 110XXXXX
                                                            // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                                            $char = pack('C*', $ord_var_c, ord($var{$c + 1}));
                                                            $c += 1;
                                                            $utf16 = $this->utf82utf16($char);
                                                            $ascii .= sprintf('\u%04s', bin2hex($utf16));
                                                            break;

                                                    case (($ord_var_c & 0xF0) == 0xE0):
                                                            // characters U-00000800 - U-0000FFFF, mask 1110XXXX
                                                            // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                                            $char = pack('C*', $ord_var_c,
                                                                                    ord($var{$c + 1}),
                                                                                    ord($var{$c + 2}));
                                                            $c += 2;
                                                            $utf16 = $this->utf82utf16($char);
                                                            $ascii .= sprintf('\u%04s', bin2hex($utf16));
                                                            break;

                                                    case (($ord_var_c & 0xF8) == 0xF0):
                                                            // characters U-00010000 - U-001FFFFF, mask 11110XXX
                                                            // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                                            $char = pack('C*', $ord_var_c,
                                                                                    ord($var{$c + 1}),
                                                                                    ord($var{$c + 2}),
                                                                                    ord($var{$c + 3}));
                                                            $c += 3;
                                                            $utf16 = $this->utf82utf16($char);
                                                            $ascii .= sprintf('\u%04s', bin2hex($utf16));
                                                            break;

                                                    case (($ord_var_c & 0xFC) == 0xF8):
                                                            // characters U-00200000 - U-03FFFFFF, mask 111110XX
                                                            // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                                            $char = pack('C*', $ord_var_c,
                                                                                    ord($var{$c + 1}),
                                                                                    ord($var{$c + 2}),
                                                                                    ord($var{$c + 3}),
                                                                                    ord($var{$c + 4}));
                                                            $c += 4;
                                                            $utf16 = $this->utf82utf16($char);
                                                            $ascii .= sprintf('\u%04s', bin2hex($utf16));
                                                            break;

                                                    case (($ord_var_c & 0xFE) == 0xFC):
                                                            // characters U-04000000 - U-7FFFFFFF, mask 1111110X
                                                            // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                                            $char = pack('C*', $ord_var_c,
                                                                                    ord($var{$c + 1}),
                                                                                    ord($var{$c + 2}),
                                                                                    ord($var{$c + 3}),
                                                                                    ord($var{$c + 4}),
                                                                                    ord($var{$c + 5}));
                                                            $c += 5;
                                                            $utf16 = $this->utf82utf16($char);
                                                            $ascii .= sprintf('\u%04s', bin2hex($utf16));
                                                            break;
                                            }
                                    }

                                    return '"'.$ascii.'"';

                            case 'array':
                                /*
                                    * As per JSON spec if any array key is not an integer
                                    * we must treat the the whole array as an object. We
                                    * also try to catch a sparsely populated associative
                                    * array with numeric keys here because some JS engines
                                    * will create an array with empty indexes up to
                                    * max_index which can cause memory issues and because
                                    * the keys, which may be relevant, will be remapped
                                    * otherwise.
                                    *
                                    * As per the ECMA and JSON specification an object may
                                    * have any string as a property. Unfortunately due to
                                    * a hole in the ECMA specification if the key is a
                                    * ECMA reserved word or starts with a digit the
                                    * parameter is only accessible using ECMAScript's
                                    * bracket notation.
                                    */

                                    // treat as a JSON object
                                    if (is_array($var) && count($var) && (array_keys($var) !== range(0, sizeof($var) - 1))) {
                                            $properties = array_map(array($this, 'name_value'),
                                                                                            array_keys($var),
                                                                                            array_values($var));

                                            foreach($properties as $property) {
                                                    if(Services_JSON::isError($property)) {
                                                            return $property;
                                                    }
                                            }

                                            return '{' . join(',', $properties) . '}';
                                    }

                                    // treat it like a regular array
                                    $elements = array_map(array($this, 'encode'), $var);

                                    foreach($elements as $element) {
                                            if(Services_JSON::isError($element)) {
                                                    return $element;
                                            }
                                    }

                                    return '[' . join(',', $elements) . ']';

                            case 'object':
                                    $vars = get_object_vars($var);

                                    $properties = array_map(array($this, 'name_value'),
                                                                                    array_keys($vars),
                                                                                    array_values($vars));

                                    foreach($properties as $property) {
                                            if(Services_JSON::isError($property)) {
                                                    return $property;
                                            }
                                    }

                                    return '{' . join(',', $properties) . '}';

                            default:
                                    return ($this->use & SERVICES_JSON_SUPPRESS_ERRORS)
                                            ? 'null'
                                            : new Services_JSON_Error(gettype($var)." can not be encoded as JSON string");
                    }
            }

        /**
            * array-walking function for use in generating JSON-formatted name-value pairs
            *
            * @param    string  $name   name of key to use
            * @param    mixed   $value  reference to an array element to be encoded
            *
            * @return   string  JSON-formatted name-value pair, like '"name":value'
            * @access   private
            */
            function name_value($name, $value)
            {
                    $encoded_value = $this->encode($value);

                    if(Services_JSON::isError($encoded_value)) {
                            return $encoded_value;
                    }

                    return $this->encode(strval($name)) . ':' . $encoded_value;
            }

        /**
            * reduce a string by removing leading and trailing comments and whitespace
            *
            * @param    $str    string      string value to strip of comments and whitespace
            *
            * @return   string  string value stripped of comments and whitespace
            * @access   private
            */
            function reduce_string($str)
            {
                    $str = preg_replace(array(

                                    // eliminate single line comments in '// ...' form
                                    '#^\s*//(.+)$#m',

                                    // eliminate multi-line comments in '/* ... */' form, at start of string
                                    '#^\s*/\*(.+)\*/#Us',

                                    // eliminate multi-line comments in '/* ... */' form, at end of string
                                    '#/\*(.+)\*/\s*$#Us'

                            ), '', $str);

                    // eliminate extraneous space
                    return trim($str);
            }

        /**
            * decodes a JSON string into appropriate variable
            *
            * @param    string  $str    JSON-formatted string
            *
            * @return   mixed   number, boolean, string, array, or object
            *                   corresponding to given JSON input string.
            *                   See argument 1 to Services_JSON() above for object-output behavior.
            *                   Note that decode() always returns strings
            *                   in ASCII or UTF-8 format!
            * @access   public
            */
            function decode($str)
            {
                    $str = $this->reduce_string($str);

                    switch (strtolower($str)) {
                            case 'true':
                                    return true;

                            case 'false':
                                    return false;

                            case 'null':
                                    return null;

                            default:
                                    $m = array();

                                    if (is_numeric($str)) {
                                            // Lookie-loo, it's a number

                                            // This would work on its own, but I'm trying to be
                                            // good about returning integers where appropriate:
                                            // return (float)$str;

                                            // Return float or int, as appropriate
                                            return ((float)$str == (integer)$str)
                                                    ? (integer)$str
                                                    : (float)$str;

                                    } elseif (preg_match('/^("|\').*(\1)$/s', $str, $m) && $m[1] == $m[2]) {
                                            // STRINGS RETURNED IN UTF-8 FORMAT
                                            $delim = substr($str, 0, 1);
                                            $chrs = substr($str, 1, -1);
                                            $utf8 = '';
                                            $strlen_chrs = strlen($chrs);

                                            for ($c = 0; $c < $strlen_chrs; ++$c) {

                                                    $substr_chrs_c_2 = substr($chrs, $c, 2);
                                                    $ord_chrs_c = ord($chrs{$c});

                                                    switch (true) {
                                                            case $substr_chrs_c_2 == '\b':
                                                                    $utf8 .= chr(0x08);
                                                                    ++$c;
                                                                    break;
                                                            case $substr_chrs_c_2 == '\t':
                                                                    $utf8 .= chr(0x09);
                                                                    ++$c;
                                                                    break;
                                                            case $substr_chrs_c_2 == '\n':
                                                                    $utf8 .= chr(0x0A);
                                                                    ++$c;
                                                                    break;
                                                            case $substr_chrs_c_2 == '\f':
                                                                    $utf8 .= chr(0x0C);
                                                                    ++$c;
                                                                    break;
                                                            case $substr_chrs_c_2 == '\r':
                                                                    $utf8 .= chr(0x0D);
                                                                    ++$c;
                                                                    break;

                                                            case $substr_chrs_c_2 == '\\"':
                                                            case $substr_chrs_c_2 == '\\\'':
                                                            case $substr_chrs_c_2 == '\\\\':
                                                            case $substr_chrs_c_2 == '\\/':
                                                                    if (($delim == '"' && $substr_chrs_c_2 != '\\\'') ||
                                                                        ($delim == "'" && $substr_chrs_c_2 != '\\"')) {
                                                                            $utf8 .= $chrs{++$c};
                                                                    }
                                                                    break;

                                                            case preg_match('/\\\u[0-9A-F]{4}/i', substr($chrs, $c, 6)):
                                                                    // single, escaped unicode character
                                                                    $utf16 = chr(hexdec(substr($chrs, ($c + 2), 2)))
                                                                                . chr(hexdec(substr($chrs, ($c + 4), 2)));
                                                                    $utf8 .= $this->utf162utf8($utf16);
                                                                    $c += 5;
                                                                    break;

                                                            case ($ord_chrs_c >= 0x20) && ($ord_chrs_c <= 0x7F):
                                                                    $utf8 .= $chrs{$c};
                                                                    break;

                                                            case ($ord_chrs_c & 0xE0) == 0xC0:
                                                                    // characters U-00000080 - U-000007FF, mask 110XXXXX
                                                                    //see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                                                    $utf8 .= substr($chrs, $c, 2);
                                                                    ++$c;
                                                                    break;

                                                            case ($ord_chrs_c & 0xF0) == 0xE0:
                                                                    // characters U-00000800 - U-0000FFFF, mask 1110XXXX
                                                                    // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                                                    $utf8 .= substr($chrs, $c, 3);
                                                                    $c += 2;
                                                                    break;

                                                            case ($ord_chrs_c & 0xF8) == 0xF0:
                                                                    // characters U-00010000 - U-001FFFFF, mask 11110XXX
                                                                    // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                                                    $utf8 .= substr($chrs, $c, 4);
                                                                    $c += 3;
                                                                    break;

                                                            case ($ord_chrs_c & 0xFC) == 0xF8:
                                                                    // characters U-00200000 - U-03FFFFFF, mask 111110XX
                                                                    // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                                                    $utf8 .= substr($chrs, $c, 5);
                                                                    $c += 4;
                                                                    break;

                                                            case ($ord_chrs_c & 0xFE) == 0xFC:
                                                                    // characters U-04000000 - U-7FFFFFFF, mask 1111110X
                                                                    // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                                                    $utf8 .= substr($chrs, $c, 6);
                                                                    $c += 5;
                                                                    break;

                                                    }

                                            }

                                            return $utf8;

                                    } elseif (preg_match('/^\[.*\]$/s', $str) || preg_match('/^\{.*\}$/s', $str)) {
                                            // array, or object notation

                                            if ($str{0} == '[') {
                                                    $stk = array(SERVICES_JSON_IN_ARR);
                                                    $arr = array();
                                            } else {
                                                    if ($this->use & SERVICES_JSON_LOOSE_TYPE) {
                                                            $stk = array(SERVICES_JSON_IN_OBJ);
                                                            $obj = array();
                                                    } else {
                                                            $stk = array(SERVICES_JSON_IN_OBJ);
                                                            $obj = new stdClass();
                                                    }
                                            }

                                            array_push($stk, array('what'  => SERVICES_JSON_SLICE,
                                                                                        'where' => 0,
                                                                                        'delim' => false));

                                            $chrs = substr($str, 1, -1);
                                            $chrs = $this->reduce_string($chrs);

                                            if ($chrs == '') {
                                                    if (reset($stk) == SERVICES_JSON_IN_ARR) {
                                                            return $arr;

                                                    } else {
                                                            return $obj;

                                                    }
                                            }

                                            //print("\nparsing {$chrs}\n");

                                            $strlen_chrs = strlen($chrs);

                                            for ($c = 0; $c <= $strlen_chrs; ++$c) {

                                                    $top = end($stk);
                                                    $substr_chrs_c_2 = substr($chrs, $c, 2);

                                                    if (($c == $strlen_chrs) || (($chrs{$c} == ',') && ($top['what'] == SERVICES_JSON_SLICE))) {
                                                            // found a comma that is not inside a string, array, etc.,
                                                            // OR we've reached the end of the character list
                                                            $slice = substr($chrs, $top['where'], ($c - $top['where']));
                                                            array_push($stk, array('what' => SERVICES_JSON_SLICE, 'where' => ($c + 1), 'delim' => false));
                                                            //print("Found split at {$c}: ".substr($chrs, $top['where'], (1 + $c - $top['where']))."\n");

                                                            if (reset($stk) == SERVICES_JSON_IN_ARR) {
                                                                    // we are in an array, so just push an element onto the stack
                                                                    array_push($arr, $this->decode($slice));

                                                            } elseif (reset($stk) == SERVICES_JSON_IN_OBJ) {
                                                                    // we are in an object, so figure
                                                                    // out the property name and set an
                                                                    // element in an associative array,
                                                                    // for now
                                                                    $parts = array();
                                                                    
                                                                    if (preg_match('/^\s*(["\'].*[^\\\]["\'])\s*:\s*(\S.*),?$/Uis', $slice, $parts)) {
                                                                            // "name":value pair
                                                                            $key = $this->decode($parts[1]);
                                                                            $val = $this->decode($parts[2]);

                                                                            if ($this->use & SERVICES_JSON_LOOSE_TYPE) {
                                                                                    $obj[$key] = $val;
                                                                            } else {
                                                                                    $obj->$key = $val;
                                                                            }
                                                                    } elseif (preg_match('/^\s*(\w+)\s*:\s*(\S.*),?$/Uis', $slice, $parts)) {
                                                                            // name:value pair, where name is unquoted
                                                                            $key = $parts[1];
                                                                            $val = $this->decode($parts[2]);

                                                                            if ($this->use & SERVICES_JSON_LOOSE_TYPE) {
                                                                                    $obj[$key] = $val;
                                                                            } else {
                                                                                    $obj->$key = $val;
                                                                            }
                                                                    }

                                                            }

                                                    } elseif ((($chrs{$c} == '"') || ($chrs{$c} == "'")) && ($top['what'] != SERVICES_JSON_IN_STR)) {
                                                            // found a quote, and we are not inside a string
                                                            array_push($stk, array('what' => SERVICES_JSON_IN_STR, 'where' => $c, 'delim' => $chrs{$c}));
                                                            //print("Found start of string at {$c}\n");

                                                    } elseif (($chrs{$c} == $top['delim']) &&
                                                                    ($top['what'] == SERVICES_JSON_IN_STR) &&
                                                                    ((strlen(substr($chrs, 0, $c)) - strlen(rtrim(substr($chrs, 0, $c), '\\'))) % 2 != 1)) {
                                                            // found a quote, we're in a string, and it's not escaped
                                                            // we know that it's not escaped becase there is _not_ an
                                                            // odd number of backslashes at the end of the string so far
                                                            array_pop($stk);
                                                            //print("Found end of string at {$c}: ".substr($chrs, $top['where'], (1 + 1 + $c - $top['where']))."\n");

                                                    } elseif (($chrs{$c} == '[') &&
                                                                    in_array($top['what'], array(SERVICES_JSON_SLICE, SERVICES_JSON_IN_ARR, SERVICES_JSON_IN_OBJ))) {
                                                            // found a left-bracket, and we are in an array, object, or slice
                                                            array_push($stk, array('what' => SERVICES_JSON_IN_ARR, 'where' => $c, 'delim' => false));
                                                            //print("Found start of array at {$c}\n");

                                                    } elseif (($chrs{$c} == ']') && ($top['what'] == SERVICES_JSON_IN_ARR)) {
                                                            // found a right-bracket, and we're in an array
                                                            array_pop($stk);
                                                            //print("Found end of array at {$c}: ".substr($chrs, $top['where'], (1 + $c - $top['where']))."\n");

                                                    } elseif (($chrs{$c} == '{') &&
                                                                    in_array($top['what'], array(SERVICES_JSON_SLICE, SERVICES_JSON_IN_ARR, SERVICES_JSON_IN_OBJ))) {
                                                            // found a left-brace, and we are in an array, object, or slice
                                                            array_push($stk, array('what' => SERVICES_JSON_IN_OBJ, 'where' => $c, 'delim' => false));
                                                            //print("Found start of object at {$c}\n");

                                                    } elseif (($chrs{$c} == '}') && ($top['what'] == SERVICES_JSON_IN_OBJ)) {
                                                            // found a right-brace, and we're in an object
                                                            array_pop($stk);
                                                            //print("Found end of object at {$c}: ".substr($chrs, $top['where'], (1 + $c - $top['where']))."\n");

                                                    } elseif (($substr_chrs_c_2 == '/*') &&
                                                                    in_array($top['what'], array(SERVICES_JSON_SLICE, SERVICES_JSON_IN_ARR, SERVICES_JSON_IN_OBJ))) {
                                                            // found a comment start, and we are in an array, object, or slice
                                                            array_push($stk, array('what' => SERVICES_JSON_IN_CMT, 'where' => $c, 'delim' => false));
                                                            $c++;
                                                            //print("Found start of comment at {$c}\n");

                                                    } elseif (($substr_chrs_c_2 == '*/') && ($top['what'] == SERVICES_JSON_IN_CMT)) {
                                                            // found a comment end, and we're in one now
                                                            array_pop($stk);
                                                            $c++;

                                                            for ($i = $top['where']; $i <= $c; ++$i)
                                                                    $chrs = substr_replace($chrs, ' ', $i, 1);

                                                            //print("Found end of comment at {$c}: ".substr($chrs, $top['where'], (1 + $c - $top['where']))."\n");

                                                    }

                                            }

                                            if (reset($stk) == SERVICES_JSON_IN_ARR) {
                                                    return $arr;

                                            } elseif (reset($stk) == SERVICES_JSON_IN_OBJ) {
                                                    return $obj;

                                            }

                                    }
                    }
            }

            /**
            * @todo Ultimately, this should just call PEAR::isError()
            */
            function isError($data, $code = null)
            {
                    if (class_exists('pear')) {
                            return PEAR::isError($data, $code);
                    } elseif (is_object($data) && (get_class($data) == 'services_json_error' ||
                                                                    is_subclass_of($data, 'services_json_error'))) {
                            return true;
                    }

                    return false;
            }
    }

    if (class_exists('PEAR_Error')) {

            class Services_JSON_Error extends PEAR_Error
            {
                    function Services_JSON_Error($message = 'unknown error', $code = null,
                                                                            $mode = null, $options = null, $userinfo = null)
                    {
                            parent::PEAR_Error($message, $code, $mode, $options, $userinfo);
                    }
            }

    } else {

            /**
            * @todo Ultimately, this class shall be descended from PEAR_Error
            */
            class Services_JSON_Error
            {
                    function Services_JSON_Error($message = 'unknown error', $code = null,
                                                                            $mode = null, $options = null, $userinfo = null)
                    {

                    }
            }

    }

            function json_decode($content, $assoc=false){
                    if ( $assoc ){
                            $json = new Services_JSON(SERVICES_JSON_LOOSE_TYPE);
                    } else {
                            $json = new Services_JSON;
                    }
                    return $json->decode($content);
            }
    }
}

if(!class_exists('PEAR_Error')) {
    /**
     * Standard PEAR error class for PHP 4
     *
     * This class is supserseded by {@link PEAR_Exception} in PHP 5
     *
     * @category   pear
     * @package    PEAR
     * @author     Stig Bakken <ssb@php.net>
     * @author     Tomas V.V. Cox <cox@idecnet.com>
     * @author     Gregory Beaver <cellog@php.net>
     * @copyright  1997-2006 The PHP Group
     * @license    http://www.php.net/license/3_0.txt  PHP License 3.0
     * @version    Release: 1.7.2
     * @link       http://pear.php.net/manual/en/core.pear.pear-error.php
     * @see        PEAR::raiseError(), PEAR::throwError()
     * @since      Class available since PHP 4.0.2
     */
    class PEAR_Error {
        var $error_message_prefix = '';
        var $mode                 = PEAR_ERROR_RETURN;
        var $level                = E_USER_NOTICE;
        var $code                 = -1;
        var $message              = '';
        var $userinfo             = '';
        var $backtrace            = null;
        
        /**
         * PEAR_Error constructor
         *
         * @param string $message  message
         *
         * @param int $code     (optional) error code
         *
         * @param int $mode     (optional) error mode, one of: PEAR_ERROR_RETURN,
         *  PEAR_ERROR_PRINT, PEAR_ERROR_DIE, PEAR_ERROR_TRIGGER,
         *  PEAR_ERROR_CALLBACK or PEAR_ERROR_EXCEPTION
         *
         * @param mixed $options   (optional) error level, _OR_ in the case of
         *  PEAR_ERROR_CALLBACK, the callback function or object/method
         *  tuple.
         *
         * @param string $userinfo (optional) additional user/debug info
         *
         * @access public
         *
         */
        function PEAR_Error($message = 'unknown error', $code = null,
                            $mode = null, $options = null, $userinfo = null) {
            if ($mode === null) {
                $mode = PEAR_ERROR_RETURN;
            }
            $this->message   = $message;
            $this->code      = $code;
            $this->mode      = $mode;
            $this->userinfo  = $userinfo;
            if (!PEAR::getStaticProperty('PEAR_Error', 'skiptrace')) {
                $this->backtrace = debug_backtrace();
                if (isset($this->backtrace[0]) && isset($this->backtrace[0]['object'])) {
                    unset($this->backtrace[0]['object']);
                }
            }
            if ($mode & PEAR_ERROR_CALLBACK) {
                $this->level = E_USER_NOTICE;
                $this->callback = $options;
            } else {
                if ($options === null) {
                    $options = E_USER_NOTICE;
                }
                $this->level = $options;
                $this->callback = null;
            }
            if ($this->mode & PEAR_ERROR_PRINT) {
                if (is_null($options) || is_int($options)) {
                    $format = "%s";
                } else {
                    $format = $options;
                }
                printf($format, $this->getMessage());
            }
            if ($this->mode & PEAR_ERROR_TRIGGER) {
                trigger_error($this->getMessage(), $this->level);
            }
            if ($this->mode & PEAR_ERROR_DIE) {
                $msg = $this->getMessage();
                if (is_null($options) || is_int($options)) {
                    $format = "%s";
                    if (substr($msg, -1) != "\n") {
                        $msg .= "\n";
                    }
                } else {
                    $format = $options;
                }
                die(sprintf($format, $msg));
            }
            if ($this->mode & PEAR_ERROR_CALLBACK) {
                if (is_callable($this->callback)) {
                    call_user_func($this->callback, $this);
                }
            }
            if ($this->mode & PEAR_ERROR_EXCEPTION) {
                trigger_error("PEAR_ERROR_EXCEPTION is obsolete, use class PEAR_Exception for exceptions", E_USER_WARNING);
                eval('$e = new Exception($this->message, $this->code);throw($e);');
            }
        }
     
        // }}}
        // {{{ getMode()
     
        /**
         * Get the error mode from an error object.
         *
         * @return int error mode
         * @access public
         */
        function getMode() {
            return $this->mode;
        }
     
        // }}}
        // {{{ getCallback()
     
        /**
         * Get the callback function/method from an error object.
         *
         * @return mixed callback function or object/method array
         * @access public
         */
        function getCallback() {
            return $this->callback;
        }
     
        // }}}
        // {{{ getMessage()
     
     
        /**
         * Get the error message from an error object.
         *
         * @return  string  full error message
         * @access public
         */
        function getMessage()
        {
            return ($this->error_message_prefix . $this->message);
        }
     
     
        // }}}
        // {{{ getCode()
     
        /**
         * Get error code from an error object
         *
         * @return int error code
         * @access public
         */
         function getCode()
         {
            return $this->code;
         }
     
        // }}}
        // {{{ getType()
     
        /**
         * Get the name of this error/exception.
         *
         * @return string error/exception name (type)
         * @access public
         */
        function getType()
        {
            return get_class($this);
        }
     
        // }}}
        // {{{ getUserInfo()
     
        /**
         * Get additional user-supplied information.
         *
         * @return string user-supplied information
         * @access public
         */
        function getUserInfo()
        {
            return $this->userinfo;
        }
     
        // }}}
        // {{{ getDebugInfo()
     
        /**
         * Get additional debug information supplied by the application.
         *
         * @return string debug information
         * @access public
         */
        function getDebugInfo()
        {
            return $this->getUserInfo();
        }
     
        // }}}
        // {{{ getBacktrace()
     
        /**
         * Get the call backtrace from where the error was generated.
         * Supported with PHP 4.3.0 or newer.
         *
         * @param int $frame (optional) what frame to fetch
         * @return array Backtrace, or NULL if not available.
         * @access public
         */
        function getBacktrace($frame = null)
        {
            if (defined('PEAR_IGNORE_BACKTRACE')) {
                return null;
            }
            if ($frame === null) {
                return $this->backtrace;
            }
            return $this->backtrace[$frame];
        }
     
        // }}}
        // {{{ addUserInfo()
     
        function addUserInfo($info)
        {
            if (empty($this->userinfo)) {
                $this->userinfo = $info;
            } else {
                $this->userinfo .= " ** $info";
            }
        }
     
        // }}}
        // {{{ toString()
        function __toString()
        {
            return $this->getMessage();
        }
        // }}}
        // {{{ toString()
     
        /**
         * Make a string representation of this object.
         *
         * @return string a string with an object summary
         * @access public
         */
        function toString() {
            $modes = array();
            $levels = array(E_USER_NOTICE  => 'notice',
                            E_USER_WARNING => 'warning',
                            E_USER_ERROR   => 'error');
            if ($this->mode & PEAR_ERROR_CALLBACK) {
                if (is_array($this->callback)) {
                    $callback = (is_object($this->callback[0]) ?
                        strtolower(get_class($this->callback[0])) :
                        $this->callback[0]) . '::' .
                        $this->callback[1];
                } else {
                    $callback = $this->callback;
                }
                return sprintf('[%s: message="%s" code=%d mode=callback '.
                               'callback=%s prefix="%s" info="%s"]',
                               strtolower(get_class($this)), $this->message, $this->code,
                               $callback, $this->error_message_prefix,
                               $this->userinfo);
            }
            if ($this->mode & PEAR_ERROR_PRINT) {
                $modes[] = 'print';
            }
            if ($this->mode & PEAR_ERROR_TRIGGER) {
                $modes[] = 'trigger';
            }
            if ($this->mode & PEAR_ERROR_DIE) {
                $modes[] = 'die';
            }
            if ($this->mode & PEAR_ERROR_RETURN) {
                $modes[] = 'return';
            }
            return sprintf('[%s: message="%s" code=%d mode=%s level=%s '.
                           'prefix="%s" info="%s"]',
                           strtolower(get_class($this)), $this->message, $this->code,
                           implode("|", $modes), $levels[$this->level],
                           $this->error_message_prefix,
                           $this->userinfo);
        }
    }
}
?>
