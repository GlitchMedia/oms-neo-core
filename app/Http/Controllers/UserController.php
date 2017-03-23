<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;
use App\Http\Requests\AddBoardPositionRequest;
use App\Http\Requests\AddFeesRequest;
use App\Http\Requests\AddRoleRequest;
use App\Http\Requests\AddWorkingGroupRequest;
use App\Http\Requests\ChangeEmailRequest;
use App\Http\Requests\ChangePasswordRequest;

use App\Models\Antenna;
use App\Models\Auth;
use App\Models\BoardMember;
use App\Models\Country;
use App\Models\Department;
use App\Models\EmailTemplate;
use App\Models\Fee;
use App\Models\FeeUser;
use App\Models\News;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Models\UserWorkingGroup;
use App\Models\WorkingGroup;

use Excel;
use File;
use Hash;
use Image;
use Input;
use Mail;
use Response;
use Session;

class UserController extends Controller
{
    public function getUsers(User $user, Request $req) {
        $max_permission = $req->get('max_permission');
    	$userData = $req->get('userData');
        $search = array(
            'name'          	=>  Input::get('name'),
            'date_of_birth'		=>	Input::get('date_of_birth'),
            'contact_email'		=>	Input::get('contact_email'),
            'gender'			=>	Input::get('gender'),
            'antenna_id'		=>	Input::get('antenna_id'),
            'department_id'		=>	Input::get('department_id'),
            'internal_email'	=>	Input::get('internal_email'),
            'studies_type_id'	=>	Input::get('studies_type_id'),
            'studies_field_id'	=>	Input::get('studies_field_id'),
            'status'			=>	Input::get('status'),
    		'sidx'      		=>  Input::get('sidx'),
    		'sord'				=>	Input::get('sord'),
    		'limit'     		=>  empty(Input::get('rows')) ? 10 : Input::get('rows'),
            'page'      		=>  empty(Input::get('page')) ? 1 : Input::get('page')
    	);

        $export = Input::get('export', false);
        if($export) {
            $search['noLimit'] = true;
        }

        $users = $user->getFiltered($search);
        return responseData($users);
        //Formatting the response to comply with the grid library should not be done in the API...

        if($export) {
            Excel::create('users', function($excel) use ($users) {
                $excel->sheet('users', function($sheet) use ($users) {
                    $sheet->loadView('excel_templates.users')->with("users", $users);
                });
            })->export('xlsx');
            return;
        }

    	$usersCount = $user->getFiltered($search, true);
    	if($usersCount == 0) {
            $numPages = 0;
        } else {
            if($usersCount % $search['limit'] > 0) {
                $numPages = ($usersCount - ($usersCount % $search['limit'])) / $search['limit'] + 1;
            } else {
                $numPages = $usersCount / $search['limit'];
            }
        }

        $toReturn = array(
            'rows'      =>  array(),
            'records'   =>  $usersCount,
            'page'      =>  $search['page'],
            'total'     =>  $numPages
        );

        $isGrid = Input::get('is_grid', false); // Checking if the caller is jqGrid -> if yes, we add actions to the response..

        foreach($users as $userX) {
            $actions = "";
            if($isGrid) {
                // $actions .= "<button class='btn btn-default btn-xs clickMeUser' title='Edit' ng-click='vm.editUser(".$userX->id.", \"userModal\")'><i class='fa fa-pencil'></i></button>"; // Temporary disabled due to some angular bugs
                if($userX->status == 2 && $max_permission == 1) {
                    $actions .= "<button class='btn btn-default btn-xs clickMeUser' title='Activate user' ng-click='vm.editUser(".$userX->id.", \"activateUserModal\")'><i class='fa fa-check'></i></button>";
                } elseif($userX->status != 2 && $userX->id != $userData->id) {
                    $actions .= "<button class='btn btn-default btn-xs clickMeUser' title='View user profile' ng-click='vm.visitProfile(\"".$userX->seo_url."\")'><i class='fa fa-search'></i></button>";
                }
            } else {
                $actions = $userX->id;
            }
        	$toReturn['rows'][] = array(
        		'id'	=>	$userX->id,
        		'cell'	=> 	array(
        			$actions,
        			$userX->last_name." ".$userX->first_name,
        			$userX->date_of_birth->format('d/m/Y'),
        			$userX->contact_email,
        			$userX->gender_text,
        			$userX->antenna->name,
        			empty($userX->department_id) ? "-" : $userX->department->name,
        			$userX->internal_email,
        			$userX->studyField->name,
        			$userX->studyType->name,
        			$userX->status,
                    $userX->seo_url
        		)
        	);
        }
        return responseFailure("This should not be run.");
    }

