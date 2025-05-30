<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller; 
use App\Mail\WelcomeMail;
use App\Models\Coach;
use App\Models\CoachAvailability;
use App\Models\CoachLanguage;
use App\Models\CoachSkill;
use App\Models\Trainee;
use App\Models\Admin;
use App\Models\TraineeAreaOfInterest;
use App\Models\TraineePreferredLanguage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary; 

class AuthController extends Controller
{  
    public function getTraineeRegistrationEnumValues()
    {
        return response()->json([
            'trainee' => [
                'preferred_languages' => $this->getEnumValues('trainee_preferred_languages', 'Language'),
                'areas_of_interest' => $this->getEnumValues('trainee_areas_of_interest', 'Area_Of_Interest'),
                'education_levels' => $this->getEnumValues('trainees', 'Education_Level')
            ]
        ]);
    }
    
    public function getCoachRegistrationEnumValues()
    {
        return response()->json([
            'coach' => [
                'skills' => $this->getEnumValues('coach_skills', 'Skill'),
                'languages' => $this->getEnumValues('coach_languages', 'Language'),
            ]
        ]);
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'Role_Profile' => 'required|in:Coach,Trainee'
        ]);
        
        return $validated['Role_Profile'] === 'Coach' 
            ? $this->registerCoach($validated, $request) 
            : $this->registerTrainee($validated, $request);
    }

    private function getEnumValues($table, $column)
    {
        $type = DB::select("SHOW COLUMNS FROM {$table} WHERE Field = ?", [$column])[0]->Type;
        preg_match_all("/'([^']*)'/", $type, $matches);
        return $matches[1] ?? [];
    }

    private function registerCoach(array $validated, Request $request)
    {
        $validSkills = $this->getEnumValues('coach_skills', 'Skill');
        $validLanguages = $this->getEnumValues('coach_languages', 'Language');

        $validated = array_merge($validated, $request->validate([
            'Full_Name' => 'required|string|max:255',
            'Email' => 'required|email|unique:users,email',
            'Password' => 'required|string|min:8',
            'Linkedin_Link' => 'required|url',
            'Photo' => 'required|image|mimes:jpeg,png,jpg|max:2048',
            'Title' => 'required|string|max:100',
            'Company_or_School' => 'required|string|max:255',
            'Bio' => 'required|string',
            'Years_Of_Experience' => 'required|integer|min:0',
            'Months_Of_Experience' => 'required|integer|between:0,11',
            'Skills' => 'required|array|min:1',
            'Skills.*' => ['required', 'string', Rule::in($validSkills)],
            'Languages' => 'required|array|min:1',
            'Languages.*' => ['required', 'string', Rule::in($validLanguages)],
            'availability' => 'required|array', 
            'availability.days' => 'required_with:availability|array',
            'availability.days.*' => 'in:Sunday,Monday,Tuesday,Wednesday,Thursday,Friday,Saturday',
            'availability.time_slots' => 'required_with:availability|array',
            'availability.time_slots.*' => 'array',
            'availability.time_slots.*.*.start_time' => 'required_with:availability|date_format:H:i',
            'availability.time_slots.*.*.end_time' => 'required_with:availability|date_format:H:i|after:availability.time_slots.*.*.start_time',
        ]));
    try {
        return DB::transaction(function () use ($validated, $request) {
            $photoPath = null;
            if ($request->hasFile('Photo')) {
                $uploadedFile = $request->file('Photo');
                $result = Cloudinary::upload($uploadedFile->getRealPath(), [
                    'folder' => 'coach_photos', 
                    'public_id' => 'coach_' . time(), 
                    'transformation' => [
                        'quality' => 'auto',
                        'fetch_format' => 'auto',
                        'width' => 200,
                        'height' => 200,
                        'crop' => 'fill',
                    ],
                ]);
                $photoPath = $result->getSecurePath();
            }
        
            $user = User::create([
                'Full_Name' => $validated['Full_Name'],
                'Email' => $validated['Email'],
                'Password' => Hash::make($validated['Password']),
                'Linkedin_Link' => $validated['Linkedin_Link'],
                'Role_Profile' => "Coach",
                'Photo' => $photoPath
            ]);

            $coach = Coach::create([
                'User_ID' => $user->User_ID,
                'Title' => $validated['Title'],
                'Company_or_School' => $validated['Company_or_School'],
                'Bio' => $validated['Bio'],
                'admin_id' => 1, 
                'Years_Of_Experience' => $validated['Years_Of_Experience'],
                'Months_Of_Experience' => $validated['Months_Of_Experience'],
                'status' => Coach::STATUS_PENDING,
            ]);

            foreach ($validated['Skills'] as $skill) {
                CoachSkill::create([
                    'coach_id' => $coach->User_ID,
                    'Skill' => $skill
                ]);
            }

            foreach ($validated['Languages'] as $language) {
                CoachLanguage::create([
                    'coach_id' => $coach->User_ID,
                    'Language' => $language
                ]);
            }
            
            if (!empty($validated['availability'])) {
                $this->setAvailability($coach->User_ID, $validated['availability']);
            }
            Mail::to($user->Email)->send(new WelcomeMail($user));

            return response()->json([
                'message' => 'Registration request submitted successfully. Awaiting admin approval.',
                'user_id' => $user->User_ID,
            ], 201);
        });
    } catch (\Exception $e) {
        return response()->json([
            'message' => 'Failed to submit registration request',
            'error' => $e->getMessage(),
        ], 500);
    }
    }
    
    private function setAvailability($userID, array $availability)
    {
        $savedSlots = [];

        try {
            foreach ($availability['days'] as $day) {
                if (isset($availability['time_slots'][$day])) {
                    foreach ($availability['time_slots'][$day] as $slot) {
                        $availabilityRecord = CoachAvailability::create([
                            'coach_id' => $userID,
                            'Day_Of_Week' => $day,
                            'Start_Time' => $slot['start_time'],
                            'End_Time' => $slot['end_time'],
                        ]);

                        $savedSlots[] = $availabilityRecord;
                    }
                }
            }
        } catch (\Exception $e) {
            throw new \Exception("Error saving availability: " . $e->getMessage());
        }
        return $savedSlots;
    } 
    
    protected function registerTrainee(array $validated, Request $request)
    {
        $validLanguages = $this->getEnumValues('trainee_preferred_languages', 'Language');
        $validInterests = $this->getEnumValues('trainee_areas_of_interest', 'Area_Of_Interest');
        $validEducationLevels = $this->getEnumValues('trainees', 'Education_Level');
        $validated = array_merge($validated, $request->validate([
            'Full_Name' => 'required|string|max:255',
            'Email' => 'required|email|unique:users,email',
            'Password' => 'required|string|min:8',
            'Education_Level' => ['required', 'string', Rule::in($validEducationLevels)],
            'Institution_Or_School' => 'required|string',
            'Story' => 'nullable|string',
            'Field_Of_Study' => 'required|string',
            'Photo' => 'nullable|image|mimes:jpeg,png,jpg|max:2048', 
            'Linkedin_Link' => 'nullable|url',
            'Years_Of_Professional_Experience' =>'nullable|integer|min:0',
            'Current_Role' => 'nullable|string',
            'Preferred_Languages' => 'required|array|min:1',
            'Preferred_Languages.*' => ['required', 'string', Rule::in($validLanguages)],
            'Areas_Of_Interest' => 'required|array|min:1',
            'Areas_Of_Interest.*' => ['required', 'string', Rule::in($validInterests)]
        ]));
        
        return DB::transaction(function () use ($validated, $request) {
            $photoPath = null;
            if ($request->hasFile('Photo')) {
                $uploadedFile = $request->file('Photo');
                $result = Cloudinary::upload($uploadedFile->getRealPath(), [
                    'folder' => 'trainee_photos', 
                    'public_id' => 'trainee_' . time(), 
                    'transformation' => [
                        'quality' => 'auto',
                        'fetch_format' => 'auto',
                        'width' => 200,
                        'height' => 200,
                        'crop' => 'fill',
                    ],
                ]);
                $photoPath = $result->getSecurePath(); 
            }
            
            $user = User::create([
                'Full_Name' => $validated['Full_Name'],
                'Email' => $validated['Email'],
                'Password' => Hash::make($validated['Password']),
                'Role_Profile' => "Trainee",
                'Photo' => $photoPath, 
                'Linkedin_Link' => !empty($validated['Linkedin_Link']) ? $validated['Linkedin_Link'] : null
            ]);

            Mail::to($user->Email)->send(new WelcomeMail($user));
            
            $trainee = Trainee::create([
                'User_ID' => $user->User_ID,
                'Education_Level' => $validated['Education_Level'],
                'Institution_Or_School' => $validated['Institution_Or_School'],
                'Field_Of_Study' => $validated['Field_Of_Study'],
                'Current_Role' => $validated['Current_Role'] ?? null,  
                'Story' => $validated['Story'] ?? null,  
                'Years_Of_Professional_Experience' => $validated['Years_Of_Professional_Experience'] ?? null
            ]);

            foreach ($validated['Preferred_Languages'] as $lang) {
                TraineePreferredLanguage::create([
                    'trainee_id' => $trainee->User_ID,
                    'Language' => $lang
                ]);
            }

            foreach ($validated['Areas_Of_Interest'] as $interest) {
                TraineeAreaOfInterest::create([
                    'trainee_id' => $trainee->User_ID,
                    'Area_Of_Interest' => $interest
                ]);
            }

            return response()->json([
                'message' => 'Trainee registered successfully',
                'user' => $user,
                'token' => $user->createToken('auth_token')->plainTextToken,
                'photo_path' => $photoPath
            ], 201);
        });
    }

    public function login(Request $request)
    {
        $request->validate([
            'Email' => 'required_without:email|email',
            'email' => 'required_without:Email|email',
            'Password' => 'required_without:password',
            'password' => 'required_without:Password',
        ]);
        $email = strtolower($request->Email ?? $request->email);
        $password = $request->Password ?? $request->password;

        $admin = Admin::whereRaw('LOWER(Email) = ?', [$email])->first();

        if ($admin) {
            $requestPassword = trim(strtolower($password));
            $adminPassword = trim(strtolower($admin->getRawOriginal('Password')));

            if ($requestPassword !== $adminPassword) {
                return response()->json([
                    'message' => 'Invalid credentials',
                    'request_password' => $password,
                    'admin_password' => $admin->getRawOriginal('Password'),
                ], 401);
            }

            $token = $admin->createToken('admin-token')->plainTextToken;

            return response()->json([
                'message' => 'Login successful Admin',
                'token' => $token,
                'admin_id' => $admin->id,
            ], 200);
        }
        $user = User::whereRaw('LOWER(Email) = ?', [$email])->first();

        if (!$user) {
            return response()->json([
                'message' =>  'The associated user account is missing. Please contact support for more details.',
            ], 404);
        }

        if (!Hash::check($password, $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials',
                'request_password' => $password,
            ], 401);
        }

        if ($user->role_profile === 'Coach') {
            $coach = Coach::where('User_ID', $user->User_ID)->first();
            if ($coach && $coach->status === Coach::STATUS_PENDING) {
                return response()->json([
                    'message' => 'Your account is still pending approval',
                ], 403);
            }
        }

        $token = $user->createToken('user-token')->plainTextToken;
        $role = $user->role_profile;

        return response()->json([
            'message' => "Login successful $role",
            'token' => $token,
            'User_ID' => $user->User_ID,
            'role' => $role,
        ], 200);
    }

    public function logout(Request $request)
    {
        if ($request->wantsJson() || $request->is('api/*')) {
            if (!$request->bearerToken()) {
                return response()->json([
                    'message' => 'No token provided',
                ], 401);
            }

            $user = auth('sanctum')->user();
            if (!$user) {
                return response()->json([
                    'message' => 'You are already logged out or not authenticated.',
                ], 401);
            }

            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'message' => 'Logout successful',
            ], 200);
        }
    }
}
