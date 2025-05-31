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
    private function getEnumValues(string $table, string $column): array
    {
        $type = DB::select("SHOW COLUMNS FROM {$table} WHERE Field = ?", [$column])[0]->Type;
        preg_match_all("/'([^']*)'/", $type, $matches);
        return $matches[1] ?? [];
    }

    
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
        $validated = $request->validate([
            'Full_Name' => 'sometimes|string|max:255',
            'Email' => [
                'sometimes', 'email',
                Rule::unique('users', 'email')->ignore($user->User_ID, 'User_ID'),
            ],
            'Linkedin_Link' => 'sometimes|nullable|url',
            'Photo' => 'sometimes|nullable|image|mimes:jpeg,png,jpg|max:2048',
            'Title' => 'sometimes|string|max:100',
            'Company_or_School' => 'sometimes|string|max:255',
            'Bio' => 'sometimes|string',
            'Years_Of_Experience' => 'sometimes|integer|min:0',
            'Months_Of_Experience' => 'sometimes|integer|between:0,11',
            'Skills' => 'sometimes|array|min:1',
            'Skills.*' => ['string', Rule::in($this->getEnumValues('coach_skills', 'Skill'))],
            'Languages' => 'sometimes|array|min:1',
            'Languages.*' => ['string', Rule::in($this->getEnumValues('coach_languages', 'Language'))],
            'availability' => 'sometimes|array',
            'availability.days' => 'required_with:availability|array',
            'availability.days.*' => 'in:Sunday,Monday,Tuesday,Wednesday,Thursday,Friday,Saturday',
            'availability.time_slots' => 'required_with:availability|array',
            'availability.time_slots.*' => 'array',
            'availability.time_slots.*.*.start_time' => 'required_with:availability|date_format:H:i',
            'availability.time_slots.*.*.end_time' => 'required_with:availability|date_format:H:i|after:availability.time_slots.*.*.start_time',
        ]);

        if ($request->hasFile('Photo')) {
            if ($user->Photo) {
                if ($user->Photo_Public_ID) {
                    Cloudinary::destroy($user->Photo_Public_ID);
                } else {
                    Storage::disk('public')->delete($user->Photo);
                }
            }

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
        
        $user->update($validated);

        if ($user->coach) {
            $coachData = [];
            if ($request->has('Title')) {
                $coachData['Title'] = $request->input('Title');
            }
            if ($request->has('Company_or_School')) {
                $coachData['Company_or_School'] = $request->input('Company_or_School');
            }
            if ($request->has('Bio')) {
                $coachData['Bio'] = $request->input('Bio');
            }
            if ($request->has('Years_Of_Experience')) {
                $coachData['Years_Of_Experience'] = $request->input('Years_Of_Experience');
            }
            if ($request->has('Months_Of_Experience')) {
                $coachData['Months_Of_Experience'] = $request->input('Months_Of_Experience');
            }
            
            if (!empty($coachData)) {
                $user->coach()->update($coachData);
            }
            
            if ($request->has('Skills')) {
                CoachSkill::where('coach_id', $user->User_ID)->delete();
                foreach ($request->input('Skills', []) as $skill) {
                    CoachSkill::create([
                        'coach_id' => $user->User_ID,
                        'Skill' => $skill
                    ]);
                }
            }
            
            if ($request->has('Languages')) {
                CoachLanguage::where('coach_id', $user->User_ID)->delete();
                foreach ($request->input('Languages', []) as $language) {
                    CoachLanguage::create([
                        'coach_id' => $user->User_ID,
                        'Language' => $language
                    ]);
                }
            }
            
            if ($request->has('availability')) {
                CoachAvailability::where('coach_id', $user->User_ID)->delete();
                $this->setAvailability($user->User_ID, $request->input('availability'));
            }
        }

        DB::commit();
        $user->load('coach', 'coach.skills', 'coach.languages', 'coach.availableTimes');
        
        return response()->json([
            'message' => 'Coach profile updated successfully',
           
        ]);
        
    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'message' => 'Failed to update coach profile',
            'error' => $e->getMessage(),
        ], 500);
    }
}

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
        $validated = $request->validate([
            'Full_Name' => 'sometimes|string|max:255',
            'Email' => [
                'sometimes', 'email',
                Rule::unique('users', 'email')->ignore($user->User_ID, 'User_ID'),
            ],
            'Linkedin_Link' => 'sometimes|nullable|url',
            'Photo' => 'sometimes|nullable|image|mimes:jpeg,png,jpg|max:2048',
            'Education_Level' => 'sometimes|string',
            'Institution_Or_School' => 'sometimes|string|max:255',
            'Field_Of_Study' => 'sometimes|string',
            'Current_Role' => 'sometimes|string',
            'Story' => 'sometimes|string',
            'Years_Of_Professional_Experience' => 'sometimes|integer|min:0',
            'Preferred_Languages' => 'sometimes|array|min:1',
            'Preferred_Languages.*' => ['string', Rule::in($this->getEnumValues('trainee_preferred_languages', 'Language'))],
            'Areas_Of_Interest' => 'sometimes|array|min:1',
            'Areas_Of_Interest.*' => ['string', Rule::in($this->getEnumValues('trainee_areas_of_interest', 'Area_Of_Interest'))],
        ]);

        if ($request->hasFile('Photo')) {
            if ($user->Photo) {
                if ($user->Photo_Public_ID) {
                    Cloudinary::destroy($user->Photo_Public_ID);
                } else {
                    Storage::disk('public')->delete($user->Photo);
                }
            }
            
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

        $user->update($validated);
        if ($user->trainee) {
            $traineeData = [];
            if ($request->has('Education_Level')) {
                $traineeData['Education_Level'] = $request->input('Education_Level');
            }
            if ($request->has('Institution_Or_School')) {
                $traineeData['Institution_Or_School'] = $request->input('Institution_Or_School');
            }
            if ($request->has('Field_Of_Study')) {
                $traineeData['Field_Of_Study'] = $request->input('Field_Of_Study');
            }
            if ($request->has('Current_Role')) {
                $traineeData['Current_Role'] = $request->input('Current_Role');
            }
            if ($request->has('Story')) {
                $traineeData['Story'] = $request->input('Story');
            }
            if ($request->has('Years_Of_Professional_Experience')) {
                $traineeData['Years_Of_Professional_Experience'] = $request->input('Years_Of_Professional_Experience');
            }
            
            if (!empty($traineeData)) {
                $user->trainee()->update($traineeData);
            }
            
            if ($request->has('Preferred_Languages')) {
                TraineePreferredLanguage::where('trainee_id', $user->User_ID)->delete();
                foreach ($request->input('Preferred_Languages', []) as $lang) {
                    TraineePreferredLanguage::create([
                        'trainee_id' => $user->User_ID,
                        'Language' => $lang
                    ]);
                }
            }
            
            if ($request->has('Areas_Of_Interest')) {
                TraineeAreaOfInterest::where('trainee_id', $user->User_ID)->delete();
                foreach ($request->input('Areas_Of_Interest', []) as $interest) {
                    TraineeAreaOfInterest::create([
                        'trainee_id' => $user->User_ID,
                        'Area_Of_Interest' => $interest
                    ]);
                }
            }
        }

        DB::commit();
        $user->load('trainee', 'trainee.preferredLanguages', 'trainee.areasOfInterest');  
        return response()->json([
            'message' => 'Trainee profile updated successfully',
            
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
    
    \DB::beginTransaction();
    
    try {
        foreach ($availability['days'] as $day) {
            if (isset($availability['time_slots'][$day])) {
                $daySlots = $availability['time_slots'][$day];
                
                // التحقق من عدم وجود تداخل في الأوقات المرسلة
                $this->validateDaySlots($daySlots, $day);
                
                // ترتيب الأوقات حسب وقت البداية
                usort($daySlots, function($a, $b) {
                    return strtotime($a['start_time']) - strtotime($b['start_time']);
                });
                
                // دمج الأوقات المتتالية
                $mergedSlots = $this->mergeConsecutiveSlots($daySlots);
                
                // التحقق من عدم التداخل مع الأوقات الموجودة في قاعدة البيانات
                foreach ($mergedSlots as $slot) {
                    $this->checkOverlapWithExistingSlots($userID, $day, $slot);
                }
                
                // حذف الأوقات القديمة لهذا اليوم
                CoachAvailability::where('coach_id', $userID)
                                ->where('Day_Of_Week', $day)
                                ->delete();
                
                // إضافة الأوقات الجديدة
                foreach ($mergedSlots as $slot) {
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
        
        \DB::commit();
        
    } catch (\Exception $e) {
        \DB::rollback();
        throw new \Exception("Error saving availability: " . $e->getMessage());
    }
    
    return $savedSlots;
}

/**
 * التحقق من صحة الأوقات المرسلة لليوم الواحد
 */
private function validateDaySlots(array $slots, string $day): void
{
    $count = count($slots);
    
    // التحقق من صحة كل وقت
    foreach ($slots as $slot) {
        if (!isset($slot['start_time']) || !isset($slot['end_time'])) {
            throw new \Exception("Missing start_time or end_time for day: $day");
        }
        
        $startTime = strtotime($slot['start_time']);
        $endTime = strtotime($slot['end_time']);
        
        if ($startTime >= $endTime) {
            throw new \Exception("Invalid time slot on $day: Start time must be before end time");
        }
    }
    
    // التحقق من عدم التداخل بين الأوقات المرسلة
    for ($i = 0; $i < $count; $i++) {
        $startTime1 = strtotime($slots[$i]['start_time']);
        $endTime1 = strtotime($slots[$i]['end_time']);
        
        for ($j = $i + 1; $j < $count; $j++) {
            $startTime2 = strtotime($slots[$j]['start_time']);
            $endTime2 = strtotime($slots[$j]['end_time']);
            
            // التحقق من التداخل (لكن السماح بالمتتالي)
            if ($this->slotsOverlap($startTime1, $endTime1, $startTime2, $endTime2)) {
                throw new \Exception("Overlapping time slots found on $day: " . 
                    $slots[$i]['start_time'] . "-" . $slots[$i]['end_time'] . 
                    " overlaps with " . 
                    $slots[$j]['start_time'] . "-" . $slots[$j]['end_time']);
            }
        }
    }
}

/**
 * التحقق من تداخل فترتين زمنيتين (مع السماح بالمتتالي)
 */
private function slotsOverlap($start1, $end1, $start2, $end2): bool
{
    // السماح بالأوقات المتتالية (نهاية الأول = بداية الثاني)
    // منع التداخل الفعلي فقط
    return ($start1 < $end2) && ($start2 < $end1);
}

/**
 * دمج الأوقات المتتالية
 */
private function mergeConsecutiveSlots(array $slots): array
{
    if (empty($slots)) {
        return $slots;
    }
    
    // ترتيب الأوقات حسب وقت البداية
    usort($slots, function($a, $b) {
        return strtotime($a['start_time']) - strtotime($b['start_time']);
    });
    
    $merged = [];
    $current = $slots[0];
    
    for ($i = 1; $i < count($slots); $i++) {
        $next = $slots[$i];
        
        $currentEnd = strtotime($current['end_time']);
        $nextStart = strtotime($next['start_time']);
        
        // دمج إذا كانت الأوقات متتالية أو متداخلة (بدون فجوة)
        if ($currentEnd >= $nextStart) {
            // دمج الأوقات - أخذ أطول نهاية
            $current['end_time'] = date('H:i:s', max(
                $currentEnd, 
                strtotime($next['end_time'])
            ));
        } else {
            // إضافة الوقت الحالي للنتيجة والانتقال للتالي
            $merged[] = $current;
            $current = $next;
        }
    }
    
    // إضافة آخر وقت
    $merged[] = $current;
    
    return $merged;
}

/**
 * التحقق من التداخل مع الأوقات الموجودة في قاعدة البيانات
 */
private function checkOverlapWithExistingSlots(int $userID, string $day, array $slot): void
{
    $startTime = $slot['start_time'];
    $endTime = $slot['end_time'];
    
    $overlappingSlots = CoachAvailability::where('coach_id', $userID)
        ->where('Day_Of_Week', $day)
        ->where(function ($query) use ($startTime, $endTime) {
            $query->where(function ($q) use ($startTime, $endTime) {
                // التحقق من جميع حالات التداخل
                $q->where(function ($subQ) use ($startTime, $endTime) {
                    // الوقت الجديد يبدأ أثناء وقت موجود
                    $subQ->where('Start_Time', '<', $startTime)
                         ->where('End_Time', '>', $startTime);
                })
                ->orWhere(function ($subQ) use ($startTime, $endTime) {
                    // الوقت الجديد ينتهي أثناء وقت موجود
                    $subQ->where('Start_Time', '<', $endTime)
                         ->where('End_Time', '>', $endTime);
                })
                ->orWhere(function ($subQ) use ($startTime, $endTime) {
                    // الوقت الجديد يحتوي على وقت موجود
                    $subQ->where('Start_Time', '>=', $startTime)
                         ->where('End_Time', '<=', $endTime);
                })
                ->orWhere(function ($subQ) use ($startTime, $endTime) {
                    // الوقت الموجود يحتوي على الوقت الجديد
                    $subQ->where('Start_Time', '<=', $startTime)
                         ->where('End_Time', '>=', $endTime);
                });
            });
        })
        ->exists();
    
    if ($overlappingSlots) {
        throw new \Exception("Time slot $startTime-$endTime overlaps with existing slots on $day");
    }
}

/**
 * نسخة مبسطة للتحقق من التداخل مع قاعدة البيانات
 */
private function checkOverlapWithExistingSlotsSimple(int $userID, string $day, array $slot): void
{
    $existingSlots = CoachAvailability::where('coach_id', $userID)
        ->where('Day_Of_Week', $day)
        ->get(['Start_Time', 'End_Time']);
    
    $newStart = strtotime($slot['start_time']);
    $newEnd = strtotime($slot['end_time']);
    
    foreach ($existingSlots as $existing) {
        $existingStart = strtotime($existing->Start_Time);
        $existingEnd = strtotime($existing->End_Time);
        
        // التحقق من التداخل (مع السماح بالمتتالي)
        if ($this->slotsOverlap($newStart, $newEnd, $existingStart, $existingEnd)) {
            throw new \Exception("Time slot {$slot['start_time']}-{$slot['end_time']} overlaps with existing slot {$existing->Start_Time}-{$existing->End_Time} on $day");
        }
    }
}



   
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
                'Photo' => !empty($user->photo) ? (filter_var($user->photo, FILTER_VALIDATE_URL) ? $user->photo : url(Storage::url($user->photo))) : null,
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
    public function getTraineeProfile(int $user_id)
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
                'Photo' => !empty($user->photo) ? (filter_var($user->photo, FILTER_VALIDATE_URL) ? $user->photo : url(Storage::url($user->photo))) : null,
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

    public function getCoachProfile2(int $user_id)
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
                'Photo' => !empty($user->photo) ? (filter_var($user->photo, FILTER_VALIDATE_URL) ? $user->photo : url(Storage::url($user->photo))) : null,
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
