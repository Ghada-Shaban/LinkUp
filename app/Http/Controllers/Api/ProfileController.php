<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coach;
use App\Models\CoachAvailability;
use App\Models\CoachLanguage;
use App\Models\CoachSkill;
use App\Models\Trainee;
use App\Models\TraineeAreaOfInterest;
use App\Models\TraineePreferredLanguage;
use App\Models\User;
use App\Models\Review;
use App\Http\Resources\ReviewResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;

class ProfileController extends Controller
{
    /**
     * Get ENUM values from a database table column.
     *
     * @param string $table
     * @param string $column
     * @return array
     */
    private function getEnumValues(string $table, string $column): array
    {
        $type = DB::select("SHOW COLUMNS FROM {$table} WHERE Field = ?", [$column])[0]->Type;
        preg_match_all("/'([^']*)'/", $type, $matches);
        return $matches[1] ?? [];
    }

    
  /**
 * تحديث بيانات المدرب
 */
public function updateCoachProfile(Request $request)
{
    $user = auth('sanctum')->user();
    if (!$user) {
        return response()->json(['message' => 'Unauthorized'], 401);
    }

    if ($user->Role_Profile !== 'Coach') {
        return response()->json(['message' => 'User is not a Coach'], 403);
    }

    try {
        DB::beginTransaction();

        // التحقق من البيانات للتحديث
        $validated = $request->validate([
            'Full_Name' => 'sometimes|string|max:255',
            'Email' => [
                'sometimes', 'email',
                Rule::unique('users', 'email')->ignore($user->User_ID, 'User_ID'),
            ],
            'Linkedin_Link' => 'sometimes|nullable|url',
            'Photo' => 'sometimes|nullable|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        // معالجة الصورة إذا تم تقديمها
        if ($request->hasFile('Photo')) {
            // إذا كانت هناك صورة قديمة، احذفها أولاً
            if ($user->Photo) {
                if ($user->Photo_Public_ID) {
                    // حذف الصورة من Cloudinary إذا كان هناك معرف عام
                    Cloudinary::destroy($user->Photo_Public_ID);
                } else {
                    // حذف الصورة من التخزين المحلي
                    Storage::disk('public')->delete($user->Photo);
                }
            }

            // رفع الصورة الجديدة إلى Cloudinary
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
            
            $validated['Photo'] = $result->getSecurePath();
            $validated['Photo_Public_ID'] = $result->getPublicId();
        }

        // تحديث بيانات المستخدم
        $user->update($validated);

        // تحديث المعلومات الخاصة بالمدرب
        if ($user->coach) {
            $coachData = [];
            if ($request->has('Title')) {
                $coachData['Title'] = $request->Title;
            }
            if ($request->has('Company_or_School')) {
                $coachData['Company_or_School'] = $request->Company_or_School;
            }
            if ($request->has('Bio')) {
                $coachData['Bio'] = $request->Bio;
            }
            if ($request->has('Years_Of_Experience')) {
                $coachData['Years_Of_Experience'] = $request->Years_Of_Experience;
            }
            if ($request->has('Months_Of_Experience')) {
                $coachData['Months_Of_Experience'] = $request->Months_Of_Experience;
            }
            
            if (!empty($coachData)) {
                $user->coach()->update($coachData);
            }
            
            // تحديث المهارات إذا تم تقديمها
            if ($request->has('Skills')) {
                $validSkills = $this->getEnumValues('coach_skills', 'Skill');
                $request->validate([
                    'Skills' => 'array|min:1',
                    'Skills.*' => ['string', Rule::in($validSkills)],
                ]);
                
                // حذف المهارات القديمة وإضافة الجديدة
                CoachSkill::where('coach_id', $user->User_ID)->delete();
                foreach ($request->Skills as $skill) {
                    CoachSkill::create([
                        'coach_id' => $user->User_ID,
                        'Skill' => $skill
                    ]);
                }
            }
            
            // تحديث اللغات إذا تم تقديمها
            if ($request->has('Languages')) {
                $validLanguages = $this->getEnumValues('coach_languages', 'Language');
                $request->validate([
                    'Languages' => 'array|min:1',
                    'Languages.*' => ['string', Rule::in($validLanguages)],
                ]);
                
                // حذف اللغات القديمة وإضافة الجديدة
                CoachLanguage::where('coach_id', $user->User_ID)->delete();
                foreach ($request->Languages as $language) {
                    CoachLanguage::create([
                        'coach_id' => $user->User_ID,
                        'Language' => $language
                    ]);
                }
            }
            
            // تحديث أوقات التوفر إذا تم تقديمها
            if ($request->has('availability')) {
                $request->validate([
                    'availability' => 'array', 
                    'availability.days' => 'required_with:availability|array',
                    'availability.days.*' => 'in:Sunday,Monday,Tuesday,Wednesday,Thursday,Friday,Saturday',
                    'availability.time_slots' => 'required_with:availability|array',
                    'availability.time_slots.*' => 'array',
                    'availability.time_slots.*.*.start_time' => 'required_with:availability|date_format:H:i',
                    'availability.time_slots.*.*.end_time' => 'required_with:availability|date_format:H:i|after:availability.time_slots.*.*.start_time',
                ]);
                
                // حذف أوقات التوفر القديمة
                CoachAvailability::where('coach_id', $user->User_ID)->delete();
                
                // إضافة أوقات التوفر الجديدة
                $this->setAvailability($user->User_ID, $request->availability);
            }
        }

        DB::commit();
        
        // إعادة تحميل علاقات المستخدم للحصول على البيانات المحدثة
        $user->load('coach', 'coach.skills', 'coach.languages', 'coach.availableTimes');
        
        return response()->json([
            'message' => 'Coach profile updated successfully',
            'user' => $user
        ]);
        
    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'message' => 'Failed to update coach profile',
            'error' => $e->getMessage(),
        ], 500);
    }
}

/**
 * تحديث بيانات المتدرب
 */
public function updateTraineeProfile(Request $request)
{
    $user = auth('sanctum')->user();
    if (!$user) {
        return response()->json(['message' => 'Unauthorized'], 401);
    }

    if ($user->Role_Profile !== 'Trainee') {
        return response()->json(['message' => 'User is not a Trainee'], 403);
    }

    try {
        DB::beginTransaction();

        // التحقق من البيانات للتحديث
        $validated = $request->validate([
            'Full_Name' => 'sometimes|string|max:255',
            'Email' => [
                'sometimes', 'email',
                Rule::unique('users', 'email')->ignore($user->User_ID, 'User_ID'),
            ],
            'Linkedin_Link' => 'sometimes|nullable|url',
            'Photo' => 'sometimes|nullable|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        // معالجة الصورة إذا تم تقديمها
        if ($request->hasFile('Photo')) {
            // إذا كانت هناك صورة قديمة، احذفها أولاً
            if ($user->Photo) {
                if ($user->Photo_Public_ID) {
                    // حذف الصورة من Cloudinary إذا كان هناك معرف عام
                    Cloudinary::destroy($user->Photo_Public_ID);
                } else {
                    // حذف الصورة من التخزين المحلي
                    Storage::disk('public')->delete($user->Photo);
                }
            }

            // رفع الصورة الجديدة إلى Cloudinary
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
            
            $validated['Photo'] = $result->getSecurePath();
            $validated['Photo_Public_ID'] = $result->getPublicId();
        }

        // تحديث بيانات المستخدم
        $user->update($validated);

        // تحديث المعلومات الخاصة بالمتدرب
        if ($user->trainee) {
            $traineeData = [];
            if ($request->has('Education_Level')) {
                $validEducationLevels = $this->getEnumValues('trainees', 'Education_Level');
                $request->validate([
                    'Education_Level' => ['string', Rule::in($validEducationLevels)],
                ]);
                $traineeData['Education_Level'] = $request->Education_Level;
            }
            if ($request->has('Institution_Or_School')) {
                $traineeData['Institution_Or_School'] = $request->Institution_Or_School;
            }
            if ($request->has('Field_Of_Study')) {
                $traineeData['Field_Of_Study'] = $request->Field_Of_Study;
            }
            if ($request->has('Current_Role')) {
                $traineeData['Current_Role'] = $request->Current_Role;
            }
            if ($request->has('Story')) {
                $traineeData['Story'] = $request->Story;
            }
            if ($request->has('Years_Of_Professional_Experience')) {
                $traineeData['Years_Of_Professional_Experience'] = $request->Years_Of_Professional_Experience;
            }
            
            if (!empty($traineeData)) {
                $user->trainee()->update($traineeData);
            }
            
            // تحديث اللغات المفضلة إذا تم تقديمها
            if ($request->has('Preferred_Languages')) {
                $validLanguages = $this->getEnumValues('trainee_preferred_languages', 'Language');
                $request->validate([
                    'Preferred_Languages' => 'array|min:1',
                    'Preferred_Languages.*' => ['string', Rule::in($validLanguages)],
                ]);
                
                // حذف اللغات القديمة وإضافة الجديدة
                TraineePreferredLanguage::where('trainee_id', $user->User_ID)->delete();
                foreach ($request->Preferred_Languages as $lang) {
                    TraineePreferredLanguage::create([
                        'trainee_id' => $user->User_ID,
                        'Language' => $lang
                    ]);
                }
            }
            
            // تحديث مجالات الاهتمام إذا تم تقديمها
            if ($request->has('Areas_Of_Interest')) {
                $validInterests = $this->getEnumValues('trainee_areas_of_interest', 'Area_Of_Interest');
                $request->validate([
                    'Areas_Of_Interest' => 'array|min:1',
                    'Areas_Of_Interest.*' => ['string', Rule::in($validInterests)],
                ]);
                
                // حذف مجالات الاهتمام القديمة وإضافة الجديدة
                TraineeAreaOfInterest::where('trainee_id', $user->User_ID)->delete();
                foreach ($request->Areas_Of_Interest as $interest) {
                    TraineeAreaOfInterest::create([
                        'trainee_id' => $user->User_ID,
                        'Area_Of_Interest' => $interest
                    ]);
                }
            }
        }

        DB::commit();
        
        // إعادة تحميل علاقات المستخدم للحصول على البيانات المحدثة
        $user->load('trainee', 'trainee.preferredLanguages', 'trainee.areasOfInterest');
        
        return response()->json([
            'message' => 'Trainee profile updated successfully',
            'user' => $user
        ]);
        
    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'message' => 'Failed to update trainee profile',
            'error' => $e->getMessage(),
        ], 500);
    }
}

