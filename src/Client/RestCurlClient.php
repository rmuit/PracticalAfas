<?php
/**
 * This file is part of the PracticalAfas package.
 *
 * (c) Roderik Muit <rm@wyz.biz>
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace PracticalAfas\Client;

use InvalidArgumentException;
use RuntimeException;

/**
 * Client for getting/sending data from/to AFAS, using REST API through CURL.
 *
 * This class takes care of authentication / connection details but has no
 * logic around interpreting any results. On any error, an exception is thrown.
 *
 * It has no official interface. It contains two public methods:
 * - getClientType(): a static method which may be needed when not using this
 *   class standalone.
 * - callAfas(): the only method needed in order to make calls to AFAS. The
 *   arguments and return value may differ depending on the client type.
 */
class RestCurlClient
{

    public static function getClientType()
    {
        return 'REST';
    }

    /**
     * Configuration options.
     *
     * @var array
     */
    protected $options;

    /**
     * Options to set for Curl.
     *
     * @var array
     */
    protected $curlOptions;

    /**
     * HTTP headers which are disallowed, or seen in constructor options.
     *
     * Header names, lower case, in the array keys. Disallowed headers are
     * defined here; these will cause an exception to be thrown if seen while
     * parsing headers. This array is added to, so duplicate headers are flagged
     * and after parsing, other code can later check which headers are used.
     *
     * Note that HTTP headers are parsed once (in the constructor) and cannot be
     * influenced afterwards (except by re-parsing the headers text in a
     * subclass' callAfas()). This means that if AFAS changes in unexpected
     * ways, like e.g. requiring a Content-Type header to be set for one
     * specific request, then this request should be made through a separate,
     * newly instantiated object.
     *
     * @var array
     */
    protected $headersSeenOrDisallowed = [
        'content-length' => false,
        'transfer-encoding' => false,
    ];

    /**
     * Constructor.
     *
     * @param array $options
     *   Configuration options. (Case sensitive.)
     *   Required:
     *   - customerId:      Customer ID, as used in the AFAS endpoint URL.
     *   - appToken:        Token used for the App connector.
     *   Optional:
     *   - environment:     Which AFAS environment to connect to. Can be 'test'
     *                      or 'accept'; if not specified, the client connects
     *                      to the live environment.
     *   - headers:         HTTP headers to pass to Curl: an array of key-value
     *                      pairs in the form of ['User-Agent' => 'Me', ...].
     * @param array $curl_options
     *   Options to pass to Curl (besides the HTTP headers), keyed by CURLOPT_
     *   constants. Some are overridden / not possible to set through here.
     *
     * @throws \InvalidArgumentException
     *   If some option values are missing / incorrect.
     * @throws \RuntimeException
     *   If the AFAS connection is known to fail.
     */
    public function __construct(array $options, $curl_options = [])
    {
        foreach (['customerId', 'appToken'] as $required_key) {
            if (empty($options[$required_key])) {
                $classname = get_class($this);
                throw new InvalidArgumentException("Required configuration parameter for $classname missing: $required_key.", 1);
            }
        }

        // For v1.0 compatibility:
        if (empty($curl_options) && isset($options['curlOptions']) && is_array($options['curlOptions'])) {
            $this->curlOptions = $options['curlOptions'];
        } else {
            $this->curlOptions = $curl_options;
        }

        $options += ['headers' => []];
        if (!is_array($options['headers'])) {
            $classname = get_class($this);
            throw new InvalidArgumentException("Non-array 'headers' option passed to $classname constructor.", 2);
        }
        // Determine default headers with names not present in the 'headers'
        // option (case insensitive comparison).
        $default_headers = array_diff_ukey([
            'User-Agent' => 'PHP Curl/PracticalAfas',
            'Authorization' => 'AfasToken ' . base64_encode('<token><version>1</version><data>' . $options['appToken'] . '</data></token>')
        ], $options['headers'], 'strcasecmp');
        // Sanitize/set HTTPHEADER Curl option.
        $this->curlOptions[CURLOPT_HTTPHEADER] = $this->httpHeaders($options['headers'] + $default_headers);

        // From ~november 2018, AFAS has a new endpoint that forces TLS 1.2 as
        // a minimum. We know how to force a specific TLS version but
        // apparently cannot specify '1.2 or higher'. If people want TLS 1.3 or
        // higher, they will have to pass CURLOPT_SSLVERSION in curlOptions.
        if (!isset($this->curlOptions[CURLOPT_SSLVERSION])) {
            if (!defined('CURL_SSLVERSION_TLSv1_2')) {
                throw new RuntimeException("PHP's Curl extension does not support TLS v1.2, which AFAS requires.");
            }
            $this->curlOptions[CURLOPT_SSLVERSION] = CURL_SSLVERSION_TLSv1_2;
        }

        // We will not use 'headers' and 'appToken' in our own code (because
        // they are contained in 'curlOptions'), but won't clean them out.
        $this->options = $options;
    }