    public function getUser(User $user) {
        $id = Input::get('id');
        $user = $user->with('antenna')->findOrFail($id);

        return responseData($user, "The requested user.");
    }

    public function activateUser(User $user, Role $role, Fee $fee, EmailTemplate $tpl, Request $req) {
        $currentUser = $req->get('userData');
        // User..
        $id = Input::get('id');
        $user = $user->findOrFail($id);

        if(!empty($user->activated_at)) {
            return responseFailure("User already activated.");
        }

        $departmentId = Input::get('department_id');
        $departmentCheck = Department::findOrFail($departmentId);

        $user->department_id = $departmentId;
        $user->seo_url = $user->generateSeoUrl();
        $user->activated_at = date('Y-m-d H:i:s');

        $userPass = $user->generateRandomPassword();

        $oAuthActive = $this->isOauthDefined();
        if($oAuthActive) {
            $domain = $this->getOAuthAllowedDomain();
            $username = $user->seo_url."@".$domain;

            $success = $user->oAuthCreateAccount(
                $this->getOAuthProvider(),
                $this->getDelegatedAdmin(),
                $this->getOauthCredentials($currentUser['id']),
                $domain,
                $user->seo_url,
                $userPass
            );

            $user->internal_email = $username;

            if($success !== true) {
                die("oAuth problem! Error code:".$success);
            }
        } else {
            $username = $user->contact_email;
            $user->password = Hash::make($userPass);
        }

        $user->save();

        $rolesCache = $role->getCache();
        $feesCache = $fee->getCache();

        // Now for roles..
        $roles = Input::get('roles', array());
        foreach($roles as $key => $val) {
            if(!$val || !isset($rolesCache[$key])) { // Role set as false or does not exist..
                continue;
            }
            $tmpRole = new UserRole();
            $tmpRole->user_id = $user->id;
            $tmpRole->role_id = $key;
            $tmpRole->save();
        }

        // Now for fees..
        $fees = Input::get('fees', array());
        $feesPaid = Input::get('feesPaid');
        foreach($fees as $key => $val) {
            if(!$val || !isset($feesCache[$key])) { // Fee set as false or does not exist..
                continue;
            }

            $tmpFee = new FeeUser();
            $tmpFee->fee_id = $key;
            $tmpFee->user_id = $user->id;
            if(isset($feesPaid[$key])) {
                $tmpFee->date_paid = $feesPaid[$key];
            } else {
                $tmpFee->date_paid = date('Y-m-d');
            }

            $tmpFee->expiration_date = $fee->getFeeEndTime($feesCache[$key]['availability'], $feesCache[$key]['availability_unit'], $tmpFee->date_paid);
            $tmpFee->save();

        }

        // Email user with all data..
        $tpl = $tpl->where('code', 'account_activated')->first();
        $toReplace = array(
            '{fullname}'        =>  $user->first_name." ".$user->last_name,
            '{username}'        =>  $username,
            '{password}'        =>  $userPass,
            '{link}'            =>  url('/')
        );

        $addToView = $tpl->prepareContentForView($toReplace);

        Mail::send('emails.email2', $addToView, function($message) use($user, $addToView) {
            $message->from($addToView['globals']['email_sender'], $addToView['globals']['app_name']);
            $message->to($user->contact_email);
            $message->subject($addToView['title']);
        });

        return responseSuccess('User activated.');
    }

    //TODO this method does not return the same info about a user as getUser does
    public function getUserByToken(Auth $auth) {
        $token = Input::get('token');
        if(empty($token)) {
            return responseFailure("Empty auth token.");
        }

        $now = date('Y-m-d H:i:s');
        $userData = $auth->with('user')->where('token_generated', $token)
                        ->where(function($query) use($now) {
                            $query->where('expiration', '>', $now)
                                    ->orWhereNull('expiration');
                        })
                        ->firstOrFail();
        $antenna = Antenna::findOrFail($userData->user->antenna_id);

        $data = $userData->user;
        $data['antenna'] = $antenna->name;

        return responseData($data);
    }

