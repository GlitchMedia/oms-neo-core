<?php

namespace App\Http\Middleware;

use Closure;

class ApiFormatter
{
    /**
     * Formats the API response as per:
     * https://oms-project.atlassian.net/wiki/display/GENERAL/Standardized+API+format
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        $formatted = json_encode(array(
          "success" => true,
          "data" => array(json_decode($response->getOriginalContent()))
                      ), JSON_PRETTY_PRINT);

        $response->setContent($formatted);

        return $response;
    }
}
