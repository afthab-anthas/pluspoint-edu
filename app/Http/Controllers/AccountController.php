<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Mail\ResetPasswordEmail;
use App\Mail\passwordNotification;
use Illuminate\Support\Facades\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\EmailController;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\MAIL;
use Illuminate\Support\Str;
use App\Exports\UsersExport;
use Maatwebsite\Excel\Facades\Excel;
use Carbon\Carbon;


class AccountController extends Controller
{
    //this method will show user registration form
    public function registration()
    {
        return view('front.account.registration');
    }
    //this method will save user login form
    public function processRegistration(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'name' => 'required',
                'email' => 'required|email|regex:/^[^A-Z]/|unique:users,email',
                'password' => [
                    'required',
                    'min:12',
                    'regex:/[a-z]/',      // must contain at least one lowercase letter
                    'regex:/[A-Z]/',      // must contain at least one uppercase letter
                    'regex:/[0-9]/',      // must contain at least one digit
                ],
                'confirm_password' => 'required|same:password',
                'role' => 'required',
                'agentCode' => [
                    'nullable',
                    function ($attribute, $value, $fail) {
                        if (!empty($value)) {
                            $validCode = DB::table('code-database')
                                ->where('referral_code', $value)
                                ->exists();

                            if (!$validCode) {
                                $fail('Invalid agent code.');
                            }
                        }
                    }
                ]
            ],
            [
                'password.min' => 'The password must be at least 12 characters.',
                'password.regex' => 'The password must contain at least one lowercase letter, one uppercase letter, and one digit.',
                'confirm_password.same' => 'The confirm password must match the password.',
                'email.regex' => 'The email must not contain uppercase letters.',
                'agentCode' => 'Invalid agent code.'
            ]
        );

        if ($validator->passes()) {

            $user = new User();
            $user->name = $request->name;
            $user->email = $request->email;
            // $user->mobile = $request->mobile; // Add this line if you want to save the mobile number
            $user->password = Hash::make($request->password);
            $user->role = $request->role;
            $user->agentCode = $request->agentCode;
            $user->save();
            $emailController = new EmailController();
            // Call the sendRegisterEmail method with the user's email
            $emailController->SendRegisterEmail($user->email, $user->name, $user->role);


            session()->flash('success', 'User has been registered successfully');


            return response()->json([
                'status' => true,
                'errors' => []
            ]);
        } else {

            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ]);
        }
    }

    //this method will show user login form
    public function login()
    {
        return view('front.account.login');
    }
    //this method will authenticate user login form
    public function authenticate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
        ]);
        if ($validator->passes()) {
            if (Auth::attempt(['email' => $request->email, 'password' => $request->password])) {
                switch (Auth::user()->role) {
                    case 'admin':
                        session()->flash('success', 'You are logged in as admin now.');
                        return redirect()->route('account.profile.admin');
                    case 'broker':
                        session()->flash('success', 'You are logged in as broker now.');
                        return redirect()->route('account.profile');
                    case 'student':
                        session()->flash('success', 'You are logged in as student now.');
                        return redirect()->route('account.profile');
                    default:
                        return redirect()->back()->with('error', 'Invalid role');
                }
            } else {
                return redirect()->back()->with('error', 'Invalid email or password');
            }
        } else {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput($request->only('email'));
        }
    }

    //this method will show user profile page
    public function profile()
    {
        if (Auth::check()) {
            $id = Auth::user()->id;
            $user = User::where('id', $id)->first();
            return view('front.account.profiles.student_profile', ['user' => $user]);
        } else {
            // Handle the case when the user is not authenticated
            return redirect()->route('account.login');
        }
    }
    public function profileEdu()
    {
        if (Auth::check()) {
            $id = Auth::user()->id;
            $user = User::where('id', $id)->first();
            return view('front.account.profiles.student_profile_edu', ['user' => $user]);
        } else {
            // Handle the case when the user is not authenticated
            return redirect()->route('account.login');
        }
    }
    public function profilePrefrences()
    {
        if (Auth::check()) {
            $id = Auth::user()->id;
            $user = User::where('id', $id)->first();
            return view('front.account.profiles.student_profile_prefrences', ['user' => $user]);
        } else {
            // Handle the case when the user is not authenticated
            return redirect()->route('account.login');
        }
    }
    public function passwordchange()
    {
        if (Auth::check()) {
            $id = Auth::user()->id;
            $user = User::where('id', $id)->first();
            return view('front.account.profiles.security', ['user' => $user]);
        } else {
            // Handle the case when the user is not authenticated
            return redirect()->route('account.login');
        }
    }
    //this method will update user data from profile
    public function updateProfile(Request $request)
    {
        $id = Auth::user()->id;
        $validator = Validator::make($request->all(), [
            'name' => 'required|min:5',
            'email' => "required|email|unique:users,email,{$id},id",
            'dob' => "required|date|before_or_equal:" . date('Y-m-d', strtotime('-10 years')),
            'citizenship' => 'required',
            'residency' => 'required',
            'passportExpiry' => 'required',
            'passport' => "required|unique:users,passport,{$id},id",
            'gender' => 'required',
        ]);
        if ($validator->passes()) {
            $user = User::find($id);
            //checking if any changes were made to the user profile
            if (
                $user->name != $request->name ||
                $user->email != $request->email ||
                $user->mobile != $request->mobile ||
                $user->dob != $request->dob ||
                $user->citizenship != $request->citizenship ||
                $user->passport != $request->passport ||
                $user->passportExpiry != $request->passportExpiry ||
                $user->gender != $request->gender ||
                $user->residency != $request->residency
            ) {
                //user details save
                $user->name = $request->name;
                $user->email = $request->email;
                $user->mobile = $request->mobile;

                $user->dob = $request->dob;
                $user->citizenship = $request->citizenship;
                $user->passport = $request->passport;
                $user->passportExpiry = $request->passportExpiry;
                $user->gender = $request->gender;
                $user->residency = $request->residency;
                $user->save();

                session()->flash('success', 'User Profile Has Been Updated');
            } else {
                session()->flash('info', 'No changes were made to the user profile');
            }
            return response()->json([
                'status' => true,
                'error' => []
            ]);
        } else {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ]);
        }
    }
    public function updateProfileEdu(Request $request)
    {
        $id = Auth::user()->id;
        $validator = Validator::make($request->all(), [
            'educationLevel' => 'required|min:5',
            'educationCountry' => 'required',
            'graduationStatus' => 'required',
            'institution' => 'required',
            'englishProficiency' => 'required',
            'avgMark' => 'required',

        ]);
        if ($validator->passes()) {
            $user = User::find($id);
            //checking if any changes were made to the user profile
            if (
                $user->educationLevel != $request->educationLevel ||
                $user->educationCountry != $request->educationCountry ||
                $user->graduationStatus != $request->graduationStatus ||
                $user->institution != $request->institution ||
                $user->degree != $request->degree ||
                $user->englishProficiency != $request->englishProficiency ||
                $user->englishListening != $request->englishListening ||
                $user->englishWriting != $request->englishWriting ||
                $user->englishReading != $request->englishReading ||
                $user->englishSpeaking != $request->englishSpeaking ||
                $user->major != $request->major || $user->avgMark != $request->avgMark
            ) {
                //user details save
                $user->educationLevel = $request->educationLevel;
                $user->educationCountry = $request->educationCountry;
                $user->graduationStatus = $request->graduationStatus;
                $user->institution = $request->institution;

                if (in_array($request->educationLevel, ['Associate Degree', "Bachelor's Degree", "Master's Degree", 'Professional Degree', 'Doctorate Degree (PhD)', 'Vocational Training'])) {
                    $user->degree = $request->degree;
                } else {
                    $user->degree = null;
                }
                $user->englishProficiency = $request->englishProficiency;
                if ($request->englishProficiency != 'NONE') {
                    $user->englishListening = $request->englishListening;
                    $user->englishWriting = $request->englishWriting;
                    $user->englishReading = $request->englishReading;
                    $user->englishSpeaking = $request->englishSpeaking;
                } else {
                    $user->englishListening = null;
                    $user->englishWriting = null;
                    $user->englishReading = null;
                    $user->englishSpeaking = null;
                }
                $user->major = $request->major;
                $user->avgMark = $request->avgMark;
                $user->save();

                session()->flash('success', 'User Profile Has Been Updated');
            } else {
                session()->flash('info', 'No changes were made to the user profile');
            }
            return response()->json([
                'status' => true,
                'error' => []
            ]);
        } else {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ]);
        }
    }
    public function updateProfilePrefrences(Request $request)
    {
        $id = Auth::user()->id;
        $validator = Validator::make($request->all(), [
            'higherEducationCountry1' => 'required',
            'higherEducationCountry2' => 'required',
            'higherEducationCountry3' => 'required',
            'majorInterest' => 'required',
            'educationLevelInterest' => 'required',

        ]);
        if ($validator->passes()) {
            $user = User::find($id);
            //checking if any changes were made to the user profile
            if (
                $user->higherEducationCountry1 != $request->higherEducationCountry1 ||
                $user->higherEducationCountry2 != $request->higherEducationCountry2 ||
                $user->higherEducationCountry3 != $request->higherEducationCountry3 ||
                $user->majorInterest != $request->majorInterest ||
                $user->educationLevelInterest != $request->educationLevelInterest
            ) {
                //user details save
                $user->higherEducationCountry1 = $request->higherEducationCountry1;
                $user->higherEducationCountry2 = $request->higherEducationCountry2;
                $user->higherEducationCountry3 = $request->higherEducationCountry3;
                $user->majorInterest = $request->majorInterest;
                $user->educationLevelInterest = $request->educationLevelInterest;
                $user->save();

                session()->flash('success', 'User Profile Has Been Updated');
            } else {
                session()->flash('info', 'No changes were made to the user profile');
            }
            return response()->json([
                'status' => true,
                'error' => []
            ]);
        } else {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ]);
        }
    }
    public function uploadedFiles(Request $request)
    {
        if (Auth::check()) {
            $id = Auth::user()->id;
            $user = User::find($id);
            return view('front.account.profiles.uploadedFiles', ['user' => $user]);
        } else {
            return redirect()->route('account.profile');
        }
    }
    public function security(Request $request)
    {

        $id = Auth::user()->id;
        $user = User::find($id);
        $validator = Validator::make($request->all(), [
            'oldPassword' => 'required',
            'newPassword' => [
                'required',
                'min:8',
                'regex:/[a-z]/',      // must contain at least one lowercase letter
                'regex:/[A-Z]/',      // must contain at least one uppercase letter
                'regex:/[0-9]/',      // must contain at least one digit
            ],
            'newConfirmPassword' => 'required|same:newPassword',
        ]);
        if ($validator->passes()) {
            if (Hash::check($request->oldPassword, $user->password)) {
                $user->password = Hash::make($request->newPassword);
                $user->save();
                session()->flash('success', 'Password has been updated successfully');
            } else {
                session()->flash('error', 'Old password is incorrect');
            }
        } else {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ]);
        }
    }

    public function updateprofilePic(Request $request)
    {
        // dd($request->all());
        $validator = Validator::make($request->all(), [
            'image' => $request->has('nullCheckbox') ? 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:5120' : 'required|image|mimes:jpeg,png,jpg,gif,svg|max:5120',
        ]);

        if ($validator->passes()) {
            $id = Auth::user()->id;
            $user = User::find($id);

            if ($request->has('nullCheckbox')) {
                $user->image = null;
                User::where('id', $id)->update(['image' => null]);
                session()->flash('success', 'Profile Picture Has Been Deleted');
            } else {
                $image = $request->file('image');
                $ext = $image->getClientOriginalExtension();
                $imageName = $id . '-' . time() . '.' . $ext;
                $image->move(public_path('/profile_pic'), $imageName);
                $user->image = $imageName;

                $source_path = public_path("/profile_pic/{$imageName}");
                $manager = new ImageManager(Driver::class);
                $image = $manager->read($source_path);

                $image->cover(300, 300);
                $image->save(public_path("/profile_pic/thumb/{$imageName}"));
                File::delete(public_path("/profile_pic/thumb/" . Auth::user()->image));
                File::delete(public_path("/profile_pic/" . Auth::user()->image));

                User::where('id', $id)->update(['image' => $imageName]);
                session()->flash('success', 'Profile Picture Has Been Updated');
            }

            return response()->json([
                'status' => true,
                'error' => []
            ]);
        } else {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ]);
        }
    }

    //admin profile starts here

    public function profileAdmin()
    {
        if (Auth::check() && Auth::user()->role == 'admin') {
            $id = Auth::user()->id;
            $user = User::where('id', $id)->first();
            return redirect()->route('account.profile.admin.users');
        } else {
            // Handle the case when the user is not authenticated or not an admin
            return redirect()->route('account.login');
        }
    }

    public function adminUsersView()
    {
        if (Auth::check() && Auth::user()->role == 'admin') {
            $users = User::all();
            return view('front.account.profiles.admin_profile', ['users' => $users]);
        } else {
            return redirect()->route('account.profile');
        }
    }
    public function adminPortalView()
    {
        if (Auth::check() && Auth::user()->role == 'admin') {
            $referralCodes = DB::table('code-database')->get();
            return view('front.account.profiles.admin_profile_portal', ['referralCodes' => $referralCodes]);
        } else {
            return redirect()->route('account.profile');
        }
    }

    public function addReferralCode(Request $request)
    {
        $request->validate([
            'referral_code' => 'required|unique:code-database',
            'description' => 'required'
        ]);

        DB::table('code-database')->insert([
            'referral_code' => $request->referral_code,
            'description' => $request->description,
        ]);

        return redirect()->back()->with('success', 'Referral code added successfully!');
    }

    public function deleteReferralCode(Request $request)
    {
        // Retrieve the referral_code_id from the request
        $id = $request->input('referral_code_id');

        // Validate if the ID is present
        if (!$id) {
            return redirect()->back()->withErrors('No referral code selected.');
        }

        // Perform the deletion
        DB::table('code-database')->where('id', $id)->delete();

        return redirect()->back()->with('success', 'Referral code deleted successfully!');
    }



    public function forgotPassword()
    {
        return view('front.account.profiles.forgot-password');
    }

    public function processForgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator);
        }

        $token = Str::random(60);

        \DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        \DB::table('password_reset_tokens')->insert([
            'email' => $request->email,
            'token' => $token,
            'created_at' => now()
        ]);
        $mailData = [
            'token' => $token,
            'name' => User::where('email', $request->email)->first()->name,
            'subject' => 'Reset Password Request'

        ];
        Mail::to($request->email)->send(new ResetPasswordEmail($mailData));
        return redirect()->route('account.forgotPassword')->with('success', 'Password reset link has been sent to your email');
    }

    public function resetPassword($tokenString)
    {
        $token = \DB::table('password_reset_tokens')->where('token', $tokenString)->first();
        if (!$token) {
            return redirect()->route('account.forgotPassword')->with('error', 'Invalid link');
        }
        return view('front.account.resetPassword', ['tokenString' => $tokenString]);
    }

    public function resetPasswordUpdate(Request $request)
    {
        // Retrieve the token from the database
        $tokenData = \DB::table('password_reset_tokens')->where('token', $request->token)->first();

        if (!$tokenData) {
            return redirect()->route('account.forgotPassword')->with('error', 'Invalid link');
        }

        // Validate the new password and confirmation
        $validator = Validator::make($request->all(), [
            'newPassword' => [
                'required',
                'min:8',
                'regex:/[a-z]/',      // must contain at least one lowercase letter
                'regex:/[A-Z]/',      // must contain at least one uppercase letter
                'regex:/[0-9]/',      // must contain at least one digit
            ],
            'newConfirmPassword' => 'required|same:newPassword',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ]);
        } else {
            // Update the user's password
            $user = User::where('email', $tokenData->email)->first();
            if ($user) {
                $user->update([
                    'password' => Hash::make($request->newPassword)
                ]);


                \DB::table('password_reset_tokens')->where('email', $user->email)->delete();

                session()->flash('success', 'Password has been updated successfully');
                $mailData = [
                    'name' => $user->name

                ];
                Mail::to($user->email)->send(new passwordNotification($mailData));
            } else {
                return redirect()->route('account.forgotPassword')->with('error', 'Something went wrong');
            }
        }
    }

    public function export()
    {
        $timestamp = Carbon::now()->format('Y-m-d_H-i-s');
        $filename = "users-{$timestamp}.xlsx";
        return Excel::download(new UsersExport, $filename);
    }

    public function logout()
    {
        Auth::logout();
        return redirect()->route('home');
    }
}
