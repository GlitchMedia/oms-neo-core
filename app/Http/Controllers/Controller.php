<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesResources;

use App\Models\User;

use File;

class Controller extends BaseController
{
    use AuthorizesRequests, AuthorizesResources, DispatchesJobs, ValidatesRequests;

    protected function isOauthDefined() {
    	$oAuth = config('oauth.oAuthProvider');
        $oAuthDefined = false;
        if(!empty($oAuth)) {
            $oAuthDefined = true;
        }

        return $oAuthDefined;
    }

    protected function getOAuthProvider() {
    	$oAuth = config('oauth.oAuthProvider');
        return $oAuth;
    }

    protected function getDelegatedAdmin() {
        return config('oauth.oAuthAdmin');
    }

    protected function getOAuthAllowedDomain() {
    	$oAuthDomain = config('oauth.oAuthDomain');
    	return $oAuthDomain;
    }

    protected function getOauthCredentials($adminId = '') {
        if($this->getOAuthProvider() == 'google') {
            $path = storage_path()."/app/".config('oauth.credentials').".json";
            return json_decode(File::get($path), true);
        } elseif($this->getOAuthProvider() == 'azure') {
            $user = User::findOrFail($adminId);
            $toReturn = array(
                'token' =>  $user->oauth_token
            );
            return $toReturn;
        }
    }

    protected function getAppVersion() {
        $path = storage_path()."/../.version";
        return File::get($path);
    }

    protected function isMapsDefined() {
        $mapsKey = config('services.google_maps');

        if(!empty($mapsKey)) {
            return $mapsKey;
        }

        return false;
    }

    //TODO these should probably be placed inside a helper file, but I was unable to get that to work.
    public static function responseSuccess($message) {
      return response()->json([
        'success' => true,
        'message' => $message,
      ], 200);
    }

    public static function responseFailure($message) {
      return response()->json([
        'success' => false,
        'message' => $message,
      ], 400);
    }

    public static function responseData($data, $message = '') {
      $response = [
        'success' => true,
        'data' => $data,
      ];
      if (!empty($message)) {
        $response['message'] = $message;
      }

      return response()->json($response, 200);
    }
}
