<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;

use App\Models\Country;
use App\Models\GlobalOption;
use App\Models\ModulePage;

use Session;

class GenericController extends Controller
{
    public function defaultRoute(GlobalOption $opt) {
    	$userData = Session::get('userData');

        // Options..
        $optionsArr = array();
        $allOptions = $opt->all();
        foreach ($allOptions as $option) {
            $optionsArr[$option->code] = $option->value;
        }
        session_start();
        $_SESSION['globals'] = $optionsArr;
        session_write_close();

    	if($userData['logged_in']) {
            $addToView = array();
            $addToView['userData'] = $userData;

            // Adding modules to which he has access..
            if($userData['is_superadmin'] == 1) {
                // Has access to all modules, regardless of roles assigned..
                $modules = ModulePage::with('module')->whereNotNull('module_pages.is_active')
                                        ->orderBy('module_pages.module_id', 'ASC')
                                        ->orderBy('module_pages.name', 'ASC')->get();
            } else {
                // Need to check roles..
                // TODO
            }

            $lastModuleId = 0;
            $modulesSrc = "";
            $modulesNames = "";
            $menuMarkUp = "";
            $moduleAccess = array();
            foreach($modules as $module) {
                $moduleBase = empty($module->module_id) ? "" : $module->module->base_url;
                
                if(!empty($module->module_id) && empty($module->module->is_active)) {
                    continue;
                }

                $modulesSrc .= "<script type='text/javascript' src='".$moduleBase.$module->module_link."'></script>";
                $modulesNames .= ", 'app.".$module->code."'";

                if($lastModuleId != $module->module_id) {
                    $lastModuleId = $module->module_id;
                    $menuMarkUp .= '<li class="nav-header">'.$module->module->name.'</li>';
                }

                $menuMarkUp .= '<li ui-sref-active="active"><a ui-sref="app.'.$module->code.'"><i class="'.$module->icon.'"></i> <span>'.$module->name.'</span></a></li>';

                $moduleAccess[$module->code] = 1; // todo get highest access level..
            }

            $addToView['modulesSrc'] = $modulesSrc;
            $addToView['modulesNames'] = $modulesNames;
            $addToView['authToken'] = $userData['authToken'];

            // Mirroring links accessible to session so they can be accessed in partials..
            session_start();
            $_SESSION['moduleMarkup'] = $menuMarkUp;
            $_SESSION['moduleAccess'] = $moduleAccess;
            session_write_close();

            Session::put('moduleAccess', $moduleAccess);

            // Countries..
            $countries = Country::all();
            $countriesMarkup = "";
            foreach($countries as $country) {
                if(strlen($countriesMarkup) > 0) {
                    $countriesMarkup .= ",";
                }
                $countriesMarkup .= $country->id.':"'.$country->name.'"'."\n";
            }
            $addToView['countries'] = $countriesMarkup;

    		return view('loggedIn', $addToView);
    	}

		return view('notLogged');
    }

    public function logout() {
        Session::flush();
        session_start();
        session_destroy();
        return redirect('/');
    }

    public function noSessionTimeout() {    
        $now = date('now');

        Session::put('lastActive', $now);

        session_start();
        $_SESSION['lastActive'] = $now;
        session_write_close();

        return 1;
    }
}
