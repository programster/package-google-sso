<?php

/*
 * A PSR-18 client that plays the part of Google's token and certificate endpoints, so the tests need no network
 * access or credentials. Any other request throws, which also checks the client is sending to the right URLs.
 */

declare(strict_types = 1);

namespace Programster\GoogleSso\Testing;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;


final class FakeGoogleHttpClient implements ClientInterface
{
    public const TOKEN_URL = "https://oauth2.googleapis.com/token";
    public const CERTS_URL = "https://www.googleapis.com/oauth2/v3/certs";

    private readonly ResponseInterface $m_tokenResponse;
    private readonly ResponseInterface $m_certsResponse;
    private int $m_numCertsRequests = 0;
    private int $m_numTokenRequests = 0;
    private RequestInterface $m_lastTokenRequest;


    /**
     * @param ResponseInterface $tokenResponse - what the token endpoint responds with.
     * @param ResponseInterface $certsResponse - what the certificates endpoint responds with.
     */
    public function __construct(ResponseInterface $tokenResponse, ResponseInterface $certsResponse)
    {
        $this->m_tokenResponse = $tokenResponse;
        $this->m_certsResponse = $certsResponse;
    }


    /**
     * Create a fake Google that issues an ID token with these claims, signed by TestSigningKey::get().
     * @param array<string, mixed> $claims - the claims, e.g. from TestFixtures::validClaims().
     * @return FakeGoogleHttpClient
     */
    public static function issuingIdToken(array $claims) : FakeGoogleHttpClient
    {
        return self::issuingIdTokenSignedWith(TestSigningKey::get(), $claims);
    }


    /**
     * Create a fake Google that issues an ID token signed with the given key, while its certificates endpoint still
     * serves TestSigningKey::get()'s certificates, e.g. to forge a token, or sign one with a key it doesn't publish.
     *
     * @param TestSigningKey $signingKey - the key to sign the ID token with.
     *
     * @param array<string, mixed> $claims - the claims, e.g. from TestFixtures::validClaims().
     *
     * @return FakeGoogleHttpClient
     */
    public static function issuingIdTokenSignedWith(TestSigningKey $signingKey, array $claims) : FakeGoogleHttpClient
    {
        $idToken = $signingKey->signIdToken($claims);
        $body = json_encode(['access_token' => 'test-access-token', 'id_token' => $idToken], JSON_THROW_ON_ERROR);
        return self::respondingToTokenRequestsWith(new Response(200, ['Content-Type' => 'application/json'], $body));
    }


    /**
     * Create a fake Google whose token endpoint gives this response, e.g. an error.
     * @param ResponseInterface $tokenResponse - what the token endpoint responds with.
     * @return FakeGoogleHttpClient
     */
    public static function respondingToTokenRequestsWith(ResponseInterface $tokenResponse) : FakeGoogleHttpClient
    {
        $certsResponse = new Response(200, ['Content-Type' => 'application/json'], TestSigningKey::get()->getJwks());
        return new FakeGoogleHttpClient($tokenResponse, $certsResponse);
    }


    /**
     * Get a copy of this fake Google, whose certificates endpoint gives a different response, e.g. an error.
     * @param ResponseInterface $certsResponse - what the certificates endpoint responds with.
     * @return FakeGoogleHttpClient
     */
    public function withCertsResponse(ResponseInterface $certsResponse) : FakeGoogleHttpClient
    {
        return new FakeGoogleHttpClient($this->m_tokenResponse, $certsResponse);
    }


    public function sendRequest(RequestInterface $request) : ResponseInterface
    {
        $url = (string)$request->getUri();

        if ($request->getMethod() === "POST" && $url === self::TOKEN_URL)
        {
            $this->m_numTokenRequests++;
            $this->m_lastTokenRequest = $request;
            $response = $this->m_tokenResponse;
        }
        elseif ($request->getMethod() === "GET" && $url === self::CERTS_URL)
        {
            $this->m_numCertsRequests++;
            $response = $this->m_certsResponse;
        }
        else
        {
            throw new \LogicException("The fake Google got an unexpected request: {$request->getMethod()} {$url}");
        }

        return $response;
    }


    /**
     * Get the last request that was sent to the token endpoint.
     * @return RequestInterface
     * @throws \LogicException - if nothing has been sent to the token endpoint yet.
     */
    public function getLastTokenRequest() : RequestInterface
    {
        if ($this->m_numTokenRequests === 0)
        {
            throw new \LogicException("Nothing has been sent to the fake Google's token endpoint.");
        }

        return $this->m_lastTokenRequest;
    }


    # Accessors
    public function getNumCertsRequests() : int { return $this->m_numCertsRequests; }
    public function getNumTokenRequests() : int { return $this->m_numTokenRequests; }
}