    /**
     * Construct HTTP header lines.
     *
     * @param array $headers
     *   Header name-value pairs in the form of ['User-Agent' => 'Me', ...]
     *
     * @return array
     *   Header lines in the form of ['User-Agent: Me', ...]
     *
     * @throws \InvalidArgumentException
     *  For disallowed header fields/values.
     */
    protected function httpHeaders(array $headers) {
        // The spec for what is allowed (rfc7230) is not extremely detailed:
        // - field names MUST not have spaces before the colon; servers MUST
        //   reject such messages.
        // - field values SHOULD contain only ASCII. (What to do with non-ASCII
        //   is not specified.)
        // We have the option of:
        // - passing through without checking: no.
        // - filtering invalid characters only: considered potentially unsafe.
        // - encoding: possible, but it is unlikely that the server does
        //   anything useful with it and 'escape sequences' cannot be sent in a
        //   non-ambiguous way.
        // - throw exception when invalid characters are encountered.
        //   We'll do the last thing.
        $header_lines = [];

        foreach ($headers as $name => $value) {
            $lower_name = strtolower($name);
            if (isset($this->headersSeenOrDisallowed[$lower_name])) {
                throw new InvalidArgumentException("Duplicate or disallowed HTTP header name '$name'.", 2);
            }
            $this->headersSeenOrDisallowed[$lower_name] = true;

            // Check for non-ascii characters.
            if (strpos($name, ' ') !== false || strpos($name, ':') !== false || preg_match('/[^\x20-\x7f]/', $name)) {
                throw new InvalidArgumentException("Disallowed HTTP header name '$name'.", 2);
            }
            if (preg_match('/[^\x20-\x7f]/', $value)) {
                throw new InvalidArgumentException("Disallowed HTTP '$name' header value '$value'.", 2);
            }
            $header_lines[] = "$name: $value";
        }

        return $header_lines;
    }

    /**
     * Validates arguments for an AFAS REST API call.
     *
     * Split out from callAfas() for more convenient subclassing.
     *
     * This class is not meant to make decisions about any actual data sent.
     * (That kind of code would belong in Connection.) So while we can
     * validate many arguments here, setting/changing them is discouraged.
     *
     * @param array $arguments
     *   Named URL arguments. All argument names must be lower case; all values
     *   must be scalars.
     * @param string $endpoint
     *   The REST API endpoint URL.
     * @param string $type
     *   HTTP verb: GET, PUT, POST, DELETE. Must be upper case.
     * @param string $request_body
     *   Request body to send for POST/PUT requests.
     *
     * @return array
     *   The arguments, possibly changed.
     *
     * @throws \InvalidArgumentException
     *   For invalid function arguments.
     */
    protected function validateArguments($arguments, $endpoint, $type, $request_body)
    {
        if (!in_array($type, ['GET', 'PUT', 'POST', 'DELETE'], true)) {
            throw new InvalidArgumentException("Invalid HTTP verb '$type''", 40);
        }
        // Be strict in accepting $type vs $request_body; we can always relax
        // things later. (For now this makes it easier to know what to do with
        // which CURL options.)
        if ($type !== 'GET') {
            if (!$request_body) {
                throw new InvalidArgumentException("Request body must be provided for $type requests.", 40);
            }
        }
        else {
            if ($request_body) {
                throw new InvalidArgumentException('Request body must not be provided for GET requests.', 40);
            }
            // If 'skip' is -1, 'take' isn't validated at all and the full data
            // set is returned (which can obviously lead to timeouts). A
            // 'skip' that is smaller than -1 or non-numeric is apparently
            // equivalent to 0.
            if (empty($arguments['skip']) || $arguments['skip'] != -1) {
                // A value of 0 would return 1 row (tested May 2017). We
                // disallow that to prevent possible confusion. We also
                // validate other disallowed values rather than have AFAS
                // return an error, because we can do a better job at the error
                // message.
                if (isset($arguments['take']) && (!is_numeric($arguments['take']) || $arguments['take'] <= 0)) {
                    throw new InvalidArgumentException("'take' argument must be a positive number.", 42);
                }
            }
        }

        return $arguments;
    }

