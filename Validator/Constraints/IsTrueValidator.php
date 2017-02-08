<?php

namespace EWZ\Bundle\RecaptchaBundle\Validator\Constraints;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\ValidatorException;

class IsTrueValidator extends ConstraintValidator
{
    /**
     * Enable recaptcha?
     *
     * @var Boolean
     */
    protected $enabled;

    /**
     * Recaptcha Private Key
     *
     * @var Boolean
     */
    protected $privateKey;

    /**
     * Request Stack
     *
     * @var RequestStack
     */
    protected $requestStack;

    /**
     * HTTP Proxy informations
     * @var Array
     */
    protected $httpProxy;

    /**
     * Enable serverside host check
     *
     * @var Boolean
     */
    protected $verifyHost;

    /**
     * The reCAPTCHA server URL's
     */
    const RECAPTCHA_VERIFY_SERVER = 'https://www.google.com';

    /**
     * Construct.
     *
     * @param Boolean      $enabled
     * @param string       $privateKey
     * @param RequestStack $requestStack
     * @param array        $httpProxy
     * @param Boolean      $verifyHost
     */
    public function __construct($enabled, $privateKey, RequestStack $requestStack, array $httpProxy, $verifyHost)
    {
        $this->enabled      = $enabled;
        $this->privateKey   = $privateKey;
        $this->requestStack = $requestStack;
        $this->httpProxy    = $httpProxy;
        $this->verifyHost   = $verifyHost;
    }

    /**
     * {@inheritdoc}
     */
    public function validate($value, Constraint $constraint)
    {
        // if recaptcha is disabled, always valid
        if (!$this->enabled) {
            return;
        }

        // define variable for recaptcha check answer
        $masterRequest = $this->requestStack->getMasterRequest();
        $remoteip = $masterRequest->getClientIp();
        $answer = $masterRequest->get('g-recaptcha-response');

        // Verify user response with Google
        $response = $this->checkAnswer($this->privateKey, $remoteip, $answer);

        // Perform server side hostname check
        if ($this->verifyHost && $response['hostname'] !== $masterRequest->getHost()) {
            $this->context->addViolation($constraint->invalidHostMessage);
        }
        elseif ($response['success'] !== true) {
            $this->context->addViolation($constraint->message);
        }
    }

    /**
      * Calls an HTTP POST function to verify if the user's guess was correct.
      *
      * @param string $privateKey
      * @param string $remoteip
      * @param string $answer
      *
      * @throws ValidatorException When missing remote ip
      *
      * @return Boolean
      */
    private function checkAnswer($privateKey, $remoteip, $answer)
    {
        if ($remoteip == null || $remoteip == '') {
            throw new ValidatorException('For security reasons, you must pass the remote ip to reCAPTCHA');
        }

        // discard spam submissions
        if ($answer == null || strlen($answer) == 0) {
            return false;
        }

        $response = $this->httpGet(self::RECAPTCHA_VERIFY_SERVER, '/recaptcha/api/siteverify', array(
            'secret'   => $privateKey,
            'remoteip' => $remoteip,
            'response' => $answer,
        ));

        return json_decode($response, true);
    }

    /**
     * Submits an HTTP POST to a reCAPTCHA server using Curl if it's available.
     *
     * @param string $host
     * @param string $path
     * @param array  $data
     *
     * @return array response
     */
    private function httpGet($host, $path, $data)
    {
        $host = sprintf('%s%s?%s', $host, $path, http_build_query($data));

        if (function_exists('curl_version')) {
            $ch = curl_init();

            curl_setopt($ch, CURLOPT_AUTOREFERER, TRUE);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_URL, $host);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);

            $response = curl_exec($ch);
            curl_close($ch);

        } else if (ini_get('allow_url_fopen')) {
            $context = $this->getResourceContext();

            $response = file_get_contents($host, false, $context);
        } else {
            throw new \Exception('Impossible to check ReCaptcha - you must have either Curl enabled or allow_url_fopen on in your .ini settings.');
        }

        return $response;
    }

    private function getResourceContext()
    {
        if (null === $this->httpProxy['host'] || null === $this->httpProxy['port']) {
            return null;
        }

        $options = array();
        foreach (array('http', 'https') as $protocol) {
            $options[$protocol] = array(
                'method' => 'GET',
                'proxy' => sprintf('tcp://%s:%s', $this->httpProxy['host'], $this->httpProxy['port']),
                'request_fulluri' => true,
            );

            if (null !== $this->httpProxy['auth']) {
                $options[$protocol]['header'] = sprintf('Proxy-Authorization: Basic %s', base64_encode($this->httpProxy['auth']));
            }
        }

        return stream_context_create($options);
    }
}
