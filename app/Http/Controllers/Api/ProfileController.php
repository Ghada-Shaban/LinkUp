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

    try {
      
        if (!isset($availability['days']) || !isset($availability['time_slots'])) {
            throw new \Exception("Invalid availability format: 'days' or 'time_slots' missing.");
        }

        foreach ($availability['days'] as $day) {
            if (!isset($availability['time_slots'][$day])) {
                continue; 
            }

            
            $timeSlots = $availability['time_slots'][$day];
            usort($timeSlots, fn($a, $b) => strcmp($a['start_time'], $b['start_time']));

          
            for ($i = 0; $i < count($timeSlots); $i++) {
                $currentSlot = $timeSlots[$i];

               
                if (!$this->isValidTimeFormat($currentSlot['start_time']) || !$this->isValidTimeFormat($currentSlot['end_time'])) {
                    throw new \Exception("Invalid time format for slot on $day: {$currentSlot['start_time']} - {$currentSlot['end_time']}");
                }

               
                if (strtotime($currentSlot['end_time']) <= strtotime($currentSlot['start_time'])) {
                    throw new \Exception("End time must be after start time for slot on $day: {$currentSlot['start_time']} - {$currentSlot['end_time']}");
                }

                
                if ($i > 0) {
                    $prevSlot = $timeSlots[$i - 1];
                    if ($prevSlot['end_time'] !== $currentSlot['start_time']) {
                        throw new \Exception("Non-consecutive time slots detected on $day: from {$prevSlot['end_time']} to {$currentSlot['start_time']}");
                    }
                }

              
                $existingSlot = CoachAvailability::where('coach_id', $userID)
                    ->where('Day_Of_Week', $day)
                    ->where(function ($query) use ($currentSlot) {
                        $query->where(function ($q) use ($currentSlot) {
                            $q->where('Start_Time', '>=', $currentSlot['start_time'])
                              ->where('Start_Time', '<', $currentSlot['end_time']);
                        })->orWhere(function ($q) use ($currentSlot) {
                            $q->where('End_Time', '>', $currentSlot['start_time'])
                              ->where('End_Time', '<=', $currentSlot['end_time']);
                        })->orWhere(function ($q) use ($currentSlot) {
                            $q->where('Start_Time', '<=', $currentSlot['start_time'])
                              ->where('End_Time', '>=', $currentSlot['end_time']);
                        });
                    })
                    ->first();

                if ($existingSlot) {
                    throw new \Exception("Time slot {$currentSlot['start_time']}-{$currentSlot['end_time']} on $day overlaps with existing slot {$existingSlot->Start_Time}-{$existingSlot->End_Time}");
                }

               
                $availabilityRecord = CoachAvailability::updateOrCreate(
                    [
                        'coach_id' => $userID,
                        'Day_Of_Week' => $day,
                        'Start_Time' => $currentSlot['start_time'],
                        'End_Time' => $currentSlot['end_time'],
                    ],
                    [
                        'End_Time' => $currentSlot['end_time'], 
                    ]
                );

                $savedSlots[] = $availabilityRecord;
            }
        }
    } catch (\Exception $e) {
        throw new \Exception("Error saving availability: " . $e->getMessage());
    }

    return $savedSlots;
}


private function isValidTimeFormat(string $time)
{
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time);
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
