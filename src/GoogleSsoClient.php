<?php


namespace Programster\GoogleSso;

use Programster\GoogleSso\Exceptions\ExceptionCsrfTokenMismatch;
use Programster\GoogleSso\Exceptions\ExceptionUnexpectedResponse;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Random\RandomException;

class GoogleSsoClient
{
    private string $googleOauthUrl;


    /**
     * Create a client for interfacing with the Google SSO.
     *
     * @param string $clientId - the client ID Google provided you for using their OAuth2 system.
     *
     * @param string $clientSecret - the secret to go with the client ID that Google provided.
     *
     * @param string $googleCallbackUrl - your site's endpoint to provide google, for where they should send the user
     * when they have logged in. This needs to be already registered with your Google client. E.g. this would be
     * your site's FQDN, with the relevant path such as. https://mydomain.com/sso/login-handler
     *
     * @param RequestFactoryInterface $requestFactory - the factory you wish to use for creating HTTP requests, which
     * we will use for sending a POST request to Google. We recommend \GuzzleHttp\Psr7\HttpFactory().
     *
     * @param ClientInterface $httpClient - the HTTP client you wish to use for sending/recieving messages
     * to Google. We recommend \GuzzleHttp\Client().
     *
     * @param CacheConfig|null $cacheConfig - optionally provide a configuration for caching, for us to cache the result
     * of fetching the Google SSO certificate information, so that the server does not have to look this up every time
     * a user logs in. This improves performance at the cost of extra setup/configuration.
     *
     * @param string $sessionCsrfTokenKey - the key that will be used for storing a CSRF token in the session for the
     * user. This will default to googleSsoCsrfToken by default, but you can override this if your application would
     * needs to use the key for something else elsewhere, which seems extremely unlikely..
     *
     * @param string $googleJwtCertsUrl - where google's JWT public key data is held, which we will use to verify that
     * the JWT we recieved on our handler is actually from Google, and someone isn't trying to hack their way in with
     * a made up JWT that wasn't from Google.
     *
     * @param string $googleOauthUrl - the base of all of the Oauth2 endpoints for Google. You shouldn't need to
     * override this, but this is here just in case it changes and I fail to update this package in time.
     */
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $googleCallbackUrl,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly ClientInterface $httpClient,
        private readonly ?CacheConfig $cacheConfig = null,
        private readonly string $sessionCsrfTokenKey = "googleSsoCsrfToken",
        private readonly string $googleJwtCertsUrl = "https://www.googleapis.com/oauth2/v3/certs",
        string $googleOauthUrl = "https://oauth2.googleapis.com",
    )
    {
        // ensure $googleOauthUrl does not end in / and strip it off if it does.
        if (substr($googleOauthUrl, -1) === "/")
        {
            $googleOauthUrl = substr($googleOauthUrl, 0, -1);
        }

        $this->googleOauthUrl = $googleOauthUrl;
    }


    /**
     * Send the user's browser to the Google SSO login page, where the user logs in, or if already logged in
     * just confirms they wish to allow our applicaton to know about them.
     * @return void
     * @throws \Random\RandomException
     */
    public function sendUserToGoogleLogin() : never
    {
        $url = $this->createGoogleSsoLoginUrl();
        header("Location: $url");
        die();
    }


    /**
     * Create and return the URL that a user can go to in order to start the "Login with Google" flow.
     * @return string - a Google URL the user needs to go to, in order to login with Google.
     * @throws RandomException
     */
    public function createGoogleSsoLoginUrl() : string
    {
        // generate the URL only once, and return existing one if already created. This will avoid issues from if
        // the implementor calls this method multiple times, for multiple links on a page etc.
        static $generatedUrl = null;

        if ($generatedUrl === null)
        {
            // create a CSRF token and store it locally
            $randomToken = bin2hex(random_bytes(16));
            $_SESSION[$this->sessionCsrfTokenKey] = $randomToken;

            // info about scopes
            // https://developers.google.com/identity/protocols/oauth2/scopes
            $scopes = implode(" ", ["email", "openid", "profile"]);

            $queryParameters = [
                'scope' => $scopes,
                'response_type' => 'code',
                'access_type' => "offline",
                'state' => $randomToken,
                'redirect_uri' => $this->googleCallbackUrl,
                'client_id' => $this->clientId,
            ];

            $generatedUrl = "https://accounts.google.com/o/oauth2/auth?" . http_build_query($queryParameters);
        }

        return $generatedUrl;
    }


    /**
     * Method to handle Google redirecting the user back to your site after the user has logged in.
     * This should be implemented on the endpoint that you provided google to send users to, and you
     * provided as $googleCallbackUrl for this object's constructor. Google will have provided
     * you with "code" and "state" query parameters in the user's browser URL, which we can use
     * to trade in our backend for the actual information about the user.
     *
     * @param string|null $code - optionally provide the code that Google provided in the URL. If not provided,
     * then this method will get it using the $_GET superglobal. Hence, this is only required if your application or
     * framework interferes with that superglobal.
     *
     * @param string|null $state - optionally provide the "state" that Google provided in the URL. If not provided,
     *  then this method will get it using the $_GET superglobal. Hence, this is only required if your application or
     *  framework interferes with that superglobal.
     *
     * @return GoogleUserData
     * @throws ExceptionCsrfTokenMismatch
     * @throws ExceptionUnexpectedResponse
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function handleGoogleSsoLogin(
        ?string $code = null,
        ?string $state = null,
    ) : GoogleUserData
    {
        if ($code === null)
        {
            // handle google response with oauth code.
            $code = $_GET['code'];
        }

        if ($state === null)
        {
            // handle google response with oauth code.
            $state = $_GET['state'];
        }

        if ($state !== $_SESSION[$this->sessionCsrfTokenKey])
        {
            throw new ExceptionCsrfTokenMismatch($state, $_SESSION[$this->sessionCsrfTokenKey]);
        }

        $parameters = [
            'grant_type' => "authorization_code",
            'code' => $code,
            'client_id' =>  $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->googleCallbackUrl,
        ];

        // send code off to google to trade the code for auth token
        $url = $this->googleOauthUrl . "/token";
        $request = $this->requestFactory->createRequest("POST", $url);
        $request->getBody()->write(json_encode($parameters));
        $request = $request->withHeader("Content-Type", "application/json");
        $response = $this->httpClient->sendRequest($request);
        $responseBody = $response->getBody()->getContents();

        try
        {
            $responseArray = json_decode(json: $responseBody, associative: true, flags: JSON_THROW_ON_ERROR);
        }
        catch(\Exception $e)
        {
            throw new ExceptionUnexpectedResponse($response);
        }

        $accessToken = $responseArray['access_token'];
        $expiresIn = $responseArray['expires_in'];
        $expiry = time() + $expiresIn;
        $scope = $responseArray['scope'];
        $tokenType = $responseArray['token_type'];
        $idToken = $responseArray['id_token'];

        // googles public jwk: https://www.googleapis.com/oauth2/v3/certs
        // verify it using firebase/jwt
        $jsonWebKeys = $this->getGoogleJwtCertKeys();
        $firebaseKeys = \Firebase\JWT\JWK::parseKeySet($jsonWebKeys);
        $jwt = \Firebase\JWT\JWT::decode($idToken, $firebaseKeys); // throws SignatureInvalidException if invalid signature given
        return GoogleUserData::createFromJwt($jwt);
    }


    /**
     * Get the array for the JWT certificates that we should use to verify the keys. This method will fetch
     * the data from the internet if no cache system is enabled, or if the cached valu has expired.
     * @return array
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private function getGoogleJwtCertKeys() : array
    {
        if ($this->cacheConfig !== null)
        {
            $cachePool = $this->cacheConfig->getCacheItemPool();
            $item = $cachePool->getItem($this->cacheConfig->getCacheKey());

            if ($item->isHit())
            {
                $certsString = $item->get();
            }
            else
            {
                $certsString = file_get_contents($this->googleJwtCertsUrl);
                $item->set($certsString);
                $item->expiresAfter($this->cacheConfig->getJwtCachePeriod());
                $cachePool->save($item);
            }
        }
        else
        {
            $certsString = file_get_contents($this->googleJwtCertsUrl);
        }

        return json_decode($certsString, true);
    }



}