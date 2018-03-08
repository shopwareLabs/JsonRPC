<?php

namespace JsonRPC;

use Closure;
use JsonRPC\Exception\AccessDeniedException;
use JsonRPC\Exception\ConnectionFailureException;
use JsonRPC\Exception\ServerErrorException;

/**
 * Class HttpClient
 *
 * @package JsonRPC
 * @author  Frederic Guillot
 */
class HttpClient
{
    /**
     * URL of the server
     *
     * @access private
     * @var string
     */
    private $url;

    /**
     * HTTP client timeout
     *
     * @access private
     * @var integer
     */
    private $timeout = 5;

    /**
     * Default HTTP headers to send to the server
     *
     * @access private
     * @var array
     */
    private $headers = array(
        'User-Agent: JSON-RPC PHP Client <https://github.com/fguillot/JsonRPC>',
        'Content-Type: application/json',
        'Accept: application/json',
        'Connection: close',
    );

    /**
     * Username for authentication
     *
     * @access private
     * @var string
     */
    private $username;

    /**
     * Password for authentication
     *
     * @access private
     * @var string
     */
    private $password;

    /**
     * Enable debug output to the php error log
     *
     * @access private
     * @var boolean
     */
    private $debug = false;

    /**
     * Cookies
     *
     * @access private
     * @var array
     */
    private $cookies = array();

    /**
     * SSL certificates verification
     *
     * @access private
     * @var boolean
     */
    private $verifySslCertificate = true;

    /**
     * SSL client certificate
     *
     * @access private
     * @var string
     */
    private $sslLocalCert;

    /**
     * Callback called before the doing the request
     *
     * @access private
     * @var Closure
     */
    private $beforeRequest;

    /**
     * HttpClient constructor
     *
     * @access public
     * @param  string $url
     */
    public function __construct($url = '')
    {
        $this->url = $url;
    }

    /**
     * Set URL
     *
     * @access public
     * @param  string $url
     * @return $this
     */
    public function withUrl($url)
    {
        $this->url = $url;
        return $this;
    }

    /**
     * Set username
     *
     * @access public
     * @param  string $username
     * @return $this
     */
    public function withUsername($username)
    {
        $this->username = $username;
        return $this;
    }

    /**
     * Set password
     *
     * @access public
     * @param  string $password
     * @return $this
     */
    public function withPassword($password)
    {
        $this->password = $password;
        return $this;
    }

    /**
     * Set timeout
     *
     * @access public
     * @param  integer $timeout
     * @return $this
     */
    public function withTimeout($timeout)
    {
        $this->timeout = $timeout;
        return $this;
    }

    /**
     * Set headers
     *
     * @access public
     * @param  array $headers
     * @return $this
     */
    public function withHeaders(array $headers)
    {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }

    /**
     * Set cookies
     *
     * @access public
     * @param  array     $cookies
     * @param  boolean   $replace
     */
    public function withCookies(array $cookies, $replace = false)
    {
        if ($replace) {
            $this->cookies = $cookies;
        } else {
            $this->cookies = array_merge($this->cookies, $cookies);
        }
    }

    /**
     * Enable debug mode
     *
     * @access public
     * @return $this
     */
    public function withDebug()
    {
        $this->debug = true;
        return $this;
    }

    /**
     * Disable SSL verification
     *
     * @access public
     * @return $this
     */
    public function withoutSslVerification()
    {
        $this->verifySslCertificate = false;
        return $this;
    }

    /**
     * Assign a certificate to use TLS
     *
     * @access public
     * @return $this
     */
    public function withSslLocalCert($path)
    {
        $this->sslLocalCert = $path;
        return $this;
    }

    /**
     * Assign a callback before the request
     *
     * @access public
     * @param  Closure $closure
     * @return $this
     */
    public function withBeforeRequestCallback(Closure $closure)
    {
        $this->beforeRequest = $closure;
        return $this;
    }

