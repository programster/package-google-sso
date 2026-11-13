<?php

declare(strict_types = 1);

namespace Programster\GoogleSso\Exceptions;

/**
 * Thrown when the callback request is missing a query parameter that Google always sends (code or state), or it isn't
 * a string. This usually means someone went to the callback URL directly, rather than being sent there by Google.
 */
class ExceptionMissingCallbackParameter extends \Exception
{
    private readonly string $m_parameterName;


    /**
     * @param string $parameterName - the name of the query parameter that was missing, e.g. "code".
     */
    public function __construct(string $parameterName)
    {
        $this->m_parameterName = $parameterName;
        parent::__construct("The Google SSO callback is missing the \"{$parameterName}\" query parameter.");
    }


    # Accessors
    public function getParameterName() : string { return $this->m_parameterName; }
}