    /**
     * Calls a REST API method.
     *
     * @param string $type
     *   HTTP verb: GET, PUT, POST, DELETE.
     * @param string $endpoint
     *   The REST API endpoint URL.
     * @param array $arguments
     *   Named URL arguments. All values must be scalars. Unlike $endpoint, all
     *   names/values will be escaped. (Case of the argument names gets changed;
     *   if there are multiple arguments whose names only differ in case, then
     *   the value that is later in the array will override earlier arguments.)
     * @param string $request_body
     *   (optional) request body to send for POST/PUT requests. Note that in
     *   POST/PUT cases, $arguments is always empty; still, we did not want to
     *   to join the two method arguments, for future extensibility.
     *
     * @return string
     *   The response body from the REST API endpoint.
     *
     * @throws \InvalidArgumentException
     *   For invalid function arguments or unknown connector type.
     * @throws \RuntimeException
     *   If an error was returned by the endpoint, or was encountered while
     *   connecting to the endpoint.
     */
    public function callAfas($type, $endpoint, array $arguments, $request_body = '')
    {
        $type = strtoupper($type);
        // Unify case of arguments, so we don't skip any validation. (If two
        // arguments with different case are in the array, the value that is
        // later in the array will override other indices.)
        $arguments = array_change_key_case($arguments);

        $arguments = $this->validateArguments($arguments, $endpoint, $type, $request_body);

        $env = !empty($this->options['environment']) ? $this->options['environment'] : '';
        // Unlike other input, we don't escape $endpoint (we assume it is safe)
        // because otherwise we can't have slashes in there.
        $endpoint = 'https://' . rawurlencode($this->options['customerId']) . ".rest$env.afas.online/profitrestservices/$endpoint";
        if ($arguments) {
            $params = [];
            foreach ($arguments as $key => $value) {
                if (!is_scalar($value)) {
                    throw new InvalidArgumentException("Invalid query argument '$key' value '$value'.", 41);
                }
                elseif (!isset($value)) {
                    $params[] = rawurlencode($key);
                }
                else {
                    $params[] = rawurlencode($key) . '=' . rawurlencode($value);
                }
            }
            $endpoint .= '?' . implode('&', $params);
        }

        // Curl options that we really need for this particular call/code to
        // work:
        $forced_options = [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
        ];
        if ($type !== 'GET') {
            $forced_options[CURLOPT_CUSTOMREQUEST] = $type;
            $forced_options[CURLOPT_POSTFIELDS] = $request_body;
            // All our content so far is JSON, so it seems good to specify a
            // Content-Type including character set.
            if (!isset($this->headersSeenOrDisallowed['content-type'])) {
                $this->curlOptions[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json; charset="utf-8"';
            }
            // We will not set Content-Length because it seems to potentially
            // complicate matters (w.r.t. multi-byte strings) and does not
            // appear necessary for servers that accept HTTP/1.1.
        }

        $ch = curl_init();
        curl_setopt_array($ch, $forced_options + $this->curlOptions);
        $response = curl_exec($ch);
        $response_headers = '';
        if ($response !== false) {
            list($response_headers, $response) = explode("\r\n\r\n", $response);
        }
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_errno = curl_errno($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);
        if ($curl_errno) {
            // Body is likely empty but we'll still log it. Since our Connection
            // uses low error codes, add 800 to the thrown code, in case the
            // caller cares about distinguishing them.
            throw new RuntimeException("CURL returned code: $curl_errno / error: \"$curl_error\" / response body: \"$response\"", $curl_errno + 800);
        }
        // We'll start out strict, and cancel on all unexpected return codes.
        if ($http_code != 200 && ($http_code != 201 || !in_array($type, ['POST', 'PUT'], true))) {
            // For e.g. code 500, we've seen a message in the response (at least
            // when we entered an invalid URL). For 401 (Unauthorized. when we
            // did not specify a token) the response is empty but headers
            // indicate that something is wrong. We'll separate body & headers
            // in an arbitrary way that hopefully looks somewhat clear in most
            // 'outputs' of the exception message.
            throw new RuntimeException("CURL returned HTTP code $http_code / Response body: \"$response\"//\nResponse headers: \"$response_headers\"", $http_code);
        }

        return $response;
    }
}