    public function getUserProfile(User $user, WorkingGroup $wg, Department $dep, Role $role, Fee $fee, UserRole $userRole, Request $req) {
        $isOauthDefined = $this->isOauthDefined();

        $userData = $req->get('userData');
        $url = Input::get('seo_url', $userData['seo_url']);
        $isUi = Input::get('is_ui', false);
        $user = $user->with('antenna', 'department', 'studyField', 'studyType')->where('seo_url', $url)->firstOrFail();
        $id = $user->id;

        $country = Country::find($user->antenna->country_id);
        $toReturn['user'] = array(
            'id'                =>  $user->id,
            'fullname'          =>  $user->first_name." ".$user->last_name,
            'antenna'           =>  $user->antenna->name,
            'antenna_city'      =>  $user->antenna->city,
            'country'           =>  $country->name,
            'department'        =>  !empty($user->department) ? $user->department->name : "-",
            'date_of_birth'     =>  $user->date_of_birth->format('Y-m-d'),
            'gender'            =>  $user->genderText,
            'university'        =>  $user->university,
            'studies'           =>  $user->studyField->name." (".$user->studyType->name.")",
            'city'              =>  $user->city,
            'bio'               =>  !empty($user->other) ? $user->other : "No bio available",
            'rank'              =>  'Member',
            'email'             =>  $user->getEmailAddress(),
            'activated_at'      =>  $user->activated_at->format('Y-m-d'),
            'status'            =>  $user->status_text,
            'suspended_for'     =>  $user->suspended_reason,
            'is_boardmember'    =>  0
        );

        $toReturn['workingGroups'] = array();
        $wgs = $wg->getUserWorkingGroups($id);
        foreach ($wgs as $work) {
            $toReturn['workingGroups'][] = array(
                'id'        =>  $work->id,
                'name'      =>  $work->name,
                'period'    =>  $work->getPeriod()
            );
        }

        $toReturn['board_positions'] = array();
        $isCurrentBoardMember = false;
        $boards = $dep->getUserBoardMemberships($id);
        foreach ($boards as $membership) {
            $toReturn['board_positions'][] = array(
                'id'        =>  $membership->id,
                'name'      =>  $membership->name,
                'period'    =>  $membership->getPeriod()
            );
            if(!$isCurrentBoardMember) {
                $isCurrentBoardMember = $membership->isActiveMembership();
            }
        }

        $toReturn['roles'] = array();
        $roles = $role->getUserRoles($id);
        foreach ($roles as $roleX) {
            $toReturn['roles'][] = array(
                'id'        =>  $roleX->id,
                'name'      =>  $roleX->name,
            );
        }

        $toReturn['fees_paid'] = array();
        $fees = $fee->getUserFees($id);
        foreach ($fees as $userFee) {
            $toReturn['fees_paid'][] = array(
                'id'        =>  $userFee->id,
                'name'      =>  $userFee->name,
                'period'    =>  $userFee->getPeriod()
            );
        }

        // Determining highest rank..
        if(!empty($user->is_superadmin)) {
            $toReturn['user']['rank'] = 'Super Admin';
            $toReturn['user']['is_boardmember'] = 1;
        } else if($isCurrentBoardMember) {
            $toReturn['user']['rank'] = "Board member";
            $toReturn['user']['is_boardmember'] = 1;
        }

        if(!empty($user->is_suspended)) {
            $toReturn['user']['rank'] = 'Suspended';
        }

        $userMaxLevelOfEditing = $userRole->getMaxPermissionLevelForRole('users', $userData->id);

        $toReturn['active_fields'] = array(
            'change_avatar'         =>  ($id == $userData->id) ? true : false,
            'change_password'       =>  ($id == $userData->id && !$isOauthDefined) ? true : false,
            'change_email'          =>  ($id == $userData->id && !$isOauthDefined) ? true : false,
            'change_bio'            =>  ($id == $userData->id) ? true : false,
            'addEditStuff'          =>  ($userData->is_superadmin || $userMaxLevelOfEditing == 1) ? true : false,
            'account_info'          =>  ($userData->is_superadmin || $userMaxLevelOfEditing == 1) ? true : false,
            'suspend_account'       =>  ($user->status == 1 && $id != $userData->id) ? true : false,
            'unsuspend_account'     =>  ($user->status == 3 && $id != $userData->id) ? true : false,
            'impersonate'           =>  ($id != $userData->id) ? true : false,
            'suspended'             =>  empty($user->suspended_reason) ? false : true,
            'work_groups'           =>  (count($toReturn['workingGroups']) > 0 || $userData->is_superadmin || $userMaxLevelOfEditing == 1) ? true : false,
            'board_positions'       =>  (count($toReturn['board_positions']) > 0 || $userData->is_superadmin || $userMaxLevelOfEditing == 1) ? true : false,
            'role'                  =>  (count($toReturn['roles']) > 0 || $userData->is_superadmin || $userMaxLevelOfEditing == 1) ? true : false,
        );

        return responseData($toReturn);
    }

