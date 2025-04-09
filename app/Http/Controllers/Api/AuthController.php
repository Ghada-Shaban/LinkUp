<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller; 
use App\Mail\WelcomeMail;
use App\Models\Coach;
use App\Models\CoachAvailability;
use App\Models\CoachLanguage;
use App\Models\CoachSkill;
use App\Models\Trainee;
use App\Models\TraineeAreaOfInterest;
use App\Models\TraineePreferredLanguage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

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

        return DB::transaction(function () use ($validated, $request) {
            $photoPath = $request->hasFile('Photo') ? $request->file('Photo')->store('photos', 'public') : null;
        
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
                'Months_Of_Experience' => $validated['Months_Of_Experience']
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
                'message' => 'Coach registered successfully',
                'user' => $user,
                'token' => $user->createToken('auth_token')->plainTextToken,
                'photo_path' => $photoPath
            ], 201);
        });
    }
    private function setAvailability($userID, array $availability)
    {
        $savedSlots = [];

        try {
            foreach ($availability['days'] as $day) {
                if (isset($availability['time_slots'][$day])) {
                    foreach ($availability['time_slots'][$day] as $slot) {
                        $availabilityRecord = CoachAvailability::create([
                            'User_ID' => $userID,
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
            $photoPath = $request->hasFile('Photo') ? $request->file('Photo')->store('photos', 'public') : null;
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
            'Email' => 'required|email',
            'Password' => 'required',
        ]);

        $user = User::where('Email', $request->Email)->first();
        if ($user && Hash::check($request->Password, $user->password)) {
            $token = $user->createToken('user-token')->plainTextToken;
            $role = $user->role_profile;

            return response()->json([
                'message' => "Login successful $role",
                'token' => $token,
                'User_ID' => $user->User_ID,
                'role' => $role,
            ]);
        }

        return response()->json([
            'message' => 'Invalid credentials',
        ], 401);
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


// update profile
    
 public function updateProfile(Request $request)
{
    // جلب المستخدم المصادق عليه
    $user = auth('sanctum')->user();
    if (!$user) {
        return response()->json(['message' => 'Unauthenticated'], 401);
    }

    // ديباج للتحقق من القيمة
    \Log::info('User ID: ' . $user->User_ID . ', Role_Profile: ' . $user->Role_Profile);

    // التحقق من الدور باستخدام مقارنة مباشرة (case-insensitive)
    $roleProfile = $user->Role_Profile;
    $isCoach = strcasecmp($roleProfile, 'Coach') === 0;
    $isTrainee = strcasecmp($roleProfile, 'Trainee') === 0;

    if (!$isCoach && !$isTrainee) {
        return response()->json(['message' => 'Invalid role'], 403);
    }

    // الـ Validation: كل الحقول optional
    $validSkills = $this->getEnumValues('coach_skills', 'Skill');
    $validLanguages = $this->getEnumValues('coach_languages', 'Language');
    $validTraineeLanguages = $this->getEnumValues('trainee_preferred_languages', 'Language');
    $validEducationLevels = $this->getEnumValues('trainees', 'Education_Level');

    $validated = $request->validate([
        // حقول مشتركة في جدول users
        'Full_Name' => 'sometimes|string|max:255',
        'Photo' => 'sometimes|image|mimes:jpeg,png,jpg|max:2048',
        'Password' => 'sometimes|string|min:8|confirmed',
        'Linkedin_Link' => 'sometimes|url|nullable',

        // حقول خاصة بـ Coach
        'Title' => 'sometimes|string|max:100',
        'Company_or_School' => 'sometimes|string|max:255',
        'Bio' => 'sometimes|string',
        'Years_Of_Experience' => 'sometimes|integer|min:0',
        'Months_Of_Experience' => 'sometimes|integer|between:0,11',
        'Skills' => 'sometimes|array|min:1',
        'Skills.*' => ['required', 'string', Rule::in($validSkills)],
        'Languages' => 'sometimes|array|min:1',
        'Languages.*' => ['required', 'string', Rule::in($validLanguages)],
        'availability' => 'sometimes|array',
        'availability.days' => 'required_with:availability|array',
        'availability.days.*' => 'in:Sunday,Monday,Tuesday,Wednesday,Thursday,Friday,Saturday',
        'availability.time_slots' => 'required_with:availability|array',
        'availability.time_slots.*' => 'array',
        'availability.time_slots.*.*.start_time' => 'required_with:availability|date_format:H:i',
        'availability.time_slots.*.*.end_time' => 'required_with:availability|date_format:H:i|after:availability.time_slots.*.*.start_time',

        // حقول خاصة بـ Trainee
        'Education_Level' => ['sometimes', 'string', Rule::in($validEducationLevels)],
        'Institution_Or_School' => 'sometimes|string',
        'Story' => 'sometimes|string|nullable',
        'Field_Of_Study' => 'sometimes|string',
        'Years_Of_Professional_Experience' => 'sometimes|integer|min:0|nullable',
        'Current_Role' => 'sometimes|string|nullable',
        'Preferred_Languages' => 'sometimes|array|min:1',
        'Preferred_Languages.*' => ['required', 'string', Rule::in($validTraineeLanguages)],
    ]);

    return DB::transaction(function () use ($request, $validated, $user, $isCoach, $isTrainee) {
        // تحديث البيانات في جدول users
        if ($request->has('Full_Name')) {
            $user->Full_Name = $validated['Full_Name'];
        }

        if ($request->hasFile('Photo')) {
            // حذف الصورة القديمة لو موجودة
            if ($user->Photo) {
                Storage::disk('public')->delete($user->Photo);
            }
            // رفع الصورة الجديدة
            $photoPath = $request->file('Photo')->store('photos', 'public');
            $user->Photo = $photoPath;
        }

        if ($request->has('Password')) {
            $user->Password = Hash::make($validated['Password']);
        }

        if ($request->has('Linkedin_Link')) {
            $user->Linkedin_Link = $validated['Linkedin_Link'];
        }

        // حفظ التغييرات في جدول users
        $user->save();

        // تحديث البيانات بناءً على الدور
        if ($isCoach) {
            $coach = Coach::where('User_ID', $user->User_ID)->first();

            if ($request->has('Title')) {
                $coach->Title = $validated['Title'];
            }

            if ($request->has('Company_or_School')) {
                $coach->Company_or_School = $validated['Company_or_School'];
            }

            if ($request->has('Bio')) {
                $coach->Bio = $validated['Bio'];
            }

            if ($request->has('Years_Of_Experience')) {
                $coach->Years_Of_Experience = $validated['Years_Of_Experience'];
            }

            if ($request->has('Months_Of_Experience')) {
                $coach->Months_Of_Experience = $validated['Months_Of_Experience'];
            }

            if ($request->has('Skills')) {
                // حذف المهارات القديمة
                CoachSkill::where('coach_id', $user->User_ID)->delete();
                // إضافة المهارات الجديدة
                foreach ($validated['Skills'] as $skill) {
                    CoachSkill::create([
                        'coach_id' => $user->User_ID,
                        'Skill' => $skill
                    ]);
                }
            }

            if ($request->has('Languages')) {
                // حذف اللغات القديمة
                CoachLanguage::where('coach_id', $user->User_ID)->delete();
                // إضافة اللغات الجديدة
                foreach ($validated['Languages'] as $language) {
                    CoachLanguage::create([
                        'coach_id' => $user->User_ID,
                        'Language' => $language
                    ]);
                }
            }

            if ($request->has('availability')) {
                // حذف الـ availability القديمة
                CoachAvailability::where('User_ID', $user->User_ID)->delete();
                // إضافة الـ availability الجديدة
                $this->setAvailability($user->User_ID, $validated['availability']);
            }

            $coach->save();
        } elseif ($isTrainee) {
            $trainee = Trainee::where('User_ID', $user->User_ID)->first();

            if ($request->has('Education_Level')) {
                $trainee->Education_Level = $validated['Education_Level'];
            }

            if ($request->has('Institution_Or_School')) {
                $trainee->Institution_Or_School = $validated['Institution_Or_School'];
            }

            if ($request->has('Story')) {
                $trainee->Story = $validated['Story'];
            }

            if ($request->has('Field_Of_Study')) {
                $trainee->Field_Of_Study = $validated['Field_Of_Study'];
            }

            if ($request->has('Years_Of_Professional_Experience')) {
                $trainee->Years_Of_Professional_Experience = $validated['Years_Of_Professional_Experience'];
            }

            if ($request->has('Current_Role')) {
                $trainee->Current_Role = $validated['Current_Role'];
            }

            if ($request->has('Preferred_Languages')) {
                // حذف اللغات القديمة
                TraineePreferredLanguage::where('trainee_id', $user->User_ID)->delete();
                // إضافة اللغات الجديدة
                foreach ($validated['Preferred_Languages'] as $language) {
                    TraineePreferredLanguage::create([
                        'trainee_id' => $user->User_ID,
                        'Language' => $language
                    ]);
                }
            }

            $trainee->save();
        }

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user,
        ]);
    });
}
    
        
       
}
 
