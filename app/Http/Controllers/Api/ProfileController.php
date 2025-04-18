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
     * Update the authenticated user's profile (Coach or Trainee).
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateProfile(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = auth('sanctum')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $user->Role_Profile === 'Coach'
            ? $this->updateCoachProfile($user, $request)
            : $this->updateTraineeProfile($user, $request);
    }

    /**
     * Update Coach profile details.
     *
     * @param User $user
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    private function updateCoachProfile(User $user, Request $request): \Illuminate\Http\JsonResponse
    {
        $validSkills = $this->getEnumValues('coach_skills', 'Skill');
        $validLanguages = $this->getEnumValues('coach_languages', 'Language');

        $validated = $request->validate([
            // Coach Profile
            'Full_Name' => ['sometimes', 'string', 'max:255'],
            'Email' => ['sometimes', 'email', Rule::unique('users', 'Email')->ignore($user->User_ID, 'User_ID')],
            'Password' => ['sometimes', 'string', 'min:8'],
            'Photo' => ['sometimes', 'image', 'mimes:jpeg,png,jpg', 'max:5120'],
            'Bio' => ['sometimes', 'string'],
            'Languages' => ['sometimes', 'array', 'min:1'],
            'Languages.*' => ['required', 'string', Rule::in($validLanguages)],

            // Personal Info
            'Company_or_School' => ['sometimes', 'string', 'max:255'],
            'Skills' => ['sometimes', 'array', 'min:1'],
            'Skills.*' => ['required', 'string', Rule::in($validSkills)],
            'Title' => ['sometimes', 'string', 'max:100'],
            'Years_Of_Experience' => ['sometimes', 'integer', 'min:0'],
            'Months_Of_Experience' => ['sometimes', 'integer', 'between:0,11'],
            'Linkedin_Link' => ['sometimes', 'url'],

            // Availability
            'availability' => ['sometimes', 'array'],
            'availability.days' => ['required_with:availability', 'array'],
            'availability.days.*' => ['in:Sunday,Monday,Tuesday,Wednesday,Thursday,Friday,Saturday'],
            'availability.time_slots' => ['required_with:availability', 'array'],
            'availability.time_slots.*' => ['array'],
            'availability.time_slots.*.*.start_time' => ['required_with:availability', 'date_format:H:i'],
            'availability.time_slots.*.*.end_time' => ['required_with:availability', 'date_format:H:i', 'after:availability.time_slots.*.*.start_time'],
        ]);

        return DB::transaction(function () use ($user, $validated, $request) {
            // Update user table (users)
            $updateData = [];
            if (isset($validated['Full_Name'])) {
                $updateData['full_name'] = $validated['Full_Name'];
            }
            if (isset($validated['Email'])) {
                $updateData['email'] = $validated['Email'];
            }
            if (isset($validated['Password'])) {
                $updateData['password'] = Hash::make($validated['Password']);
            }
            if (isset($validated['Linkedin_Link'])) {
                $updateData['linkedin_link'] = $validated['Linkedin_Link'];
            }

            if ($request->hasFile('Photo')) {
                if ($user->photo) {
                    Storage::disk('public')->delete($user->photo);
                }
                $updateData['photo'] = $request->file('Photo')->store('photos', 'public');
            }

            if (!empty($updateData)) {
                $user->update($updateData);
            }

            // Update coach table (coaches)
            $coach = Coach::where('User_ID', $user->User_ID)->first();
            if ($coach) {
                $coachUpdateData = [];
                if (isset($validated['Bio'])) {
                    $coachUpdateData['Bio'] = $validated['Bio'];
                }
                if (isset($validated['Company_or_School'])) {
                    $coachUpdateData['Company_or_School'] = $validated['Company_or_School'];
                }
                if (isset($validated['Title'])) {
                    $coachUpdateData['Title'] = $validated['Title'];
                }
                if (isset($validated['Years_Of_Experience'])) {
                    $coachUpdateData['Years_Of_Experience'] = $validated['Years_Of_Experience'];
                }
                if (isset($validated['Months_Of_Experience'])) {
                    $coachUpdateData['Months_Of_Experience'] = $validated['Months_Of_Experience'];
                }

                if (!empty($coachUpdateData)) {
                    $coach->update($coachUpdateData);
                }
            }

            // Update skills
            if (isset($validated['Skills'])) {
                CoachSkill::where('coach_id', $user->User_ID)->delete();
                foreach ($validated['Skills'] as $skill) {
                    CoachSkill::create([
                        'coach_id' => $user->User_ID,
                        'Skill' => $skill,
                    ]);
                }
            }

            // Update languages
            if (isset($validated['Languages'])) {
                CoachLanguage::where('coach_id', $user->User_ID)->delete();
                foreach ($validated['Languages'] as $language) {
                    CoachLanguage::create([
                        'coach_id' => $user->User_ID,
                        'Language' => $language,
                    ]);
                }
            }

            // Update availability
            if (isset($validated['availability'])) {
                CoachAvailability::where('User_ID', $user->User_ID)->delete();
                $this->setAvailability($user->User_ID, $validated['availability']);
            }

            $updatedFields = array_keys($validated);
            return response()->json([
                'message' => 'Coach profile updated successfully',
                'updated_fields' => $updatedFields,
                'user' => $user->fresh(),
                'photo_path' => $user->photo ? Storage::url($user->photo) : null,
            ], 200);
        });
    }

    /**
     * Update Trainee profile details.
     *
     * @param User $user
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    private function updateTraineeProfile(User $user, Request $request): \Illuminate\Http\JsonResponse
    {
        $validLanguages = $this->getEnumValues('trainee_preferred_languages', 'Language');
        $validInterests = $this->getEnumValues('trainee_areas_of_interest', 'Area_Of_Interest');
        $validEducationLevels = $this->getEnumValues('trainees', 'Education_Level');

        $validated = $request->validate([
            // Trainee Profile
            'Full_Name' => ['sometimes', 'string', 'max:255'],
            'Email' => ['sometimes', 'email', Rule::unique('users', 'Email')->ignore($user->User_ID, 'User_ID')],
            'Password' => ['sometimes', 'string', 'min:8'],
            'Photo' => ['sometimes', 'image', 'mimes:jpeg,png,jpg', 'max:5120'],
            'Story' => ['sometimes', 'string'],
            'Preferred_Languages' => ['sometimes', 'array', 'min:1'],
            'Preferred_Languages.*' => ['required', 'string', Rule::in($validLanguages)],

            // Personal Info
            'Institution_Or_School' => ['sometimes', 'string', 'max:255'],
            'Areas_Of_Interest' => ['sometimes', 'array', 'min:1'],
            'Areas_Of_Interest.*' => ['required', 'string', Rule::in($validInterests)],
            'Current_Role' => ['sometimes', 'string'],
            'Education_Level' => ['sometimes', 'string', Rule::in($validEducationLevels)],
            'Linkedin_Link' => ['sometimes', 'url'],
        ]);

        return DB::transaction(function () use ($user, $validated, $request) {
            // Update user table (users)
            $updateData = [];
            if (isset($validated['Full_Name'])) {
                $updateData['full_name'] = $validated['Full_Name'];
            }
            if (isset($validated['Email'])) {
                $updateData['email'] = $validated['Email'];
            }
            if (isset($validated['Password'])) {
                $updateData['password'] = Hash::make($validated['Password']);
            }
            if (isset($validated['Linkedin_Link'])) {
                $updateData['linkedin_link'] = $validated['Linkedin_Link'];
            }

            if ($request->hasFile('Photo')) {
                if ($user->photo) {
                    Storage::disk('public')->delete($user->photo);
                }
                $updateData['photo'] = $request->file('Photo')->store('photos', 'public');
            }

            if (!empty($updateData)) {
                $user->update($updateData);
            }

            // Update trainee table (trainees)
            $trainee = Trainee::where('User_ID', $user->User_ID)->first();
            if ($trainee) {
                $traineeUpdateData = [];
                if (isset($validated['Story'])) {
                    $traineeUpdateData['Story'] = $validated['Story'];
                }
                if (isset($validated['Institution_Or_School'])) {
                    $traineeUpdateData['Institution_Or_School'] = $validated['Institution_Or_School'];
                }
                if (isset($validated['Current_Role'])) {
                    $traineeUpdateData['Current_Role'] = $validated['Current_Role'];
                }
                if (isset($validated['Education_Level'])) {
                    $traineeUpdateData['Education_Level'] = $validated['Education_Level'];
                }

                if (!empty($traineeUpdateData)) {
                    $trainee->update($traineeUpdateData);
                }
            }

            // Update preferred languages
            if (isset($validated['Preferred_Languages'])) {
                TraineePreferredLanguage::where('trainee_id', $user->User_ID)->delete();
                foreach ($validated['Preferred_Languages'] as $lang) {
                    TraineePreferredLanguage::create([
                        'trainee_id' => $user->User_ID,
                        'Language' => $lang,
                    ]);
                }
            }

            // Update areas of interest
            if (isset($validated['Areas_Of_Interest'])) {
                TraineeAreaOfInterest::where('trainee_id', $user->User_ID)->delete();
                foreach ($validated['Areas_Of_Interest'] as $interest) {
                    TraineeAreaOfInterest::create([
                        'trainee_id' => $user->User_ID,
                        'Area_Of_Interest' => $interest,
                    ]);
                }
            }

            $updatedFields = array_keys($validated);
            return response()->json([
                'message' => 'Trainee profile updated successfully',
                'updated_fields' => $updatedFields,
                'user' => $user->fresh(),
                'photo_path' => $user->photo ? Storage::url($user->photo) : null,
            ], 200);
        });
    }

    /**
     * Get Coach profile data.
     *
     * @param int $user_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCoachProfile(int $user_id): \Illuminate\Http\JsonResponse
    {
        $user = User::where('User_ID', $user_id)->where('Role_Profile', 'Coach')->first();
        if (!$user) {
            return response()->json(['message' => 'Coach not found'], 404);
        }

        $coach = Coach::where('User_ID', $user_id)->first();
        $languages = CoachLanguage::where('coach_id', $user_id)->pluck('Language');
        $skills = CoachSkill::where('coach_id', $user_id)->pluck('Skill');
        $availability = CoachAvailability::where('User_ID', $user_id)
            ->get()
            ->groupBy('Day_Of_Week')
            ->map(function ($slots) {
                return $slots->map(function ($slot) {
                    return [
                        'start_time' => $slot->Start_Time,
                        'end_time' => $slot->End_Time,
                    ];
                });
            });

        return response()->json([
            'message' => 'Coach profile retrieved successfully',
            'profile' => [
                'User_ID' => $user->User_ID,
                'Full_Name' => $user->full_name,
                'Email' => $user->email,
                'Photo' => $user->photo ? Storage::url($user->photo) : null,
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
                'Photo' => $user->photo ? Storage::url($user->photo) : null,
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
     * Update or set availability for a coach with overlap checking.
     *
     * @param int $userID
     * @param array $availability
     * @return array
     * @throws \Exception
     */
    private function setAvailability(int $userID, array $availability): array
    {
        $savedSlots = [];

        try {
            foreach ($availability['days'] as $day) {
                if (isset($availability['time_slots'][$day])) {
                    foreach ($availability['time_slots'][$day] as $slot) {
                        // Check for overlapping time slots on the same day, excluding the current slot
                        $existingSlots = CoachAvailability::where('User_ID', $userID)
                            ->where('Day_Of_Week', $day)
                            ->where(function ($query) use ($slot) {
                                $query->whereBetween('Start_Time', [$slot['start_time'], $slot['end_time']])
                                    ->orWhereBetween('End_Time', [$slot['start_time'], $slot['end_time']])
                                    ->orWhere(function ($q) use ($slot) {
                                        $q->where('Start_Time', '<', $slot['start_time'])
                                          ->where('End_Time', '>', $slot['end_time']);
                                    });
                            })
                            ->where('Start_Time', '!=', $slot['start_time']) // Exclude the slot being updated
                            ->exists();

                        if ($existingSlots) {
                            throw new \Exception("Time slot overlaps with an existing slot on $day");
                        }

                        // Update or create the slot
                        $availabilityRecord = CoachAvailability::updateOrCreate(
                            [
                                'User_ID' => $userID,
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
}