    private function setAvailability(int $userID, array $availability): array
    {
        $savedSlots = [];

        try {
            foreach ($availability['days'] as $day) {
                if (isset($availability['time_slots'][$day])) {
                    foreach ($availability['time_slots'][$day] as $slot) {
                        $existingSlots = CoachAvailability::where('coach_id', $userID)
                            ->where('Day_Of_Week', $day)
                            ->where(function ($query) use ($slot) {
                                $query->whereBetween('Start_Time', [$slot['start_time'], $slot['end_time']])
                                    ->orWhereBetween('End_Time', [$slot['start_time'], $slot['end_time']])
                                    ->orWhere(function ($q) use ($slot) {
                                        $q->where('Start_Time', '<', $slot['start_time'])
                                          ->where('End_Time', '>', $slot['end_time']);
                                    });
                            })
                            ->where('Start_Time', '!=', $slot['start_time'])
                            ->exists();

                        if ($existingSlots) {
                            throw new \Exception("Time slot overlaps with an existing slot on $day");
                        }

                        $availabilityRecord = CoachAvailability::updateOrCreate(
                            [
                                'coach_id' => $userID,
                                'Day_Of_Week' => $day,
                                'Start_Time' => $slot['start_time'],
                            ],
                            [
                                'End_Time' => $slot['end_time'],
                            ]
                        );

                        $savedSlots[] = $availabilityRecord;
                    }
                }
            }
        } catch (\Exception $e) {
            throw new \Exception("Error saving availability: " . $e->getMessage());
        }

        return $savedSlots;
    }
    /**
     * Get Coach profile data.
     *
     * @param int $user_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCoachProfile(int $user_id)
    {
        $user = User::where('User_ID', $user_id)->where('Role_Profile', 'Coach')->first();
        if (!$user) {
            return response()->json(['message' => 'Coach not found'], 404);
        }

        $coach = Coach::where('User_ID', $user_id)->first();
        $languages = CoachLanguage::where('coach_id', $user_id)->pluck('Language');
        $skills = CoachSkill::where('coach_id', $user_id)->pluck('Skill');
        $availabilityData = CoachAvailability::where('coach_id', $user_id)
        ->get()
        ->groupBy('Day_Of_Week')
        ->map(function ($slots) {
            return $slots->map(function ($slot) {
                return [
                    'start_time' => \Carbon\Carbon::parse($slot->Start_Time)->format('H:i'),
                    'end_time' => \Carbon\Carbon::parse($slot->End_Time)->format('H:i'),
                ];
            });
        });
        $availability = [
        'days' => $availabilityData->keys()->toArray(), // Extract the days as an array
        'time_slots' => $availabilityData->toArray(),   // Use the grouped data as time_slots
    ];

        return response()->json([
            'message' => 'Coach profile retrieved successfully',
            'profile' => [
                'User_ID' => $user->User_ID,
                'Full_Name' => $user->full_name,
                'Email' => $user->email,
                'Photo' => !empty($user->photo) ? url(Storage::url($user->photo)) : null,
                'Bio' => $coach->Bio ?? null,
                'Languages' => $languages,
                'Company_or_School' => $coach->Company_or_School ?? null,
                'Skills' => $skills,
                'Title' => $coach->Title ?? null,
                'Years_Of_Experience' => $coach->Years_Of_Experience ?? 0,
                'Months_Of_Experience' => $coach->Months_Of_Experience ?? 0,
                'Linkedin_Link' => $user->linkedin_link ?? null,
                'availability' => $availability,
            ],
        ], 200);
    }

    /**
     * Get Trainee profile data.
     *
     * @param int $user_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTraineeProfile(int $user_id): \Illuminate\Http\JsonResponse
    {
        $user = User::where('User_ID', $user_id)->where('Role_Profile', 'Trainee')->first();
        if (!$user) {
            return response()->json(['message' => 'Trainee not found'], 404);
        }

        $trainee = Trainee::where('User_ID', $user_id)->first();
        $languages = TraineePreferredLanguage::where('trainee_id', $user_id)->pluck('Language');
        $interests = TraineeAreaOfInterest::where('trainee_id', $user_id)->pluck('Area_Of_Interest');

        return response()->json([
            'message' => 'Trainee profile retrieved successfully',
            'profile' => [
                'User_ID' => $user->User_ID,
                'Full_Name' => $user->full_name,
                'Email' => $user->email,
                'Photo' => !empty($user->photo) ? url(Storage::url($user->photo)) : null,
                'Story' => $trainee->Story ?? null,
                'Preferred_Languages' => $languages,
                'Institution_Or_School' => $trainee->Institution_Or_School ?? null,
                'Areas_Of_Interest' => $interests,
                'Current_Role' => $trainee->Current_Role ?? null,
                'Education_Level' => $trainee->Education_Level ?? null,
                'Linkedin_Link' => $user->linkedin_link ?? null,
            ],
        ], 200);
    }

   
   

    /**
     * Get Coach profile data with reviews.
     *
     * @param int $user_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCoachProfile2(int $user_id): \Illuminate\Http\JsonResponse
    {
        $user = User::where('User_ID', $user_id)->where('Role_Profile', 'Coach')->first();
        if (!$user) {
            return response()->json(['message' => 'Coach not found'], 404);
        }

        $coach = Coach::where('User_ID', $user_id)->first();
        $languages = CoachLanguage::where('coach_id', $user_id)->pluck('Language');
        $skills = CoachSkill::where('coach_id', $user_id)->pluck('Skill');
        $reviews = Review::with(['trainee.user'])
            ->where('coach_id', $coach->User_ID)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'message' => 'Coach profile retrieved successfully',
            'profile' => [
                'User_ID' => $user->User_ID,
                'Full_Name' => $user->full_name,
                'Email' => $user->email,
                'Photo' => !empty($user->photo) ? url(Storage::url($user->photo)) : null,
                'Bio' => $coach->Bio ?? null,
                'Languages' => $languages,
                'Company_or_School' => $coach->Company_or_School ?? null,
                'Skills' => $skills,
                'Title' => $coach->Title ?? null,
                'Years_Of_Experience' => $coach->Years_Of_Experience ?? 0,
                'Months_Of_Experience' => $coach->Months_Of_Experience ?? 0,
                'Linkedin_Link' => $user->linkedin_link ?? null,
                'reviews' => ReviewResource::collection($reviews),
            ],
        ], 200);
    }
}