    public function setBoardPosition(BoardMember $bm, AddBoardPositionRequest $req) {
        $bm->user_id = Input::get('user_id');
        $bm->department_id = Input::get('department_id');
        $bm->start_date = Input::get('start_date');
        $bm->end_date = Input::get('end_date');
        $bm->save();

        return responseSuccess("Board position set.");
    }

    public function addUserRoles(Role $role, AddRoleRequest $req) {
        $id = Input::get('user_id');
        $rolesCache = $role->getCache();

        $roles = Input::get('roles');
        foreach ($roles as $key => $val) {
            if(!$val || !isset($rolesCache[$key])) {
                continue;
            }

            UserRole::firstOrCreate([
                'user_id'   =>  $id,
                'role_id'   =>  $key
            ]);
        }

        return responseSuccess("User role added.");
    }

    public function addFeesToUser(Fee $fee, AddFeesRequest $req) {
        $id = Input::get('user_id');

        $feesCache = $fee->getCache();

        $fees = Input::get('fees');
        $feesPaid = Input::get('feesPaid');
        foreach($fees as $key => $val) {
            if(!$val || !isset($feesCache[$key])) { // Fee set as false or does not exist..
                continue;
            }

            $tmpFee = new FeeUser();
            $tmpFee->fee_id = $key;
            $tmpFee->user_id = $id;
            if(isset($feesPaid[$key])) {
                $tmpFee->date_paid = $feesPaid[$key];
            } else {
                $tmpFee->date_paid = date('Y-m-d');
            }

            $tmpFee->expiration_date = $fee->getFeeEndTime($feesCache[$key]['availability'], $feesCache[$key]['availability_unit'], $tmpFee->date_paid);
            $tmpFee->save();

        }

        return responseSuccess("Fee added.");
    }

    public function deleteFee(FeeUser $obj) {
        $id = Input::get('id');
        $obj = $obj->findOrFail($id);
        $obj->delete();

        return responseSuccess("Fee deleted");
    }

    public function deleteRole(UserRole $obj) {
        $id = Input::get('id');
        $obj = $obj->findOrFail($id);
        $obj->delete();

        return responseSuccess("Role deleted.");
    }

    public function deleteMembership(BoardMember $obj) {
        $id = Input::get('id');
        $obj = $obj->findOrFail($id);
        $obj->delete();

        return responseSuccess("Membership deleted.");
    }

    public function deleteWorkGroup(UserWorkingGroup $obj) {
        $id = Input::get('id');
        $obj = $obj->findOrFail($id);
        $obj->delete();

        return responseSuccess("Working group deleted.");
    }

    public function changeEmail(User $user, ChangeEmailRequest $req) {
        $userData = $req->get('userData');
        $user = $user->findOrFail($userData['id']);
        $user->contact_email = Input::get('email');

        $emailHash = $user->getEmailHash($user->contact_email);
        if($user->checkEmailHash($emailHash, $user->id)) {
            return responseFailure("Email already exists!");
        }

        $user->save();

        return responseSuccess("Email changed.");
    }

    public function changePassword(User $user, ChangePasswordRequest $req) {
        $userData = $req->get('userData');
        $user = $user->findOrFail($userData['id']);

        $newPassword = Input::get('password');
        $user->password = Hash::make($newPassword);
        $user->save();

        return responseSuccess("Password changed.");
    }

    public function editBio(User $user, Request $req) {
        $userData = $req->get('userData');
        $user = $user->findOrFail($userData['id']);

        $bio = Input::get('bio');
        $user->other = nl2br($bio);
        $user->save();

        return responseSuccess("Bio changed.");
    }

    public function suspendAccount(User $user, Request $req) {
        $userData = $req->get('userData');

        $id = Input::get('id');
        $user = $user->findOrFail($id);

        $suspensionReason = Input::get('reason');
        $user->suspendAccount($suspensionReason, $userData->first_name." ".$userData->last_name);

        return responseSuccess("Account suspended.");
    }

    public function unsuspendAccount(User $user, Request $req) {
        $userData = $req->get('userData');

        $id = Input::get('id');
        $user = $user->findOrFail($id);

        $user->unsuspendAccount();

        return responseSuccess("Account unsuspended.");
    }