    /**
     * Get cookies
     *
     * @access public
     * @return array
     */
    public function getCookies()
    {
        return $this->cookies;
    }

    /**
     * Do the HTTP request
     *
     * @access public
     * @throws ConnectionFailureException
     * @param  string   $payload
     * @param  string[] $headers Headers for this request
     * @return array
     */
    public function execute($payload, array $headers = array())
    {
        if (is_callable($this->beforeRequest)) {
            call_user_func_array($this->beforeRequest, array($this, $payload, $headers));
        }

        $curlHandler = curl_init(trim($this->url));

        $this->configureCurl($payload, $headers, $curlHandler);

        $response = curl_exec($curlHandler);

        if ($error = curl_error($curlHandler) !== "") {
            throw new ConnectionFailureException('CurlError: ' . $error);
        }

        $header_size = curl_getinfo($curlHandler,CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $header_size);
        $headers = array_map("trim", explode("\n", $headers));

        $body = json_decode(substr($response, $header_size), true);

        curl_close($curlHandler);

        if ($this->debug) {
            error_log('==> Request: '.PHP_EOL.(is_string($payload) ? $payload : json_encode($payload, JSON_PRETTY_PRINT)));
            error_log('==> Headers: '.PHP_EOL.var_export($headers, true));
            error_log('==> Response: '.PHP_EOL.json_encode($response, JSON_PRETTY_PRINT));
        }

        $this->handleExceptions($headers);
        $this->parseCookies($headers);

        return $body;
    }


    /**
     * Parse cookies from response
     *
     * @access private
     * @param  array $headers
     */
    private function parseCookies(array $headers)
    {
        foreach ($headers as $header) {
            $pos = stripos($header, 'Set-Cookie:');

            if ($pos !== false) {
                $cookies = explode(';', substr($header, $pos + 11));

                foreach ($cookies as $cookie) {
                    $item = explode('=', $cookie);

                    if (count($item) === 2) {
                        $name = trim($item[0]);
                        $value = $item[1];
                        $this->cookies[$name] = $value;
                    }
                }
            }
        }
    }

    /**
     * Throw an exception according the HTTP response
     *
     * @access public
     * @param  array   $headers
     * @throws AccessDeniedException
     * @throws ServerErrorException
     */
    public function handleExceptions(array $headers)
    {
        $exceptions = array(
            '401' => '\JsonRPC\Exception\AccessDeniedException',
            '403' => '\JsonRPC\Exception\AccessDeniedException',
            '404' => '\JsonRPC\Exception\ConnectionFailureException',
            '500' => '\JsonRPC\Exception\ServerErrorException',
        );

        foreach ($headers as $header) {
            foreach ($exceptions as $code => $exception) {
                if (strpos($header, 'HTTP/1.0 '.$code) !== false || strpos($header, 'HTTP/1.1 '.$code) !== false) {
                    throw new $exception('Response: '.$header);
                }
            }
        }
    }

    /**
     * @param string $payload
     * @param array $headers
     * @param $curlHandler
     */
    private function configureCurl($payload, array $headers, $curlHandler)
    {
        if (!empty($this->username) && !empty($this->password)) {
            curl_setopt($curlHandler, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($curlHandler, CURLOPT_USERNAME, $this->username);
            curl_setopt($curlHandler, CURLOPT_USERPWD, $this->password);
        }

        if (!empty($this->cookies)) {
            $cookies = array();

            foreach ($this->cookies as $key => $value) {
                $cookies[] = $key . '=' . $value;
            }

            curl_setopt($curlHandler, CURLOPT_COOKIE, implode('; ', $cookies));
        }

        curl_setopt($curlHandler, CURLOPT_POST, 1);
        curl_setopt($curlHandler, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curlHandler, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($curlHandler, CURLOPT_HEADER, 1);
        curl_setopt($curlHandler, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curlHandler, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($curlHandler, CURLOPT_MAXREDIRS, 2);
    }
}