    public function impersonateUser(User $user, Request $req, Auth $auth) {
        $userData = $req->get('userData');
        $xAuthToken = isset($_SERVER['HTTP_X_AUTH_TOKEN']) ? $_SERVER['HTTP_X_AUTH_TOKEN'] : '';

        $id = Input::get('id');
        $user = $user->findOrFail($id);

        $auth = $auth->where('token_generated', $xAuthToken)->firstOrFail();
        $auth->user_id = $id; // Switching token to new user..
        $auth->save();

        $userData = $user->getLoginUserArray($xAuthToken);

        Session::put('userData', $userData);
        // Mirroring Laravel session data to PHP native session.. For use with angular partials..
        session_start();
        $_SESSION['userData'] = Session::get('userData');
        session_write_close();

        return responseSuccess("Impersonated user.");
    }

    public function addWorkingGroupToUser(User $user, UserWorkingGroup $uWg, AddWorkingGroupRequest $req) {
        $id = Input::get('user_id');
        $wgId = Input::get('work_group_id');

        $uWg = $uWg->firstOrCreate([
            'user_id'       =>  $id,
            'work_group_id' =>  $wgId
        ]);

        $start_date = Input::get('start_date');
        $end_date = Input::get('end_date');

        $needsSave = false;
        if(!empty($start_date)) {
            $uWg->start_date = $start_date;
            $needsSave = true;
        }

        if(!empty($end_date)) {
            $uWg->end_date = $end_date;
            $needsSave = true;
        }

        if($needsSave) {
            $uWg->save();
        }

        return responseSuccess("Working group added to user.");
    }

    public function getDashboardData(User $user, News $news) {
        $toReturn = array();
        $toReturn['userCount'] = $user->whereNotNull('activated_at')->count();
        $newestMembers = $user->with('antenna')->whereNotNull('activated_at')->orderBy('activated_at', 'DESC')->take(12)->get();
        foreach($newestMembers as $member) {
            $toReturn['newestMembers'][] = array(
                'id'        =>  $member->id,
                'fullname'  =>  $member->first_name." ".$member->last_name,
                'local'     =>  $member->antenna->name,
                'seo_url'   =>  $member->seo_url
            );

        }

        $toReturn['latestNews'] = array(
            'rows'      =>  array()
        );
        $search['sidx'] = 'date';
        $search['sord'] = 'desc';
        $search['limit'] = 5;
        $search['page'] = 1;

        $news = $news->getFiltered($search);
        foreach($news as $new) {
            $toReturn['latestNews']['rows'][] = array(
                'id'    =>  $new->id,
                'cell'  =>  array(
                    '',
                    $new->title,
                    $new->content,
                    $new->created_at->format('d/m/Y'),
                    $new->user->first_name." ".$new->user->last_name
                )
            );
        }

        return responseData($toReturn);
    }

    public function uploadUserAvatar(Request $req) {
        $userData = $req->get('userData');

        $allowedExt = array(
            'png', 'jpg', 'jpeg'
        );

        $path = storage_path();
        $baseDir = $path."/userAvatars/";
        if(!file_exists($baseDir)) {
            mkdir($baseDir, 0777, true);
        }
        $tmpPlace = $path."/userAvatarsTmp/";
        if(!file_exists($tmpPlace)) {
            mkdir($tmpPlace, 0777, true);
        }

        $file = $req->file('avatar');

        $filename = $file->getClientOriginalName();
        $extension = explode('.', $filename);
        $extension = strtolower($extension[count($extension)-1]);
        if(!in_array($extension, $allowedExt)) {
            return responseFailure("Extension not allowed!");
        }

        $file->move($tmpPlace, $filename);
        $tmpIm = Image::make($tmpPlace.$filename);
        $tmpIm->fit(300);
        $tmpIm->save($baseDir.$userData->id.".jpg");

        unlink($tmpPlace.$filename);

        return responseSuccess("Avatar uploaded.");
    }

    public function getUserAvatar($avatarId) {
        $fallbackAvatar = storage_path()."/baseFiles/defaultAvatar.jpg";
        $path = storage_path()."/userAvatars/".$avatarId.".jpg";

        if(!File::exists($path)) {
            $file = File::get($fallbackAvatar);
            $type = File::mimeType($fallbackAvatar);
        } else {
            $file = File::get($path);
            $type = File::mimeType($path);
        }

        $response = Response::make($file, 200);
        $response->header("Content-Type", $type);
        return $response;
    }

    //Why have this when there is already getUser() which searches by ID?
    public function getUserById(User $user) {
        $user = $user->findOrFail(Input::get('id'));
        return responseData($user);
    }
}
